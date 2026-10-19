<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @module Security/MagicLinkTokens
 * @description One-time sign-in link tokens: 256-bit random, only the SHA-256 hash is stored,
 *     purpose-bound, expiring, and consumed atomically exactly once (POST only — see
 *     MagicLinkAuthenticator). Raw tokens never touch logs or the database.
 *
 * @input email, purpose (login|claim), optional claim intent id, lifetime minutes
 * @output raw token (to email) / token row on peek or consume
 */
final class MagicLinkTokens
{
    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
    }

    public function issue(string $email, string $purpose, ?string $intentId, int $minutes): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();
        $this->db->insert('auth_link_tokens', [
            'id' => Uuid::v4()->toRfc4122(),
            'token_hash' => self::hash($raw),
            'email_normalized' => $email,
            'intent_id' => $intentId,
            'purpose' => $purpose,
            'expires_at' => $now->modify(sprintf('+%d minutes', $minutes))->format('Y-m-d H:i:s.uP'),
            'created_at' => $now->format('Y-m-d H:i:s.uP'),
        ]);

        return $raw;
    }

    /**
     * Read-only lookup for the GET confirmation page. Never consumes.
     *
     * @return array{state:string, email:string, intent_id:?string, purpose:string}|null
     */
    public function peek(string $raw): ?array
    {
        if (!self::wellFormed($raw)) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT email_normalized, intent_id, purpose, expires_at, consumed_at FROM auth_link_tokens WHERE token_hash = ?', [self::hash($raw)]);
        if (false === $row) {
            return null;
        }
        $state = null !== $row['consumed_at'] ? 'used' : (new \DateTimeImmutable((string) $row['expires_at']) <= $this->clock->now() ? 'expired' : 'valid');

        return ['state' => $state, 'email' => (string) $row['email_normalized'], 'intent_id' => $row['intent_id'], 'purpose' => (string) $row['purpose']];
    }

    /**
     * Atomically consumes a valid token and retires the address's other outstanding tokens.
     * Returns null when unknown, expired or already used.
     *
     * @return array{email:string, intent_id:?string, purpose:string}|null
     */
    public function consume(string $raw): ?array
    {
        if (!self::wellFormed($raw)) {
            return null;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $row = $this->db->fetchAssociative(
            'UPDATE auth_link_tokens SET consumed_at = :now WHERE token_hash = :h AND consumed_at IS NULL AND expires_at > :now
             RETURNING email_normalized, intent_id, purpose',
            ['now' => $now, 'h' => self::hash($raw)],
        );

        if (false === $row) {
            return null;
        }
        // One working link at a time: using a link retires every other outstanding link for that address.
        $this->db->executeStatement(
            'UPDATE auth_link_tokens SET consumed_at = :now WHERE email_normalized = :e AND consumed_at IS NULL',
            ['now' => $now, 'e' => $row['email_normalized']],
        );

        return ['email' => (string) $row['email_normalized'], 'intent_id' => $row['intent_id'], 'purpose' => (string) $row['purpose']];
    }

    public function purge(): int
    {
        return $this->db->executeStatement('DELETE FROM auth_link_tokens WHERE expires_at < ?', [$this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s.uP')]);
    }

    private static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    private static function wellFormed(string $raw): bool
    {
        return 1 === preg_match('~^[A-Za-z0-9_-]{43}$~', $raw);
    }
}
