<?php

declare(strict_types=1);

namespace App\Community;

/**
 * @module Community/BrandPalette
 * @description Turns the organizer's brand colours into accessible CSS variables: the brand itself
 *     (backgrounds), the ink to put on it (white or near-black, whichever contrasts more) and a brand
 *     text colour darkened (light theme) or lightened (dark theme) until it reaches WCAG AA 4.5:1 on the
 *     page. Any colour an organizer picks therefore stays readable.
 */
final class BrandPalette
{
    public const LIGHT_BG = '#f8f7f4';
    public const DARK_SURFACE = '#1f1d2a';
    private const DARK_INK = '#111018';

    public static function css(string $primary, string $accent): string
    {
        return sprintf(
            ":root{--brand:%s;--brand-accent:%s;--brand-ink:%s;--brand-text:%s}\n@media (prefers-color-scheme: dark){:root{--brand-text:%s}}\n",
            $primary, $accent, self::ink($primary), self::textOn($primary, self::LIGHT_BG), self::textOn($primary, self::DARK_SURFACE),
        );
    }

    /** White or near-black text for a solid background of $bg, whichever has the higher contrast. */
    public static function ink(string $bg): string
    {
        return self::contrast('#ffffff', $bg) >= self::contrast(self::DARK_INK, $bg) ? '#ffffff' : self::DARK_INK;
    }

    /** $color moved towards black (light background) or white (dark background) until it reaches $target. */
    public static function textOn(string $color, string $bg, float $target = 4.5): string
    {
        $towards = self::luminance($bg) > 0.5 ? 0 : 255;
        [$r, $g, $b] = self::rgb($color);
        for ($t = 0.0; $t <= 1.0001; $t += 0.04) {
            $mixed = sprintf('#%02x%02x%02x', (int) round($r + ($towards - $r) * $t), (int) round($g + ($towards - $g) * $t), (int) round($b + ($towards - $b) * $t));
            if (self::contrast($mixed, $bg) >= $target) {
                return $mixed;
            }
        }

        return 0 === $towards ? '#000000' : '#ffffff';
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $lin = static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
    }
}
