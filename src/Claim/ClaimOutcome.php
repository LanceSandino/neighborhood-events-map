<?php

declare(strict_types=1);

namespace App\Claim;

/**
 * @module Claim/ClaimOutcome
 * @description Result of an atomic claim attempt: published | already_yours | already_claimed | limit | closed | rejected.
 */
final class ClaimOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $addressId,
        public readonly ?string $address,
        public readonly ?string $message = null,
    ) {
    }

    public function published(): bool
    {
        return 'published' === $this->status;
    }
}
