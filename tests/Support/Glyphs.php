<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * @module tests/Support/Glyphs
 * @description What counts as an emoji or a decorative glyph for the "drawn art only" tests (D-20261105-01): emoji with
 *   their joiners and variation selector, arrows, technical symbols, enclosed letters, geometric shapes, dingbats and the
 *   multiplication and minus signs used as close and zoom buttons. Plain typography (dashes, quotes, ellipsis, the middle
 *   dot) is not in it.
 * @dependencies none
 */
final class Glyphs
{
    public const PATTERN = '/[\x{00D7}\x{2190}-\x{21FF}\x{2212}\x{2300}-\x{23FF}\x{2460}-\x{24FF}\x{25A0}-\x{27BF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{3030}\x{303D}\x{3297}\x{3299}\x{FE0F}\x{200D}\x{1F000}-\x{1FAFF}]/u';

    /** @return list<string> the distinct glyphs in a text */
    public static function in(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m);

        return array_values(array_unique($m[0]));
    }
}
