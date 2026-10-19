<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\RuntimeConfig;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @module Security/ProofOfWork
 * @description Server side of the browser SHA-256 proof-of-work. Issues purpose-bound, client-bound,
 *     short-lived challenges with adaptive difficulty, and verifies/consumes solutions exactly once.
 *
 *     Message (UTF-8 bytes): "<purpose>:<challengeId>:<saltHex>:<nonce>"; nonce = 1-16 ASCII digits.
 *     Valid when SHA-256(message) has at least `difficulty` leading zero bits.
 *
 *     PoW raises the cost of abuse; it is NOT a substitute for rate limits.
 *
 * @input purpose (login|claim|dispute), client hash, challenge id, nonce
 * @output challenge payload; verify() returns void or throws PowException
 * @dependencies Shared/RuntimeConfig, DBAL, clock
 */
final class ProofOfWork
{
    public const PURPOSES = ['login', 'claim', 'dispute'];
    public const MAX_FAILED_ATTEMPTS = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly RuntimeConfig $config,
    ) {
    }

    /** @return array{id:string, purpose:string, salt:string, difficulty:int, expires_at:string, algorithm:string} */
    public function issue(string $purpose, string $clientHash): array
    {
        if (!\in_array($purpose, self::PURPOSES, true)) {
            throw new PowException('wrong_purpose');
        }
        $now = $this->clock->now();
        $difficulty = $this->difficultyFor($clientHash, $now);
        $id = Uuid::v4()->toRfc4122();
        $salt = bin2hex(random_bytes(16));
        $expires = $now->modify(sprintf('+%d seconds', max(30, $this->config->powTtlSeconds)));
        $this->db->insert('pow_challenges', [
            'id' => $id, 'purpose' => $purpose, 'salt' => $salt, 'difficulty' => $difficulty,
            'client_hash' => $clientHash, 'expires_at' => self::ts($expires), 'created_at' => self::ts($now),
        ]);

        return ['id' => $id, 'purpose' => $purpose, 'salt' => $salt, 'difficulty' => $difficulty,
            'expires_at' => $expires->format(\DATE_ATOM), 'algorithm' => 'sha256-leading-zero-bits'];
    }

    /** @throws PowException */
    public function verify(string $purpose, mixed $challengeId, mixed $nonce, string $clientHash): void
    {
        if (!\is_string($challengeId) || !Uuid::isValid($challengeId) || !\is_string($nonce)) {
            throw new PowException('missing');
        }
        if (!preg_match('~^[0-9]{1,16}$~', $nonce)) {
            throw new PowException('malformed');
        }
        $row = $this->db->fetchAssociative('SELECT * FROM pow_challenges WHERE id = ?', [$challengeId]);
        if (false === $row) {
            throw new PowException('invalid');
        }
        $now = $this->clock->now();
        if (null !== $row['consumed_at']) {
            throw new PowException('replayed');
        }
        if ((int) $row['failed_attempts'] >= self::MAX_FAILED_ATTEMPTS) {
            throw new PowException('locked');
        }
        if (new \DateTimeImmutable((string) $row['expires_at']) <= $now) {
            throw new PowException('expired');
        }
        if ($row['purpose'] !== $purpose) {
            $this->recordFailure($challengeId);
            throw new PowException('wrong_purpose');
        }
        if (!hash_equals((string) $row['client_hash'], $clientHash)) {
            $this->recordFailure($challengeId);
            throw new PowException('client_mismatch');
        }
        if (!self::meetsDifficulty(self::digest($purpose, $challengeId, (string) $row['salt'], $nonce), (int) $row['difficulty'])) {
            $this->recordFailure($challengeId);
            throw new PowException('invalid');
        }
        $consumed = $this->db->executeStatement(
            'UPDATE pow_challenges SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL AND expires_at > :now',
            ['now' => self::ts($now), 'id' => $challengeId],
        );
        if (1 !== $consumed) {
            throw new PowException('replayed');
        }
    }

    public static function digest(string $purpose, string $id, string $salt, string $nonce): string
    {
        return hash('sha256', $purpose.':'.$id.':'.$salt.':'.$nonce, true);
    }

    public static function leadingZeroBits(string $binary): int
    {
        $bits = 0;
        foreach (str_split($binary) as $byte) {
            $b = \ord($byte);
            if (0 === $b) {
                $bits += 8;
                continue;
            }
            for ($mask = 0x80; 0 === ($b & $mask); $mask >>= 1) {
                ++$bits;
            }
            break;
        }

        return $bits;
    }

    public static function meetsDifficulty(string $binary, int $difficulty): bool
    {
        return self::leadingZeroBits($binary) >= $difficulty;
    }

    /** Base difficulty, +1 after 10 and +2 after 25 challenges from the same client in 10 minutes. */
    private function difficultyFor(string $clientHash, \DateTimeImmutable $now): int
    {
        $recent = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM pow_challenges WHERE client_hash = ? AND created_at > ?',
            [$clientHash, self::ts($now->modify('-10 minutes'))],
        );
        $bump = $recent >= 25 ? 2 : ($recent >= 10 ? 1 : 0);

        return min(24, $this->config->powDifficultyClamped() + $bump);
    }

    private function recordFailure(string $id): void
    {
        $this->db->executeStatement('UPDATE pow_challenges SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$id]);
    }

    public function purge(): int
    {
        return $this->db->executeStatement('DELETE FROM pow_challenges WHERE expires_at < ?', [self::ts($this->clock->now()->modify('-1 day'))]);
    }

    private static function ts(\DateTimeImmutable $t): string
    {
        return $t->format('Y-m-d H:i:s.uP');
    }
}
