<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\EventsCloseEndedCommand;
use App\Event\EndedEvents;
use App\Event\EventLifecycle;
use App\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @module tests/Integration/EventAutoCloseTest
 * @description Events close by themselves after their last day (0.18, D-20261104-01): an open event closes from the start
 *   of the local day after its last day in the community's time zone, on the injected clock (these tests move a mock
 *   clock and never sleep), through the same life-cycle code as the organizer's Close: next year's event is created
 *   hidden, the change is audited with the "cli" actor, nothing happens twice and a date moved meanwhile is checked again
 *   under the lock. Hidden, upcoming and draft events never close by themselves. The console command honours
 *   EVENTS_AUTO_CLOSE (off on fixed-date demo stacks) unless forced, and one failure leaves the others to close.
 * @dependencies tests/Support/AppTestCase, Event/EndedEvents, Event/EventLifecycle, Command/EventsCloseEndedCommand
 */
final class EventAutoCloseTest extends AppTestCase
{
    /** @return iterable<string, array{0:string, 1:string, 2:string}> time zone, the last local minute of Oct 31, the first of Nov 1 (both UTC) */
    public static function zones(): iterable
    {
        yield 'New York, four hours behind' => ['America/New_York', '2026-11-01 03:59:00 UTC', '2026-11-01 04:00:00 UTC'];
        yield 'Los Angeles, seven hours behind' => ['America/Los_Angeles', '2026-11-01 06:59:00 UTC', '2026-11-01 07:00:00 UTC'];
        yield 'Auckland, its day starts before UTC\'s' => ['Pacific/Auckland', '2026-10-31 10:59:00 UTC', '2026-10-31 11:00:00 UTC'];
        yield 'UTC itself' => ['UTC', '2026-10-31 23:59:00 UTC', '2026-11-01 00:00:00 UTC'];
    }

    #[DataProvider('zones')]
    public function testAnOpenEventClosesFromTheStartOfTheLocalDayAfterItsLastDay(string $zone, string $lastMinute, string $nextDay): void
    {
        $this->configureCommunity(['community' => ['timezone' => $zone]]);
        static::mockTime($lastMinute);
        self::assertSame(['closed' => [], 'skipped' => [], 'failed' => []], $this->ended()->run(), "still open at 23:59 local on the last day ($zone)");
        self::assertSame('open', $this->stateOf(1));

        static::mockTime($nextDay);
        $run = $this->ended()->run();
        self::assertSame([[1, 'Halloween']], array_map(static fn (array $c) => [$c['id'], $c['name']], $run['closed']), "closed at 00:00 local the next day ($zone)");
        self::assertSame('closed', $this->stateOf(1));
    }

    public function testAYearCrossingSeasonStaysOpenAcrossNewYearAndClosesTheDayAfterItsLastDay(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-11-01', '2027-01-01', 'open');
        foreach (['2026-12-31 12:00:00 UTC', '2027-01-01 04:59:00 UTC', '2027-01-01 05:00:00 UTC', '2027-01-02 04:59:00 UTC'] as $moment) {
            static::mockTime($moment);
            $this->ended()->run();
            self::assertSame('open', $this->stateOf($lights), "open at $moment (New York): Dec 31 and Jan 1 are inside the season");
        }
        static::mockTime('2027-01-02 05:00:00 UTC');
        self::assertSame([$lights], array_column($this->ended()->run()['closed'], 'id'), 'closed at 00:00 on Jan 2 (New York)');
        self::assertSame(['hidden', '2027-11-01', '2028-01-01', true], $this->nextOf('holiday_lights'), 'next season: the same nights a year later, hidden and tentative');
    }

