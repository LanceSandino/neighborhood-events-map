<?php

declare(strict_types=1);

namespace App\Shared;

/**
 * @module Shared/RuntimeConfig
 * @description Typed access to infrastructure settings that come from environment variables
 *     (base URL, mail, PoW, throttles, routing). Community/event data lives in CommunityConfig.
 *
 * @input Raw environment strings injected via services.yaml
 * @output Validated scalar settings and rate-limit tuples
 */
final class RuntimeConfig
{
    /** @var array<string, array{0:int,1:int}> */
    private array $limits = [];

    /**
     * @param array<string, string> $rateLimits bucket => "count/seconds"
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $mailFrom,
        public readonly string $adminEmail,
        public readonly string $appSecret,
        private readonly string $sessionIdleMinutesEnv,
        private readonly string $magicLinkMinutesEnv,
        public readonly int $powDifficulty,
        public readonly int $powTtlSeconds,
        array $rateLimits,
        public readonly string $routingProvider,
        public readonly string $routingApiKey,
        public readonly string $routingBaseUrl,
        public readonly bool $demoMode,
    ) {
        foreach ($rateLimits as $bucket => $spec) {
            $this->limits[$bucket] = self::parseLimit($spec, $bucket);
        }
    }

    /** @return array{0:int,1:int} [count, seconds] */
    public static function parseLimit(string $spec, string $bucket = 'limit'): array
    {
        if (!preg_match('~^\s*(\d{1,6})\s*/\s*(\d{1,7})\s*$~', $spec, $m) || (int) $m[1] < 1 || (int) $m[2] < 1) {
            throw new \InvalidArgumentException(sprintf('Invalid rate limit "%s" for %s; expected "count/seconds".', $spec, $bucket));
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /** @return array{0:int,1:int} */
    public function limit(string $bucket): array
    {
        return $this->limits[$bucket] ?? throw new \OutOfBoundsException('Unknown rate-limit bucket '.$bucket);
    }

    /** Env override wins over the community YAML value; result clamped to 5..240 minutes. */
    public function sessionIdleMinutes(?int $communityValue): int
    {
        $value = '' !== trim($this->sessionIdleMinutesEnv) ? (int) $this->sessionIdleMinutesEnv : ($communityValue ?? 30);

        return max(5, min(240, $value));
    }

    /** Env override wins over YAML; clamped to 5..60 minutes (default 15). */
    public function magicLinkMinutes(?int $communityValue): int
    {
        $value = '' !== trim($this->magicLinkMinutesEnv) ? (int) $this->magicLinkMinutesEnv : ($communityValue ?? 15);

        return max(5, min(60, $value));
    }

    public function powDifficultyClamped(): int
    {
        return max(4, min(24, $this->powDifficulty));
    }
}
