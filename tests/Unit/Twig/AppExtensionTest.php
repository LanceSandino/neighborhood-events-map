<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\AppExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppExtension::class)]
final class AppExtensionTest extends TestCase
{
    public function testZoneSummary(): void
    {
        $x = new AppExtension();
        $phases = array_map(fn ($l) => ['label' => $l, 'residential' => true], ['Phase 1', 'Phase 2', 'Phase 3', 'Phase 5A', 'Phase 5B', 'Phase 8']);
        $phases[] = ['label' => 'Clubhouse', 'residential' => false];
        self::assertSame(['title' => '6 phases', 'sub' => '1–3, 5A, 5B, 8'], $x->zoneSummary($phases));
        self::assertSame(['title' => '2 zones', 'sub' => 'Lakefront, East'], $x->zoneSummary([['label' => 'Lakefront', 'residential' => true], ['label' => 'East', 'residential' => true]]));
        self::assertNull($x->zoneSummary([]));
    }

    public function testFormatting(): void
    {
        $x = new AppExtension();
        self::assertSame('OCT 31, 2026', $x->eventBadge('2026-10-31'));
        self::assertSame('5 PM', $x->time12('17:00'));
        self::assertSame('12:30 PM', $x->time12('12:30'));
        self::assertSame('12 AM', $x->time12('00:00'));
        $json = $x->bootJson(['a' => '</script>']);
        self::assertStringNotContainsString('</script>', $json, 'cannot break out of <script type="application/json">');
        self::assertSame(['a' => '</script>'], json_decode($json, true));
    }
}
