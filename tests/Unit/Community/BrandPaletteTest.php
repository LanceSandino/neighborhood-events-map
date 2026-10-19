<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\BrandPalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Traceability: accessibility (WCAG 2.1 AA 1.4.3) — whatever brand colours an organizer picks, text
 * in the brand colour and text on brand-coloured buttons keep at least 4.5:1 contrast in both themes.
 */
#[CoversClass(BrandPalette::class)]
final class BrandPaletteTest extends TestCase
{
    /** @return iterable<array{0:string}> */
    public static function brands(): iterable
    {
        foreach (['#f07936', '#2e7d6b', '#ffd400', '#524077', '#000000', '#ffffff', '#ff69b4'] as $c) {
            yield $c => [$c];
        }
    }

    #[DataProvider('brands')]
    public function testAnyBrandColourStaysReadable(string $brand): void
    {
        self::assertGreaterThanOrEqual(4.5, BrandPalette::contrast(BrandPalette::textOn($brand, BrandPalette::LIGHT_BG), BrandPalette::LIGHT_BG));
        self::assertGreaterThanOrEqual(4.5, BrandPalette::contrast(BrandPalette::textOn($brand, BrandPalette::DARK_SURFACE), BrandPalette::DARK_SURFACE));
        self::assertGreaterThanOrEqual(4.5, BrandPalette::contrast(BrandPalette::ink($brand), $brand), 'button text on the brand colour');
    }

    public function testKnownValues(): void
    {
        self::assertEqualsWithDelta(21.0, BrandPalette::contrast('#000000', '#ffffff'), 0.01);
        self::assertSame('#111018', BrandPalette::ink('#ffd400'), 'dark ink on yellow');
        self::assertSame('#ffffff', BrandPalette::ink('#2e7d6b'), 'white ink on green');
        self::assertSame('#111018', BrandPalette::ink('#f07936'), 'orange is too light for white text');
        self::assertSame('#2e7d6b', BrandPalette::textOn('#2e7d6b', BrandPalette::LIGHT_BG), 'already readable colours are kept');
        $css = BrandPalette::css('#f07936', '#524077');
        self::assertStringContainsString('--brand:#f07936', $css);
        self::assertStringContainsString('prefers-color-scheme: dark', $css);
    }
}
