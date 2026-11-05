<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Event\EndedEvents;
use App\Event\EventConflictException;
use App\Event\EventLifecycle;
use App\Tests\Support\AppTestCase;

/**
 * @module tests/Integration/DefaultEventRuleTest
 * @description What "/" shows once its event is over (D-20261104-03): when the default event closes, by hand or by itself,
 *   the default moves to the current event happening soonest — an open event whose dates include today, then the earliest
 *   starting open event, then the earliest announced upcoming one (dates to be announced last) —, else it stays on the
 *   closed event's thank-you page. A hidden next year's event is never it. A closed default moves on as soon as a public
 *   current event exists. An organizer's "Make default" is never overridden while that event stays current, and a closed
 *   event can no longer be chosen. Every automatic move is audited; the race with the organizer is in
 *   tests/Integration/EventLifecycleRaceTest.
 * @dependencies tests/Support/AppTestCase, Event/EventLifecycle, Event/EndedEvents
 */
final class DefaultEventRuleTest extends AppTestCase
{
    private string $csrf = '';

    public function testAnOpenEventHappeningTodayBeatsAnEarlierStartingOne(): void
    {
        $this->configureCommunity(); // Halloween, on today, is the default
        $earlier = $this->event('garage_sale', 'Sale That Started First', '2026-09-01', '2026-09-30', 'open'); // first day earliest, not on today
        $today = $this->event('holiday_lights', 'Christmas Lights', '2026-10-30', '2026-12-31', 'open');
        $this->lifecycle()->close(1, null, null);
        self::assertSame($today, $this->defaultId(), 'on the map and its dates include today');
        self::assertNotSame($earlier, $this->defaultId());
    }

    public function testWithoutOneTodayTheEarliestStartingOpenEvent(): void
    {
        $this->configureCommunity();
        $later = $this->event('garage_sale', 'Spring Sale', '2027-04-10', '2027-04-11', 'open');
        $sooner = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        $this->lifecycle()->close(1, null, null);
        self::assertSame($sooner, $this->defaultId(), 'the earliest first day among the open events');
        self::assertNotSame($later, $this->defaultId());
    }

