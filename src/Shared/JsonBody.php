<?php

declare(strict_types=1);

namespace App\Shared;

use Symfony\Component\HttpFoundation\Request;

/**
 * @module Shared/JsonBody
 * @description Decodes a bounded JSON object request body (max 256 KB unless overridden).
 */
final class JsonBody
{
    /** @return array<string,mixed> */
    public static function decode(Request $request, int $maxBytes = 262_144): array
    {
        $raw = $request->getContent();
        if (\strlen($raw) > $maxBytes) {
            throw new ValidationException(['body' => 'Request is too large.']);
        }
        if ('' === trim($raw)) {
            return [];
        }
        $data = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new ValidationException(['body' => 'Expected a JSON object.']);
        }

        return $data;
    }
}