    /** One locked transaction through the organizer's Close: the same result, audited as "cli", with what it closed. */
    public function testItClosesLikeTheOrganizersCloseAndIsAudited(): void
    {
        $this->configureCommunity();
        static::mockTime('2026-11-02 12:00:00 UTC');
        $this->ended()->run();

        self::assertSame(['hidden', '2027-10-31', '2027-10-31', true], $this->nextOf('halloween'), 'next year\'s event is created hidden');
        $this->client->request('GET', '/');
        self::assertSame(200, $this->code());
        self::assertSame([[1, 'closed']], array_map(static fn (array $e) => [$e['id'], $e['state']], $this->json('GET', '/api/events')['events']), 'visitors never see the hidden one');
        $audit = $this->db()->fetchAssociative("SELECT actor_user_id, actor_label, source, target_id, details FROM audit_events WHERE action = 'event.closed'");
        self::assertNotFalse($audit);
        self::assertSame([null, 'cli', 'cli', '1'], [$audit['actor_user_id'], $audit['actor_label'], $audit['source'], $audit['target_id']], 'an automatic actor');
        $details = json_decode((string) $audit['details'], true);
        self::assertEquals(['automatic' => true, 'last_day' => '2026-10-31', 'next_id' => 2], array_intersect_key($details, array_flip(['automatic', 'last_day', 'next_id'])));
        self::assertNotNull($this->db()->fetchOne('SELECT closed_at FROM event_occurrences WHERE id = 1'));
    }

    public function testItIsIdempotentAndLeavesOneNextYear(): void
    {
        $this->configureCommunity();
        static::mockTime('2026-11-02 12:00:00 UTC');
        self::assertCount(1, $this->ended()->run()['closed']);
        self::assertSame(['closed' => [], 'skipped' => [], 'failed' => []], $this->ended()->run(), 'nothing left to do');
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action = 'event.closed'"));
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_occurrences'));
    }

    public function testAnOrganizersCloseBeforeTheLoopLeavesNothingToDo(): void
    {
        $this->configureCommunity();
        static::getContainer()->get(EventLifecycle::class)->close(1, 'Thank you all!', null);
        static::mockTime('2026-11-02 12:00:00 UTC');
        self::assertSame([], $this->ended()->run()['closed']);
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action = 'event.closed'"));
        self::assertNull(static::getContainer()->get(EventLifecycle::class)->closeEnded(1, '2026-11-02'), 'a closed event is left alone');
    }

    public function testTheDateIsCheckedAgainUnderTheLock(): void
    {
        $this->configureCommunity();
        $lifecycle = static::getContainer()->get(EventLifecycle::class);
        self::assertNull($lifecycle->closeEnded(1, '2026-10-31'), 'its last day: still on');
        self::assertSame('open', $this->stateOf(1));
        // The organizer moved the event while the loop was reading the list: the day the loop saw no longer ends it.
        $this->db()->executeStatement("UPDATE event_occurrences SET starts_on = '2026-11-07', ends_on = '2026-11-07' WHERE id = 1");
        self::assertNull($lifecycle->closeEnded(1, '2026-11-02'), 'the new date is what counts');
        self::assertSame('open', $this->stateOf(1));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action = 'event.closed'"));
        self::assertNull($lifecycle->closeEnded(999, '2026-11-02'), 'an unknown event is not an error');
    }

    public function testOnlyOpenEventsWithDatesCloseByThemselves(): void
    {
        $this->configureCommunity();
        $this->db()->executeStatement("UPDATE event_occurrences SET type = 'garage_sale', starts_on = '2027-12-01', ends_on = '2027-12-01' WHERE id = 1"); // open and not over
        $ids = [];
        foreach (['hidden' => ['halloween', '2020-10-31', '2020-10-31'], 'upcoming' => ['holiday_lights', '2020-11-01', '2021-01-01'], 'draft' => ['halloween', '2019-10-31', '2019-10-31'],
            'closed' => ['halloween', '2018-10-31', '2018-10-31'], 'archived' => ['halloween', '2017-10-31', '2017-10-31']] as $state => [$type, $from, $to]) {
            $ids[$state] = $this->event($type, ucfirst($state), $from, $to, $state);
        }
        $ids['to be announced'] = $this->event('garage_sale', 'Later', null, null, 'draft');
        static::mockTime('2026-11-30 12:00:00 UTC');
        self::assertSame(['closed' => [], 'skipped' => [], 'failed' => []], $this->ended()->run());
        foreach (['hidden', 'upcoming', 'draft', 'closed', 'archived'] as $state) {
            self::assertSame($state, $this->stateOf($ids[$state]), "$state events are never closed by the date");
        }
        self::assertSame('open', $this->stateOf(1));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action = 'event.closed'"));
    }

