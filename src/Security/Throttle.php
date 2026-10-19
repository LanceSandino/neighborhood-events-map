<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\RuntimeConfig;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Security/Throttle
 * @description Persistent sliding-window rate limiter in PostgreSQL (survives restarts, shared by
 *     all PHP workers). A transaction-scoped advisory lock per bucket/key prevents concurrent
 *     requests from slipping past the limit. Keys are HMAC-hashed.
 *
 * @input bucket name (configured in RuntimeConfig) and a raw key (IP, email, address id, "global")
 * @output void, or RateLimitedException with Retry-After seconds
 * @dependencies Shared/RuntimeConfig, Security/ClientIdentity
 */
final class Throttle
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly RuntimeConfig $config,
        private readonly ClientIdentity $identity,
    ) {
    }

    /** @throws RateLimitedException */
    public function hit(string $bucket, string $key): void
    {
        [$limit, $window] = $this->config->limit($bucket);
        $keyHash = $this->identity->hash('throttle:'.$bucket, $key);
        $now = $this->clock->now();
        $since = $now->modify(sprintf('-%d seconds', $window));

        $this->db->transactional(function (Connection $db) use ($bucket, $keyHash, $limit, $window, $now, $since): void {
            $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))', ['k' => $bucket.'|'.$keyHash]);
            $row = $db->fetchAssociative(
                'SELECT COUNT(*) AS n, MIN(occurred_at) AS oldest FROM throttle_events WHERE bucket = :b AND key_hash = :k AND occurred_at > :since',
                ['b' => $bucket, 'k' => $keyHash, 'since' => self::ts($since)],
            );
            if ((int) $row['n'] >= $limit) {
                $oldest = new \DateTimeImmutable((string) $row['oldest']);
                $retry = max(1, $oldest->getTimestamp() + $window - $now->getTimestamp());
                throw new RateLimitedException($retry, $bucket);
            }
            $db->insert('throttle_events', ['bucket' => $bucket, 'key_hash' => $keyHash, 'occurred_at' => self::ts($now)]);
        });
    }

    public function purge(int $olderThanSeconds = 172_800): int
    {
        return $this->db->executeStatement('DELETE FROM throttle_events WHERE occurred_at < :t', ['t' => self::ts($this->clock->now()->modify("-{$olderThanSeconds} seconds"))]);
    }

    private static function ts(\DateTimeImmutable $t): string
    {
        return $t->format('Y-m-d H:i:s.uP');
    }
}
