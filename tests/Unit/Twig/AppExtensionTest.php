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
        self::assertSame(['6–10 PM', '6:30–9 PM', '11 AM–1 PM'], [$x->timeSpan('18:00', '22:00'), $x->timeSpan('18:30', '21:00'), $x->timeSpan('11:00', '13:00')]);
        self::assertSame('12:30 PM', $x->time12('12:30'));
        self::assertSame('12 AM', $x->time12('00:00'));
        $json = $x->bootJson(['a' => '</script>']);
        self::assertStringNotContainsString('</script>', $json, 'cannot break out of <script type="application/json">');
        self::assertSame(['a' => '</script>'], json_decode($json, true));
    }

    public function testSaleDaysAsWordsAndTheSeasonOfADate(): void
    {
        $x = new AppExtension();
        self::assertSame('Saturday, April 10, 2027', $x->dayList(['2027-04-10']));
        self::assertSame('Sat–Sun, April 10–11, 2027', $x->dayList(['2027-04-10', '2027-04-11']), 'a weekend');
        self::assertSame('Fri–Sun, April 9–11, 2027', $x->dayList(['2027-04-09', '2027-04-10', '2027-04-11']));
        self::assertSame('Fri–Sun, April 30 – May 2, 2027', $x->dayList(['2027-04-30', '2027-05-01', '2027-05-02']), 'across a month end');
        self::assertSame('Thu–Fri, December 31, 2026 – January 1, 2027', $x->dayList(['2026-12-31', '2027-01-01']), 'across the year end');
        self::assertSame('Sat, April 10 and Sat, April 17, 2027', $x->dayList(['2027-04-10', '2027-04-17']), 'two Saturdays');
        self::assertSame('Sat, April 10, Sun, April 11 and Sat, April 17, 2027', $x->dayList(['2027-04-10', '2027-04-11', '2027-04-17']));
        self::assertSame('', $x->dayList([]));
        self::assertSame(['Saturday', 'Sunday'], $x->dayNames(['2027-04-10', '2027-04-11']));
        self::assertSame(['Saturday, Apr 10', 'Saturday, Apr 17'], $x->dayNames(['2027-04-10', '2027-04-17']), 'the same weekday twice names the dates');
        self::assertSame(['spring', 'summer', 'fall', 'winter', 'winter'], array_map(static fn (string $d) => $x->seasonOf($d, 40.0), ['2027-04-10', '2027-07-04', '2027-10-02', '2027-12-11', '2027-02-06']));
        self::assertSame(['fall', 'spring', 'spring'], [$x->seasonOf('2027-04-10', -33.9), $x->seasonOf('2027-10-02', -33.9), $x->seasonOf('2027-04-10', null)], 'the southern half the other way round');
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
