<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\EventBlockSchema;
use App\Event\Type\HalloweenType;
use App\Event\Type\HolidayLightsType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Community/EventBlockSchemaTest
 * @description The settings' event block for an event of several days (Christmas lights): the first day is `date`,
 *   the last day on or after it and at most 62 days later, across a year end; a one-day event keeps exactly its block.
 * @dependencies Community/EventBlockSchema, Event/Type/HolidayLightsType, Event/Type/HalloweenType
 */
#[CoversClass(EventBlockSchema::class)]
final class EventBlockSchemaTest extends TestCase
{
    /** @return array{0: array<string,mixed>, 1: list<string>} */
    private static function lights(array $ev): array
    {
        $e = [];
        $block = EventBlockSchema::normalize($ev, new HolidayLightsType(), $e);

        return [$block, $e];
    }

    public function testASeasonAcrossTheYearEnd(): void
    {
        [$b, $e] = self::lights(['date' => '2026-12-01', 'ends_on' => '2027-01-03', 'starts_at' => 1080, 'ends_at' => '22:00']);
        self::assertSame([], $e);
        self::assertSame(['name' => 'Christmas Lights', 'date' => '2026-12-01', 'starts_at' => '18:00', 'ends_at' => '22:00', 'type' => 'holiday_lights',
            'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03'], $b, 'YAML may read 18:00 as 1080 minutes');
        self::assertSame(['2026-12-01', '2027-01-03'], EventBlockSchema::range($b));
        self::assertSame(34, EventBlockSchema::days('2026-12-01', '2027-01-03'));
        [$b] = self::lights(['starts_on' => '2026-12-05']);
        self::assertSame(['2026-12-05', '2026-12-05', '2026-12-05'], [$b['date'], $b['starts_on'], $b['ends_on']], 'one night when no last day is given');
    }

    public function testRangeMistakesAreNamed(): void
    {
        self::assertSame(['event.starts_on must be the same day as event.date (the first day of the event).'], self::lights(['date' => '2026-12-01', 'starts_on' => '2026-12-02', 'ends_on' => '2026-12-20'])[1]);
        self::assertSame(['event.ends_on must be YYYY-MM-DD, on or after the first day.'], self::lights(['date' => '2026-12-10', 'ends_on' => '2026-12-09'])[1]);
        self::assertSame(['event.ends_on must be YYYY-MM-DD, on or after the first day.'], self::lights(['date' => '2026-12-10', 'ends_on' => '2026-02-30'])[1]);
        self::assertSame(['event.ends_on: an event spans at most 62 days.'], self::lights(['date' => '2026-11-15', 'ends_on' => '2027-01-16'])[1]);
        self::assertSame([], self::lights(['date' => '2026-11-15', 'ends_on' => '2027-01-15'])[1], '62 days is the limit');
        self::assertSame(['event.date must be YYYY-MM-DD.', 'event.starts_at/ends_at must be HH:MM with the end after the start.'], self::lights(['date' => 'soon', 'starts_at' => '22:00', 'ends_at' => '18:00'])[1]);
    }

    public function testAOneDayEventKeepsItsBlock(): void
    {
        $e = [];
        $b = EventBlockSchema::normalize(['date' => '2026-10-31', 'ends_on' => '2026-11-02'], new HalloweenType(), $e);
        self::assertSame([], $e);
        self::assertSame(['name', 'date', 'starts_at', 'ends_at', 'type'], array_keys($b), 'no starts_on/ends_on for a one-day event');
        self::assertSame(['2026-10-31', '2026-10-31'], EventBlockSchema::range($b));
    }
}
