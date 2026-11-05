<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Event\EventLifecycle;
use App\Tests\Support\AppTestCase;

/**
 * @module tests/Integration/PastEventsTest
 * @description Past events, not tabs (0.18, D-20261104-02): the map's switcher shows only current events (open and
 *   announced upcoming) and still hides with fewer than two; closed events are listed under "Past events", newest first,
 *   each leading to its thank-you page. The entry (at the end of the switcher's row and in the footer) shows whenever a
 *   closed event exists and never otherwise, so an install without one looks as before. /api/events keeps closed events
 *   with their state. Archived and hidden events show nowhere. A thank-you page leads back to the current default event.
 *   My house keeps closed events as read-only tabs.
 * @dependencies tests/Support/AppTestCase, Event/EventLifecycle
 */
final class PastEventsTest extends AppTestCase
{
    public function testTheSwitcherShowsCurrentEventsAndPastEventsListsTheClosedOnesNewestFirst(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        $sale = $this->event('garage_sale', 'Rummage Sale', null, null, 'upcoming');
        $older = $this->event('halloween', 'Halloween', '2024-10-31', '2024-10-31', 'closed');
        $newer = $this->event('halloween', 'Halloween', '2025-10-31', '2025-10-31', 'closed');
        $this->event('halloween', 'Archived One', '2023-10-31', '2023-10-31', 'archived');
        $this->event('halloween', 'Draft One', '2027-10-31', '2027-10-31', 'draft');
        $lightsSale = $this->event('garage_sale', 'Closed Sale', '2026-04-10', '2026-04-11', 'closed');

        $page = $this->client->request('GET', '/');
        self::assertSame(['/', "/?e=$lights", "/?e=$sale"], $page->filter('.event-switch a')->each(static fn ($a) => $a->attr('href')), 'only current events are tabs: the default first');
        self::assertSame(['Halloween', 'Christmas Lights', 'Rummage Sale'], $page->filter('.event-switch .event-tab-name')->each(static fn ($n) => $n->text()));
        self::assertSame(['Soon'], $page->filter('.event-switch .event-tab-state')->each(static fn ($n) => $n->text()), 'no tab says "Ended"');
        self::assertSame([['/past-events', 'Past events']], $page->filter('.event-switch-bar a.past-link')->each(static fn ($a) => [$a->attr('href'), $a->text()]), 'a small control at the end of the switcher');
        self::assertCount(1, $page->filter('.site-footer a[href="/past-events"]'), 'and a link in the footer');
        self::assertCount(0, $page->filter('.event-switch a.past-link'), 'outside the strip, so scrolling the tabs never hides it');

        $list = $this->client->request('GET', '/past-events');
        self::assertSame(200, $this->code());
        self::assertSame('Past events', $list->filter('h1')->text());
        self::assertSame(["/?e=$lightsSale", "/?e=$newer", "/?e=$older"], $list->filter('.past-list a')->each(static fn ($a) => $a->attr('href')), 'newest first, each to its thank-you page');
        self::assertSame(['Closed Sale 2026', 'Halloween 2025', 'Halloween 2024'], $list->filter('.past-name')->each(static fn ($n) => $n->text()), 'each with its year');
        self::assertSame('Apr 10, 2026 – Apr 11, 2026', $list->filter('.past-dates')->first()->text());
        self::assertSame('page', $list->filter('a.past-link')->attr('aria-current'));
        self::assertCount(0, $list->filter('.event-switch a[aria-current]'), 'no tab is this page');
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Archived One', 'Draft One'] as $never) {
            self::assertStringNotContainsString($never, $html);
        }

