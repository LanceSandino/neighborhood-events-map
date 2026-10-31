<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\AppExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

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
        self::assertSame('OCT 31, 2026', $x->eventBadge('2026-10-31', '2026-10-31'), 'a one-day event keeps its badge');
        self::assertSame('DEC 1 – JAN 3', $x->eventBadge('2026-12-01', '2027-01-03'));
        self::assertSame('Dec 1, 2026 – Jan 3, 2027', $x->dateRange('2026-12-01', '2027-01-03'), 'across the year end');
        self::assertSame('Dec 1 – 24, 2026', $x->dateRange('2026-12-01', '2026-12-24'));
        self::assertSame('Nov 28 – Dec 31, 2026', $x->dateRange('2026-11-28', '2026-12-31'));
        self::assertSame('Oct 31, 2026', $x->dateRange('2026-10-31', '2026-10-31'));
        self::assertSame('not-a-date', $x->dateRange('not-a-date'));
        self::assertSame('2026-12-31', (new AppExtension(new MockClock('2027-01-01 04:30:00 UTC')))->localToday('America/Chicago'), 'the app clock, in the community\'s zone');
        self::assertSame('5 PM', $x->time12('17:00'));
        self::assertSame('12:30 PM', $x->time12('12:30'));
        self::assertSame('12 AM', $x->time12('00:00'));
        $json = $x->bootJson(['a' => '</script>']);
        self::assertStringNotContainsString('</script>', $json, 'cannot break out of <script type="application/json">');
        self::assertSame(['a' => '</script>'], json_decode($json, true));
    }

    public function testWalkingAreasForTheZoneFilter(): void
    {
        $x = new AppExtension();
        $zone = static fn (string $k, string $status = 'active', bool $residential = true) => ['key' => 'p'.strtolower($k), 'label' => 'Phase '.$k, 'residential' => $residential, 'status' => $status];
        $zones = [$zone('1'), $zone('2'), $zone('3'), $zone('4'), $zone('6A'), $zone('6B'), $zone('7', 'coming_soon'), $zone('Club', 'active', false)];
        $groups = [['p1', 'p2', 'p3'], ['p4'], ['p6a', 'p6b', 'p7'], ['pclub']];
        self::assertSame([
            ['value' => 'area:0', 'label' => 'Phases 1–3'],
            ['value' => 'area:2', 'label' => 'Phases 6A, 6B'],
        ], $x->zoneAreas($zones, $groups), 'unnamed one-zone groups, coming-soon and non-residential zones are left out');
        self::assertSame(['Main (1–3)', 'Second (4)', 'Third (6A, 6B)'], array_column($x->zoneAreas($zones, $groups, ['Main', 'Second', 'Third']), 'label'));
        self::assertSame([], $x->zoneAreas($zones, []));
    }
}
