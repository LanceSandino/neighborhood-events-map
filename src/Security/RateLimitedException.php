<?php

declare(strict_types=1);

namespace App\Security;

/**
 * @module Security/RateLimitedException
 * @description Thrown when a persistent throttle bucket is exhausted; maps to HTTP 429 + Retry-After.
 */
final class RateLimitedException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter, public readonly string $bucket)
    {
        parent::__construct(sprintf('Too many requests (%s). Try again in %d seconds.', $bucket, $retryAfter));
    }
}
