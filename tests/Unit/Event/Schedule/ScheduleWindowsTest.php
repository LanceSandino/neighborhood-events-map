<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event\Schedule;

use App\Event\EventOccurrence;
use App\Event\Schedule\ScheduleWindows;
use App\Shared\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/Schedule/ScheduleWindowsTest
 * @description The schedule core for every kind, although only Halloween (single_day) is enabled: windows inside
 *   the occurrence, no duplicate dates, at most the kind's days (1/62/3) and the organizer's cap, end after start
 *   on the same day, hours inside the bounds; sorted output; the "when" window of a listing.
 * @dependencies Event/Schedule/ScheduleWindows, Event/EventOccurrence
 */
final class ScheduleWindowsTest extends TestCase
{
    private static function event(string $from, string $to): EventOccurrence
    {
        return new EventOccurrence(7, 'halloween', 'Test', $from, $to, '17:00', '21:00', [], 'active');
    }

    /** @param list<mixed> $windows */
    private static function errors(string $kind, EventOccurrence $e, mixed $windows, array $bounds = ScheduleWindows::DAY, ?int $cap = null): array
    {
        try {
            ScheduleWindows::normalize($kind, $e, $windows, $bounds, $cap);
        } catch (ValidationException $x) {
            return $x->fields;
        }
        self::fail('expected a validation error');
    }

    public function testASingleDayIsExactlyTheEventDayWithTheHomesOwnHours(): void
    {
        $halloween = self::event('2026-10-31', '2026-10-31');
        self::assertSame([['date' => '2026-10-31', 'start' => '17:30', 'end' => '20:30']], ScheduleWindows::singleDay($halloween, '17:30', '20:30'));
        self::assertSame(['windows.0' => 'Choose a day from 2026-10-31 to 2026-10-31.'], self::errors('single_day', $halloween, [['date' => '2026-11-01', 'start' => '17:00', 'end' => '18:00']]));
        self::assertSame(['windows' => 'This event has one day.'], self::errors('single_day', $halloween, [['date' => '2026-10-31', 'start' => '17:00', 'end' => '18:00'], ['date' => '2026-10-31', 'start' => '18:00', 'end' => '19:00']]));
        self::assertSame(['windows.0' => 'This event is on 2026-10-30.'], self::errors('single_day', self::event('2026-10-30', '2026-10-31'), [['date' => '2026-10-31', 'start' => '17:00', 'end' => '18:00']]));
    }

    public function testNightlyRangesAcrossTheYearEndAreSortedAndChecked(): void
    {
        $lights = self::event('2026-12-01', '2027-01-03');
        $out = ScheduleWindows::normalize('nightly_range', $lights, [
            ['date' => '2027-01-01', 'start' => '18:00', 'end' => '22:00'],
            ['date' => '2026-12-31', 'start' => '17:30', 'end' => '23:30'],
            ['date' => '2026-12-01', 'start' => '18:00:00', 'end' => '21:00'],
        ]);
        self::assertSame(['2026-12-01', '2026-12-31', '2027-01-01'], array_column($out, 'date'));
        self::assertSame('18:00', $out[0]['start'], 'seconds are dropped');
        self::assertSame(['windows.1' => 'That day is already chosen.'], self::errors('nightly_range', $lights, [['date' => '2026-12-31', 'start' => '18:00', 'end' => '22:00'], ['date' => '2026-12-31', 'start' => '19:00', 'end' => '22:00']]));
        self::assertSame(['windows.0' => 'Choose a day from 2026-12-01 to 2027-01-03.'], self::errors('nightly_range', $lights, [['date' => '2027-01-04', 'start' => '18:00', 'end' => '22:00']]));
        self::assertSame(['windows.0' => 'End time must be later than the start time.'], self::errors('nightly_range', $lights, [['date' => '2026-12-24', 'start' => '22:00', 'end' => '01:00']]), 'no overnight windows');
        self::assertSame(['windows.0' => 'End time must be later than the start time.'], self::errors('nightly_range', $lights, [['date' => '2026-12-24', 'start' => '20:00', 'end' => '20:00']]));
        self::assertSame(['windows.0' => 'Choose hours between 16:00 and 23:00.'], self::errors('nightly_range', $lights, [['date' => '2026-12-24', 'start' => '15:30', 'end' => '20:00']], ['16:00', '23:00']));
        self::assertSame(['windows.0' => 'Use times like 18:00.'], self::errors('nightly_range', $lights, [['date' => '2026-12-24', 'start' => '6pm', 'end' => '20:00']]));
        self::assertSame(['windows.0' => 'Use a date like 2026-12-18.'], self::errors('nightly_range', $lights, [['date' => '2026-02-30', 'start' => '18:00', 'end' => '20:00']]));
        self::assertSame(['windows' => 'Choose at most 5 days.'], self::errors('nightly_range', $lights, array_map(static fn (int $d) => ['date' => sprintf('2026-12-%02d', $d), 'start' => '18:00', 'end' => '20:00'], range(1, 6)), ScheduleWindows::DAY, 5), 'the organizer cap');
    }

    public function testAtMostTheKindsDays(): void
    {
        $range = self::event('2026-01-01', '2026-12-31');
        $days = static fn (int $n) => array_map(static fn (int $i) => ['date' => (new \DateTimeImmutable('2026-01-01'))->modify("+$i days")->format('Y-m-d'), 'start' => '08:00', 'end' => '12:00'], range(0, $n - 1));
        self::assertCount(62, ScheduleWindows::normalize('nightly_range', $range, $days(62)));
        self::assertSame(['windows' => 'Choose at most 62 days.'], self::errors('nightly_range', $range, $days(63)));
        self::assertCount(3, ScheduleWindows::normalize('sale_days', $range, $days(3)));
        self::assertSame(['windows' => 'Choose at most 3 days.'], self::errors('sale_days', $range, $days(4)));
        self::assertSame(['windows' => 'Choose at least one day.'], self::errors('sale_days', $range, []));
        self::assertSame(['windows' => 'Choose at least one day.'], self::errors('sale_days', $range, ['date' => '2026-05-01']));
        self::assertSame(['windows.0' => 'Use a date like 2026-12-18.'], self::errors('sale_days', $range, ['not a window']));
        $this->expectException(\InvalidArgumentException::class);
        ScheduleWindows::normalize('weekly', $range, $days(1));
    }

    public function testTheWindowThatAnswersWhen(): void
    {
        $w = [['date' => '2026-12-18', 'start' => '18:00', 'end' => '22:00'], ['date' => '2026-12-20', 'start' => '17:00', 'end' => '21:00']];
        self::assertSame('2026-12-18', ScheduleWindows::currentOrNext($w, '2026-12-01')['date'], 'the next one');
        self::assertSame('2026-12-18', ScheduleWindows::currentOrNext($w, '2026-12-18')['date'], 'today');
        self::assertSame('2026-12-20', ScheduleWindows::currentOrNext($w, '2026-12-19')['date'], 'the next one');
        self::assertSame('2026-12-20', ScheduleWindows::currentOrNext($w, '2027-01-05')['date'], 'the last one when all are over');
        self::assertNull(ScheduleWindows::currentOrNext([], '2026-12-01'));
    }
}