    public function testSeveralEndedEventsCloseOldestFirstAndAnEventOfAnUnofferedTypeIsSkipped(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-10-01', '2026-10-20', 'open');
        $this->db()->executeStatement('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_type_check');
        $this->db()->executeStatement("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_type_check CHECK (type IN ('halloween', 'holiday_lights', 'garage_sale', 'fireworks'))");
        try {
            $fireworks = $this->event('fireworks', 'Fireworks Night', '2026-07-04', '2026-07-04', 'open');
            static::mockTime('2026-11-02 12:00:00 UTC');
            $run = $this->ended()->run();
            self::assertSame([$lights, 1], array_column($run['closed'], 'id'), 'oldest first');
            self::assertSame([$fireworks], array_column($run['skipped'], 'id'), 'a type this release does not offer is left for the organizer to archive');
            self::assertSame([], $run['failed']);
            self::assertSame('open', $this->stateOf($fireworks));
        } finally {
            $this->db()->executeStatement("DELETE FROM event_occurrences WHERE type = 'fireworks'");
            $this->db()->executeStatement('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_type_check');
            $this->db()->executeStatement("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_type_check CHECK (type IN ('halloween', 'holiday_lights', 'garage_sale'))");
        }
    }

    public function testTheCommandHonoursTheFlagAndForceOverridesIt(): void
    {
        $this->configureCommunity();
        static::mockTime('2026-11-02 12:00:00 UTC');
        $ended = $this->ended();

        $off = new CommandTester(new EventsCloseEndedCommand($ended, false));
        self::assertSame(0, $off->execute([]));
        self::assertStringContainsString('off (EVENTS_AUTO_CLOSE=0)', $off->getDisplay());
        self::assertSame('open', $this->stateOf(1), 'a fixed-date demo stack keeps its events');

        $forced = new CommandTester(new EventsCloseEndedCommand($ended, false));
        self::assertSame(0, $forced->execute(['--force' => true]));
        self::assertStringContainsString('Closed “Halloween”', $forced->getDisplay());
        self::assertSame('closed', $this->stateOf(1));

        $on = new CommandTester(new EventsCloseEndedCommand($ended, true));
        self::assertSame(0, $on->execute([]));
        self::assertStringContainsString('No event has ended', $on->getDisplay(), 'run again: nothing to do');
    }

    /** The container's command takes its flag from the environment, as compose.yaml and compose.dev.yaml set it. */
    public function testTheCommandIsRegistered(): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        self::assertTrue($application->has('app:events:close-ended'));
    }

    private function ended(): EndedEvents
    {
        return static::getContainer()->get(EndedEvents::class);
    }

    private function stateOf(int $id): string
    {
        return (string) $this->db()->fetchOne('SELECT state FROM event_occurrences WHERE id = ?', [$id]);
    }

    /** @return array{0:string, 1:?string, 2:?string, 3:bool} the state, dates and tentative flag of the type's current event */
    private function nextOf(string $type): array
    {
        $r = $this->db()->fetchAssociative("SELECT state, starts_on, ends_on, dates_tentative FROM event_occurrences WHERE type = ? AND state IN ('hidden', 'upcoming', 'open')", [$type]);
        self::assertNotFalse($r);

        return [(string) $r['state'], $r['starts_on'], $r['ends_on'], (bool) $r['dates_tentative']];
    }

    private function event(string $type, string $name, ?string $from, ?string $to, string $state): int
    {
        return (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, activated_at, closed_at, archived_at)
            VALUES (?, ?, ?, ?, '17:00', '21:00', ?, NOW(), CASE WHEN ? IN ('open', 'closed') THEN NOW() END, CASE WHEN ? = 'closed' THEN NOW() END, CASE WHEN ? = 'archived' THEN NOW() END) RETURNING id",
            [$type, $name, $from, $to, $state, $state, $state, $state]);
    }
}
