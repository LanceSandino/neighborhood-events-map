<?php

declare(strict_types=1);

namespace App\Security;

/**
 * @module Security/PowException
 * @description Proof-of-work rejection with a machine-readable code (invalid, expired, replayed,
 *     wrong_purpose, client_mismatch, locked, malformed, missing).
 */
final class PowException extends \RuntimeException
{
    public function __construct(public readonly string $code_)
    {
        parent::__construct(match ($code_) {
            'expired' => 'The anti-spam check expired. Please try again.',
            'replayed' => 'That anti-spam check was already used. Please try again.',
            'locked' => 'Too many failed anti-spam attempts. Please start again.',
            'missing', 'malformed' => 'The anti-spam check is missing or malformed.',
            default => 'The anti-spam check failed. Please try again.',
        });
    }
}
