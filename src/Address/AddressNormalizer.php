<?php

declare(strict_types=1);

namespace App\Address;

/**
 * @module Address/AddressNormalizer
 * @description Canonicalizes US-style street addresses (house number, directionals, suffixes,
 *     unit, whitespace/punctuation) into a stable uniqueness key plus a tidy display form. Addresses
 *     never need special characters: input with anything but ASCII letters, digits, spaces and
 *     . , ' - # is refused (never silently stripped), so markup, look-alike letters from other
 *     scripts and invisible characters cannot reach the registry.
 *
 * @input Free-text address or (house number, street, unit) parts
 * @output array{house_number:string, street:string, unit:?string, normalized:string, display:string}
 * @dependencies none
 */
final class AddressNormalizer
{
    private const SUFFIXES = [
        'STREET' => 'ST', 'STR' => 'ST', 'AVENUE' => 'AVE', 'AV' => 'AVE', 'DRIVE' => 'DR', 'PLACE' => 'PL',
        'COURT' => 'CT', 'LANE' => 'LN', 'ROAD' => 'RD', 'BOULEVARD' => 'BLVD', 'PARKWAY' => 'PKWY',
        'CIRCLE' => 'CIR', 'TERRACE' => 'TER', 'TRAIL' => 'TRL', 'COVE' => 'CV', 'HIGHWAY' => 'HWY',
        'POINT' => 'PT', 'SQUARE' => 'SQ', 'CROSSING' => 'XING', 'PASS' => 'PASS', 'RUN' => 'RUN',
        'LOOP' => 'LOOP', 'WAY' => 'WAY', 'ROW' => 'ROW', 'BEND' => 'BND', 'GLEN' => 'GLN', 'HOLLOW' => 'HOLW',
        'LANDING' => 'LNDG', 'MEADOW' => 'MDW', 'MEADOWS' => 'MDWS', 'RIDGE' => 'RDG', 'VIEW' => 'VW',
    ];
    private const DIRECTIONS = [
        'NORTH' => 'N', 'SOUTH' => 'S', 'EAST' => 'E', 'WEST' => 'W',
        'NORTHEAST' => 'NE', 'NORTHWEST' => 'NW', 'SOUTHEAST' => 'SE', 'SOUTHWEST' => 'SW',
    ];
    private const UNIT_WORDS = ['APT', 'APARTMENT', 'UNIT', 'STE', 'SUITE', 'LOT', '#'];
    /** Everything an address may contain as typed or imported (\z: no trailing newline either). */
    private const PLAIN = "~^[A-Za-z0-9 .,'#-]*\z~";
    public const PLAIN_HINT = "Use only letters, numbers, spaces and . , ' - # in an address.";

    /** True when $s uses only the characters an address may contain. */
    public static function isPlain(string $s): bool
    {
        return 1 === preg_match(self::PLAIN, $s);
    }

    /**
     * @return array{house_number:string, street:string, unit:?string, normalized:string, display:string}
     */
    public function parse(string $address): array
    {
        self::assertPlain($address);
        $clean = $this->clean($address);
        if (!preg_match('~^(\d{1,6}[A-Z]?)\s+(.+)$~', $clean, $m)) {
            throw new InvalidAddressException('Start the address with the house number, e.g. "123 Pumpkin Ln".');
        }
        $unit = null;
        $rest = $m[2];
        // "Apt 4", "Apt #4" and "#4" all name unit 4.
        $words = implode('|', array_map(fn ($w) => preg_quote($w, '~'), array_diff(self::UNIT_WORDS, ['#'])));
        if (preg_match('~^(.*?)\s+(?:(?:'.$words.')(?:\s*#)?|#)\s*([A-Z0-9-]{1,8})$~', $rest, $u)) {
            $rest = $u[1];
            $unit = $u[2];
        }

        return $this->fromParts($m[1], $rest, $unit);
    }

    /**
     * @return array{house_number:string, street:string, unit:?string, normalized:string, display:string}
     */
    public function fromParts(string $houseNumber, string $street, ?string $unit = null): array
    {
        self::assertPlain($houseNumber, $street, $unit ?? '');
        $house = $this->clean($houseNumber);
        if (!preg_match('~^\d{1,6}[A-Z]?$~', $house)) {
            throw new InvalidAddressException('House number must be digits (optionally followed by one letter).');
        }
        $street = $this->normalizeStreet($street);
        if ('' === $street || \strlen($street) > 80 || !preg_match('~[A-Z]~', $street)) {
            throw new InvalidAddressException('Enter a street name.');
        }
        $unit = null !== $unit ? $this->clean($unit) : null;
        if ('' === $unit) {
            $unit = null;
        }
        if (null !== $unit && !preg_match('~^[A-Z0-9-]{1,8}$~', $unit)) {
            throw new InvalidAddressException('Unit must be up to 8 letters or digits.');
        }
        $normalized = $house.' '.$street.(null !== $unit ? ' UNIT '.$unit : '');

        return [
            'house_number' => $house,
            'street' => $street,
            'unit' => $unit,
            'normalized' => $normalized,
            'display' => $house.' '.$this->displayStreet($street).(null !== $unit ? ' Unit '.$unit : ''),
        ];
    }

    public function normalizeStreet(string $street): string
    {
        $words = preg_split('~\s+~', $this->clean($street), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $last = \count($words) - 1;
        foreach ($words as $i => $w) {
            if (isset(self::DIRECTIONS[$w]) && (0 === $i || $i === $last)) {
                $words[$i] = self::DIRECTIONS[$w];
            } elseif ($i === $last && isset(self::SUFFIXES[$w])) {
                $words[$i] = self::SUFFIXES[$w];
            } elseif ($i === $last - 1 && isset(self::SUFFIXES[$w]) && isset(self::DIRECTIONS[$words[$last]] ) ) {
                $words[$i] = self::SUFFIXES[$w];
            }
        }

        return implode(' ', $words);
    }

    private static function assertPlain(string ...$parts): void
    {
        foreach ($parts as $part) {
            if (!self::isPlain($part)) {
                throw new InvalidAddressException(self::PLAIN_HINT);
            }
        }
    }

    private function clean(string $s): string
    {
        $s = strtoupper(trim($s));
        $s = preg_replace('~[\x00-\x1F\x7F]~u', '', $s) ?? '';
        $s = str_replace(['.', ','], ' ', $s);
        $s = preg_replace('~\s*#\s*~', ' # ', $s) ?? $s;
        $s = preg_replace('~[^A-Z0-9#\- ]~', '', $s) ?? '';

        return trim(preg_replace('~\s+~', ' ', $s) ?? '');
    }

    private function displayStreet(string $street): string
    {
        return implode(' ', array_map(
            static fn (string $w) => \in_array($w, ['N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW'], true) ? $w : ucfirst(strtolower($w)),
            explode(' ', $street),
        ));
    }
}