    public function testWithoutAnOpenEventTheEarliestAnnouncedOneToBeAnnouncedLast(): void
    {
        $this->configureCommunity();
        $tbd = $this->event('garage_sale', 'Rummage Sale', null, null, 'upcoming');
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'upcoming');
        $this->lifecycle()->close(1, null, null);
        self::assertSame($lights, $this->defaultId(), 'dates first; an event to be announced comes last');
        self::assertNotSame($tbd, $this->defaultId());
        $this->client->request('GET', '/');
        self::assertSame(200, $this->code());
        self::assertSame($lights, $this->json('GET', '/api/event')['event']['id'], '"/" shows it');
    }

    public function testAnEventToBeAnnouncedIsStillBetterThanAThankYouPage(): void
    {
        $this->configureCommunity();
        $tbd = $this->event('garage_sale', 'Rummage Sale', null, null, 'upcoming');
        $this->lifecycle()->close(1, null, null);
        self::assertSame($tbd, $this->defaultId());
    }

    public function testWithNoCurrentEventItStaysOnTheClosedEventsThankYouPage(): void
    {
        $this->configureCommunity();
        $this->lifecycle()->close(1, null, null);
        self::assertSame(1, $this->defaultId(), 'next year\'s event is hidden: never the default');
        self::assertCount(1, $this->client->request('GET', '/')->filter('.event-status.is-closed'));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action = 'event.default_moved'"), 'nothing moved');
    }

    public function testAClosedDefaultMovesOnAsSoonAsAPublicCurrentEventExists(): void
    {
        $this->configureCommunity();
        $next = (int) $this->lifecycle()->close(1, null, null)['next']->id;
        self::assertSame(1, $this->defaultId());
        $this->lifecycle()->announce($next, null);
        self::assertSame($next, $this->defaultId(), 'announced: visitors would not find it from a thank-you page');
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action = 'event.default_moved'"));
    }

    /** An install that closed its default by hand before this release: the first run of the command puts it right. */
    public function testTheCommandMovesAClosedDefaultLeftByAnEarlierRelease(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        $this->db()->executeStatement("UPDATE event_occurrences SET state = 'closed', closed_at = NOW() WHERE id = 1"); // what 0.17 could leave behind
        self::assertSame(1, $this->defaultId());
        $run = static::getContainer()->get(EndedEvents::class)->run();
        self::assertSame([], $run['closed']);
        self::assertSame($lights, $this->defaultId());
        $audit = $this->db()->fetchAssociative("SELECT actor_label, target_id, details FROM audit_events WHERE action = 'event.default_moved'");
        self::assertNotFalse($audit);
        self::assertSame(['cli', (string) $lights], [$audit['actor_label'], $audit['target_id']]);
        self::assertSame(['from' => 1], array_intersect_key(json_decode((string) $audit['details'], true), ['from' => 0]));
    }

    public function testAnOrganizersChoiceIsNotOverriddenWhileThatEventStaysCurrent(): void
    {
        $this->configureCommunity();
        $sale = $this->event('garage_sale', 'Spring Sale', '2027-04-10', '2027-04-11', 'upcoming');
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-10-20', '2027-01-03', 'open'); // on the map today
        $this->organizer();
        $this->json('POST', "/api/admin/events/$sale/default", [], $this->h());
        self::assertSame([200, $sale], [$this->code(), $this->defaultId()], 'an upcoming event chosen over an open one');
        $this->lifecycle()->close(1, null, null);
        self::assertSame($sale, $this->defaultId(), 'another event closing does not touch it');
        static::mockTime('2026-11-02 12:00:00 UTC');
        static::getContainer()->get(EndedEvents::class)->run();
        self::assertSame($sale, $this->defaultId(), 'nor does the automatic run');
        self::assertSame('open', $this->db()->fetchOne('SELECT state FROM event_occurrences WHERE id = ?', [$lights]));
        $this->lifecycle()->open($sale, null);
        self::assertSame($sale, $this->defaultId(), 'still chosen once it opens');
        $this->lifecycle()->close($sale, null, null);
        self::assertSame($lights, $this->defaultId(), 'when it closes the rule applies again');
    }

    public function testAClosedEventCanNoLongerBeChosen(): void
    {
        $this->configureCommunity();
        $this->organizer();
        $closed = $this->event('halloween', 'Halloween 2025', '2025-10-31', '2025-10-31', 'closed');
        $r = $this->json('POST', "/api/admin/events/$closed/default", [], $this->h());
        self::assertSame([409, 'not_current'], [$this->code(), $r['error']['code']]);
        self::assertSame(1, $this->defaultId());
        self::assertStringContainsString('closed', $r['error']['message']);
        $this->expectException(EventConflictException::class);
        $this->lifecycle()->makeDefault($closed, null);
    }

    public function testTheOrganizerClosingTheDefaultIsAuditedAsTheOrganizer(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        $this->organizer();
        $list = $this->json('GET', '/api/admin/events');
        $row = array_values(array_filter($list['events'], static fn (array $e) => 1 === $e['id']))[0];
        self::assertSame('Christmas Lights', $row['close_preview']['default_after'], 'the confirmation says where "/" goes');
        $notDefault = array_values(array_filter($list['events'], static fn (array $e) => $lights === $e['id']))[0];
        self::assertNull($notDefault['close_preview']['default_after'], 'closing another event moves nothing');
        $this->json('POST', '/api/admin/events/1/close', ['confirm' => true], $this->h());
        self::assertSame($lights, $this->defaultId());
        $audit = $this->db()->fetchAssociative("SELECT actor_user_id, actor_label, source, target_id, details FROM audit_events WHERE action = 'event.default_moved'");
        self::assertNotFalse($audit);
        self::assertStringStartsWith('admin#', (string) $audit['actor_label']);
        self::assertSame(['admin', (string) $lights], [$audit['source'], $audit['target_id']]);
        self::assertSame(['from' => 1], array_intersect_key(json_decode((string) $audit['details'], true), ['from' => 0]));
    }

    public function testClosingAutomaticallyMovesTheDefaultAndAuditsItAsCli(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'upcoming');
        $sale = $this->event('garage_sale', 'Rummage Sale', null, null, 'upcoming');
        static::mockTime('2026-11-02 12:00:00 UTC');
        static::getContainer()->get(EndedEvents::class)->run();
        self::assertSame($lights, $this->defaultId(), 'the owner\'s case: Christmas is the default, next Halloween is not shown');
        self::assertSame([[$lights, 'upcoming'], [1, 'closed'], [$sale, 'upcoming']], array_map(static fn (array $e) => [$e['id'], $e['state']], $this->json('GET', '/api/events')['events']));
        self::assertSame('cli', $this->db()->fetchOne("SELECT actor_label FROM audit_events WHERE action = 'event.default_moved'"));
    }

    public function testArchivingOrHidingTheDefaultFollowsTheSameRule(): void
    {
        $this->configureCommunity();
        $later = $this->event('garage_sale', 'Spring Sale', '2027-04-10', '2027-04-11', 'open');
        $sooner = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        $this->lifecycle()->archive(1, null);
        self::assertSame($sooner, $this->defaultId());
        $this->lifecycle()->archive($sooner, null);
        self::assertSame($later, $this->defaultId());
        $this->lifecycle()->close($later, null, null);
        self::assertSame($later, $this->defaultId(), 'nothing current: the closed one stays');
    }

    private function lifecycle(): EventLifecycle
    {
        return static::getContainer()->get(EventLifecycle::class);
    }

    private function defaultId(): int
    {
        return (int) $this->db()->fetchOne('SELECT id FROM event_occurrences WHERE is_default');
    }

    private function event(string $type, string $name, ?string $from, ?string $to, string $state): int
    {
        return (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, activated_at, closed_at)
            VALUES (?, ?, ?, ?, '17:00', '21:00', ?, NOW(), CASE WHEN ? IN ('open', 'closed') THEN NOW() END, CASE WHEN ? = 'closed' THEN NOW() END) RETURNING id",
            [$type, $name, $from, $to, $state, $state, $state]);
    }

    /** @return array<string,string> */
    private function h(): array
    {
        return ['X-CSRF-Token' => $this->csrf];
    }

    private function organizer(): void
    {
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, now()) ON CONFLICT (email_normalized) DO UPDATE SET is_admin = TRUE");
        $this->client->getCookieJar()->clear();
        $this->signIn('org@example.test');
        $this->csrf = $this->csrf();
    }
}