        $api = $this->json('GET', '/api/events')['events'];
        self::assertSame([1, $older, $newer, $lightsSale, $lights, $sale], array_column($api, 'id'), '/api/events keeps closed events: the default first, then by date');
        self::assertSame(['open', 'closed', 'closed', 'closed', 'open', 'upcoming'], array_column($api, 'state'), 'with their state, so clients can group them');
        self::assertSame(['id', 'type', 'name', 'state', 'starts_on', 'ends_on', 'dates_tentative', 'is_default', 'closing_message'], array_keys($api[1]), 'keys unchanged');
        self::assertSame(['id', 'type', 'name', 'state', 'starts_on', 'ends_on', 'dates_tentative', 'is_default'], array_keys($api[0]));
    }

    public function testTheEntryShowsWithOneCurrentEventThoughTheSwitcherHides(): void
    {
        $this->configureCommunity();
        $this->event('halloween', 'Halloween', '2025-10-31', '2025-10-31', 'closed');
        $page = $this->client->request('GET', '/');
        self::assertCount(0, $page->filter('.event-switch'), 'fewer than two current events: no switcher');
        self::assertCount(1, $page->filter('.event-switch-bar a.past-link'), 'the entry still shows');
        self::assertCount(1, $page->filter('.site-footer a[href="/past-events"]'));
        foreach (['/about', '/privacy', '/login'] as $path) {
            $other = $this->client->request('GET', $path);
            self::assertSame([0, 1], [$other->filter('.event-switch-bar')->count(), $other->filter('.site-footer a[href="/past-events"]')->count()], "$path: the footer has it");
        }
    }

    public function testWithoutAClosedEventThereIsNoEntryAnywhere(): void
    {
        $this->configureCommunity();
        $page = $this->client->request('GET', '/');
        self::assertSame([0, 0, 0], [$page->filter('.event-switch-bar')->count(), $page->filter('.past-link')->count(), $page->filter('.site-footer a[href="/past-events"]')->count()],
            'an install that never closed an event looks exactly as before');
        self::assertCount(0, $page->filter('header.topline.has-switch'));
        $list = $this->client->request('GET', '/past-events');
        self::assertSame([200, 0], [$this->code(), $list->filter('.past-list')->count()]);
        self::assertSame('No past events yet.', $list->filter('.past-empty')->text());
    }

    public function testAnArchivedOrHiddenEventShowsNowhereAndClosingTheOnlyEventKeepsItsThankYouPage(): void
    {
        $this->configureCommunity();
        $closed = static::getContainer()->get(EventLifecycle::class)->close(1, null, null);
        $next = $closed['next']->id;
        $page = $this->client->request('GET', '/');
        self::assertCount(1, $page->filter('.event-status.is-closed'));
        self::assertSame('true', $page->filter('a.past-link')->attr('aria-current'), 'this page is one of the past events');
        self::assertSame(['/me'], $page->filter('.event-status-actions a')->each(static fn ($a) => $a->attr('href')), 'nothing current to go back to; no hidden event named');
        $list = $this->client->request('GET', '/past-events');
        self::assertSame(['/?e=1'], $list->filter('.past-list a')->each(static fn ($a) => $a->attr('href')));
        self::assertStringNotContainsString("e=$next", (string) $this->client->getResponse()->getContent(), 'next year\'s hidden event is never named');

        static::getContainer()->get(EventLifecycle::class)->archive(1, null);
        $list = $this->client->request('GET', '/past-events');
        self::assertSame([0, 'No past events yet.'], [$list->filter('.past-list')->count(), $list->filter('.past-empty')->text()], 'archived: gone from the list');
    }

    public function testAThankYouPageLeadsBackToTheCurrentDefaultEvent(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        static::getContainer()->get(EventLifecycle::class)->close(1, null, null);
        self::assertSame($lights, (int) $this->db()->fetchOne('SELECT id FROM event_occurrences WHERE is_default'));
        $page = $this->client->request('GET', '/?e=1');
        self::assertCount(1, $page->filter('.event-status.is-closed'), 'the existing thank-you page');
        self::assertSame(['/me', '/'], $page->filter('.event-status-actions a')->each(static fn ($a) => $a->attr('href')));
        self::assertSame('Back to Christmas Lights', $page->filter('.event-status-actions a.secondary')->text());
        self::assertStringNotContainsString('e=2', (string) $page->filter('.event-status-actions')->html(), 'no link to the hidden next year');
        self::assertSame('true', $page->filter('a.past-link')->attr('aria-current'));
    }

    public function testTheWayBackIsOneLinkWhenTheNextEventIsTheDefault(): void
    {
        $this->configureCommunity();
        $lifecycle = static::getContainer()->get(EventLifecycle::class);
        $next = $lifecycle->close(1, null, null)['next']->id;
        $lifecycle->announce($next, null);
        $page = $this->client->request('GET', '/?e=1');
        self::assertSame(["/me?e=$next", "/?e=$next"], $page->filter('.event-status-actions a')->each(static fn ($a) => $a->attr('href')), '"See" already is the way back');
        self::assertCount(0, $this->client->request('GET', '/')->filter('.event-switch'), 'one current event: no switcher');
    }

    public function testMyHouseKeepsClosedEventsAsReadOnlyTabs(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('11', 'Pumpkin Ln', 40.0041, -75.005, 'west');
        $this->confirm($this->requestLink('me@example.test', ['address' => $this->byId($id), 'listing' => self::listing()]));
        static::getContainer()->get(EventLifecycle::class)->close(1, null, null);
        $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        $page = $this->client->request('GET', '/me?e=1');
        self::assertSame(200, $this->code());
        self::assertContains('Ended', $page->filter('.event-switch .event-tab-state')->each(static fn ($n) => $n->text()), 'residents read a closed event from its tab');
        self::assertCount(0, $page->filter('.event-switch-bar a.past-link'), 'My house has no second way to the same page');
        $me = $this->json('GET', '/api/me?e=1');
        self::assertSame(['closed', false], [$me['event']['state'], $me['event']['accepts_listings']], 'read-only as before');
        self::assertFalse($me['homes'][0]['can_list']);
    }

    private function event(string $type, string $name, ?string $from, ?string $to, string $state): int
    {
        return (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, activated_at, closed_at, archived_at)
            VALUES (?, ?, ?, ?, '17:00', '21:00', ?, NOW(), CASE WHEN ? IN ('open', 'closed') THEN NOW() END, CASE WHEN ? = 'closed' THEN NOW() END, CASE WHEN ? = 'archived' THEN NOW() END) RETURNING id",
            [$type, $name, $from, $to, $state, $state, $state, $state]);
    }
}
