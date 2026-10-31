<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listing;

use App\Community\CommunityConfig;
use App\Community\CommunitySchema;
use App\Event\EventOccurrence;
use App\Event\EventTypeRegistry;
use App\Event\Type\HalloweenType;
use App\Listing\ListingDraft;
use App\Shared\ValidationException;
use App\Tests\Support\TestEventType;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Listing/ListingDraftWindowsTest
 * @description A listing's days: Halloween (one day) takes the event day with the home's hours and still refuses a
 *   `windows` field as unknown (0.9 input unchanged); a type with several days sends `windows`, checked against the
 *   active event by the schedule core, with the organizer's per-listing cap; day-scoped statuses carry the local date.
 * @dependencies Listing/ListingDraft, tests/Support/TestEventType
 */
final class ListingDraftWindowsTest extends TestCase
{
    private static function community(string $type, string $from, string $to, array $eventSettings = []): CommunityConfig
    {
        $s = (new CommunitySchema())->normalize(['community' => ['id' => 'x1', 'name' => 'X', 'timezone' => 'America/Chicago'], 'event' => ['date' => $from]]);
        $s['event']['type'] = $type;

        return new CommunityConfig($s, null, false, null, [], true, new EventOccurrence(3, $type, 'Lights', $from, $to, '17:00', '21:00', $eventSettings, 'active'));
    }

    private static function registry(): EventTypeRegistry
    {
        return new EventTypeRegistry([new HalloweenType(), new TestEventType('holiday_lights', 'nightly_range')]);
    }

    public function testHalloweenTakesTheEventDayAndKeepsRefusingWindows(): void
    {
        $c = self::community('halloween', '2026-10-31', '2026-10-31');
        $draft = ListingDraft::fromInput(['candy' => true, 'start' => '18:00', 'end' => '20:00'], $c, false, null, self::registry());
        self::assertArrayNotHasKey('windows', $draft);
        self::assertSame([['date' => '2026-10-31', 'start' => '18:00', 'end' => '20:00']], ListingDraft::windowsFor($draft, $c->event, new HalloweenType()));
        try {
            ListingDraft::fromInput(['candy' => true, 'windows' => []], $c, false, null, self::registry());
            self::fail('windows is not a Halloween field');
        } catch (ValidationException $e) {
            self::assertSame(['windows' => 'Unknown field.'], $e->fields);
        }
    }

    public function testATypeWithSeveralDaysSendsItsWindows(): void
    {
        $c = self::community('holiday_lights', '2026-12-01', '2027-01-03', ['max_days' => 3]);
        $draft = ListingDraft::fromInput(['details' => ['candy' => true], 'windows' => [['date' => '2027-01-01', 'start' => '18:00', 'end' => '22:00'], ['date' => '2026-12-31', 'start' => '17:00', 'end' => '23:30']]], $c, false, null, self::registry());
        self::assertSame(['2026-12-31', '2027-01-01'], array_column($draft['windows'], 'date'));
        self::assertSame($draft['windows'], ListingDraft::windowsFor($draft, $c->event, new TestEventType()));
        $partial = ListingDraft::fromInput(['note' => 'Same nights'], $c, true, $draft, self::registry());
        self::assertSame($draft['windows'], $partial['windows'], 'a partial update keeps the nights');
        try {
            ListingDraft::fromInput(['details' => ['candy' => true], 'windows' => array_map(static fn (int $d) => ['date' => "2026-12-0$d", 'start' => '18:00', 'end' => '20:00'], [1, 2, 3, 4])], $c, false, null, self::registry());
            self::fail('the organizer cap');
        } catch (ValidationException $e) {
            self::assertSame(['windows' => 'Choose at most 3 days.'], $e->fields);
        }
        $this->expectException(ValidationException::class);
        ListingDraft::windowsFor(ListingDraft::fromInput(['details' => ['candy' => true]], $c, false, null, self::registry()), $c->event, new TestEventType());
    }

    public function testADayScopedStatusCarriesTheLocalDate(): void
    {
        $c = self::community('halloween', '2026-10-31', '2026-10-31');
        $draft = ListingDraft::fromInput(['candy' => true, 'status' => 'paused'], $c, false, null, self::registry());
        self::assertNull(ListingDraft::statusDate($draft, new HalloweenType(), $c, new \DateTimeImmutable('2026-11-01T04:30:00Z')), 'no Halloween status is day-scoped');
        $lights = self::community('holiday_lights', '2026-12-01', '2027-01-03');
        self::assertSame('2026-12-31', $lights->localDate(new \DateTimeImmutable('2027-01-01T04:30:00Z')), '22:30 in Chicago is still New Year\'s Eve');
    }
}
