<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\ClosingMessage;
use App\Event\EventDates;
use App\Event\EventOccurrence;
use App\Event\Type\GarageSaleType;
use App\Event\Type\HalloweenType;
use App\Event\Type\HolidayLightsType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/NextEventTest
 * @description The dates of events to come (D-20261031-39): a new event's dates in the organizer console per type
 *   (Halloween October 31, Christmas lights November 1 to January 1 — crossing the year —, a sale's dates to be
 *   announced), next year's tentative dates when an event closes (October 31 next year; the lights' own first and last
 *   night one year later, February 29 included; a sale to be announced), the dates in words and the thank-you text
 *   (the organizer's, or the type's default with the event's year and next year's dates).
 * @dependencies Event/Type/*, Event/EventDates, Event/ClosingMessage
 */
final class NextEventTest extends TestCase
{
    /** @return iterable<string, array{0:string, 1:string, 2:array{0:?string,1:?string}}> */
    public static function newEvents(): iterable
    {
        yield 'Halloween before the day' => ['halloween', '2026-10-01', ['2026-10-31', '2026-10-31']];
        yield 'Halloween on the day' => ['halloween', '2026-10-31', ['2026-10-31', '2026-10-31']];
        yield 'Halloween after the day' => ['halloween', '2026-11-01', ['2027-10-31', '2027-10-31']];
        yield 'lights in the autumn' => ['holiday_lights', '2026-10-31', ['2026-11-01', '2027-01-01']];
        yield 'lights during the season' => ['holiday_lights', '2026-12-15', ['2026-11-01', '2027-01-01']];
        yield 'lights on New Year\'s Day (still that season)' => ['holiday_lights', '2027-01-01', ['2026-11-01', '2027-01-01']];
        yield 'lights in the spring' => ['holiday_lights', '2027-03-01', ['2027-11-01', '2028-01-01']];
        yield 'a sale' => ['garage_sale', '2026-10-31', [null, null]];
    }

    /** @param array{0:?string,1:?string} $expected */
    #[DataProvider('newEvents')]
    public function testANewEventStartsOnItsTypesUsualDates(string $type, string $today, array $expected): void
    {
        self::assertSame($expected, self::type($type)->newEventDates($today));
    }

    /** @return iterable<string, array{0:string, 1:?string, 2:?string, 3:array{0:?string,1:?string}}> */
    public static function closings(): iterable
    {
        yield 'Halloween on Oct 31' => ['halloween', '2026-10-31', '2026-10-31', ['2027-10-31', '2027-10-31']];
        yield 'Halloween on another day still comes back on Oct 31' => ['halloween', '2026-10-30', '2026-10-30', ['2027-10-31', '2027-10-31']];
        yield 'lights Nov 1 to Jan 1 (crossing the year)' => ['holiday_lights', '2026-11-01', '2027-01-01', ['2027-11-01', '2028-01-01']];
        yield 'lights with their own dates' => ['holiday_lights', '2026-12-01', '2027-01-03', ['2027-12-01', '2028-01-03']];
        yield 'lights ending on Feb 29' => ['holiday_lights', '2027-12-31', '2028-02-29', ['2028-12-31', '2029-02-28']];
        yield 'a sale' => ['garage_sale', '2027-04-10', '2027-04-11', [null, null]];
    }

    /** @param array{0:?string,1:?string} $expected */
    #[DataProvider('closings')]
    public function testClosingGivesNextYearsTentativeDates(string $type, ?string $from, ?string $to, array $expected): void
    {
        self::assertSame($expected, self::type($type)->nextDates(self::event($type, 'Name', $from, $to)));
    }

    public function testDatesInWords(): void
    {
        self::assertSame('Oct 31, 2027, tentative', EventDates::words(self::event('halloween', 'Halloween', '2027-10-31', '2027-10-31', true)));
        self::assertSame('Nov 1, 2027 – Jan 1, 2028', EventDates::words(self::event('holiday_lights', 'Lights', '2027-11-01', '2028-01-01')));
        self::assertSame('dates to be announced', EventDates::words(self::event('garage_sale', 'Sale', null, null)));
        self::assertSame('dates to be announced', EventDates::words(null));
    }

    public function testTheThankYouTextIsTheOrganizersOrTheTypesDefaultWithTheYearAndNextYearsDates(): void
    {
        $closed = self::event('halloween', 'Halloween', '2026-10-31', '2026-10-31');
        $next = self::event('halloween', 'Halloween', '2027-10-31', '2027-10-31', true);
        self::assertSame('Halloween 2026 was a success — thank you! Come back next year (Oct 31, 2027, tentative). Residents: sign in to get your house ready for next year.',
            ClosingMessage::text($closed, new HalloweenType(), $next), 'the owner\'s example, word for word');
        self::assertSame('Spooky Night 2026 was a success — thank you! Come back next year (dates to be announced). Residents: sign in to get your house ready for next year.',
            ClosingMessage::text(self::event('halloween', 'Spooky Night 2026', '2026-10-31', '2026-10-31'), new HalloweenType(), null), 'a name with its year keeps it; no next event: to be announced');
        self::assertStringContainsString('The next sale: dates to be announced.', ClosingMessage::text(self::event('garage_sale', 'Community Rummage Sale', '2027-04-10', '2027-04-11'), new GarageSaleType(), self::event('garage_sale', 'x', null, null)));
        self::assertStringContainsString('Come back next season (Nov 1, 2027 – Jan 1, 2028, tentative).', ClosingMessage::text(self::event('holiday_lights', 'Christmas Lights', '2026-11-01', '2027-01-01'), new HolidayLightsType(), self::event('holiday_lights', 'x', '2027-11-01', '2028-01-01', true)));
        $own = new EventOccurrence(1, 'halloween', 'Halloween', '2026-10-31', '2026-10-31', '17:00', '21:00', ['closing_message' => '  Thanks, neighbors!  '], EventOccurrence::CLOSED);
        self::assertSame('Thanks, neighbors!', ClosingMessage::text($own, new HalloweenType(), $next), 'the organizer\'s own text wins');
        self::assertSame('Halloween 2026 was a success — thank you! Come back next year (Oct 31, 2027, tentative). Residents: sign in to get your house ready for next year.',
            ClosingMessage::preview($closed, new HalloweenType(), '2027-10-31', '2027-10-31'), 'the confirmation shows the text closing will show');
    }

    private static function type(string $key): HalloweenType|HolidayLightsType|GarageSaleType
    {
        return match ($key) {
            'halloween' => new HalloweenType(),
            'holiday_lights' => new HolidayLightsType(),
            default => new GarageSaleType(),
        };
    }

    private static function event(string $type, string $name, ?string $from, ?string $to, bool $tentative = false): EventOccurrence
    {
        return new EventOccurrence(1, $type, $name, $from, $to, '17:00', '21:00', [], EventOccurrence::OPEN, '2026-01-15 10:00:00+00', datesTentative: $tentative);
    }
}
