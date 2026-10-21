<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\RuntimeConfig;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Deletes events once they are older than their own bucket's window: nothing older can count any more,
     * so a flood is stored no longer than its limit needs (minutes for pow_ip, not days). Events of buckets
     * that are no longer configured go after the longest window.
     */
    public function purge(): int
    {
        $now = $this->clock->now();
        $windows = $this->config->windows();
        $deleted = 0;
        foreach ($windows as $bucket => $seconds) {
            $deleted += $this->db->executeStatement('DELETE FROM throttle_events WHERE bucket = :b AND occurred_at < :t',
                ['b' => $bucket, 't' => self::ts($now->modify("-{$seconds} seconds"))]);
        }
        $longest = [] === $windows ? 172_800 : max($windows);

        return $deleted + $this->db->executeStatement('DELETE FROM throttle_events WHERE bucket NOT IN (:buckets) AND occurred_at < :t',
            ['buckets' => array_keys($windows), 't' => self::ts($now->modify("-{$longest} seconds"))], ['buckets' => ArrayParameterType::STRING]);
    }

    private static function ts(\DateTimeImmutable $t): string
    {
        return $t->format('Y-m-d H:i:s.uP');
    }
}
