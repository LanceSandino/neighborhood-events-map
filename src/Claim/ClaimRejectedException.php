<?php

declare(strict_types=1);

namespace App\Claim;

/**
 * @module Claim/ClaimRejectedException
 * @description A claim cannot proceed. `reason` is machine-readable; the message is user-safe and
 *     never reveals another owner's identity.
 */
final class ClaimRejectedException extends \RuntimeException
{
    public const STATUS = [
        'already_claimed' => 409, 'closed' => 403, 'not_eligible' => 422, 'outside_boundary' => 422,
        'zone_not_claimable' => 422, 'zone_mismatch' => 422, 'ambiguous_zone' => 422, 'street_not_allowed' => 422,
        'pin_collision' => 422, 'self_entry_disabled' => 422, 'unknown_zone' => 422, 'invalid_address' => 422,
    ];

    public function __construct(public readonly string $reason, string $message, public readonly ?int $addressId = null)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return self::STATUS[$this->reason] ?? 422;
    }
}
