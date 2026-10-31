<?php

declare(strict_types=1);

namespace App\Listing;

use App\Event\Type\HalloweenType;

/**
 * @module Listing/LegacyListingColumns
 * @description The compatibility bridge to the legacy Halloween columns of `listings` (candy … stroller_friendly).
 *     Since 0.10 the listing's details live in the `details` JSONB column; every write also fills these columns
 *     with the same values, so the previous release still reads correct data after an image-only rollback
 *     (D-20261031-03). The columns are Halloween's: a listing of another event type cannot be stored here.
 *
 * @dependencies Event/Type/HalloweenType
 */
final class LegacyListingColumns
{
    /** The legacy columns, in table order. */
    public const COLUMNS = ['candy', 'nonfood', 'adult_drinks', 'low_noise', 'jump_scares', 'flashing_lights', 'loud_audio', 'scare_level', 'stroller_friendly'];
    public const COLUMN_LIST = 'candy, nonfood, adult_drinks, low_noise, jump_scares, flashing_lights, loud_audio, scare_level, stroller_friendly';
    public const PARAMETER_LIST = ':candy, :nonfood, :adult_drinks, :low_noise, :jump_scares, :flashing_lights, :loud_audio, :scare_level, :stroller_friendly';

    /** "candy = EXCLUDED.candy, …" for an upsert. */
    public static function upsertAssignments(): string
    {
        return implode(', ', array_map(static fn (string $c) => "$c = EXCLUDED.$c", self::COLUMNS));
    }

    /** "candy = :candy, …" for an update. */
    public static function updateAssignments(): string
    {
        return implode(', ', array_map(static fn (string $c) => "$c = :$c", self::COLUMNS));
    }

    /**
     * Column values from Halloween details (named like the columns).
     *
     * @param array<string,mixed> $details
     *
     * @return array<string,bool|string>
     */
    public static function bindings(array $details): array
    {
        $out = [];
        foreach (self::COLUMNS as $c) {
            if (!\array_key_exists($c, $details)) {
                throw new \LogicException(sprintf('The listings table keeps the Halloween columns; details without "%s" cannot be stored there.', $c));
            }
            $out[$c] = 'scare_level' === $c ? (string) $details[$c] : (bool) $details[$c];
        }

        return $out;
    }

    /** @return array<string,string> DBAL types of the boolean columns */
    public static function bindingTypes(): array
    {
        return array_fill_keys(HalloweenType::BOOLEANS, 'boolean');
    }
}
