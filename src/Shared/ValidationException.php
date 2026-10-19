<?php

declare(strict_types=1);

namespace App\Shared;

/**
 * @module Shared/ValidationException
 * @description Field-level validation failure (HTTP 400/422 with per-field messages).
 */
final class ValidationException extends \InvalidArgumentException
{
    /** @param array<string,string> $fields */
    public function __construct(public readonly array $fields, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message);
    }
}
