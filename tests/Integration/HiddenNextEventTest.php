<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Listing\ListingRepository;
use App\Tests\Support\AppTestCase;

/**
 * @module tests/Integration/HiddenNextEventTest
 * @description Hidden events (0.17, D-20261101-01): closing an event creates next year's as hidden — visitors never
 *   see it: not in /api/events, the switcher or any page, and `e=<id>` naming it is an unknown id (404 for every
 *   public API and route plans, the default event for every page, a shared stop list included); the thank-you card
 *   keeps "Get my house ready" without naming it and shows "See <next event>" only once it is announced. Residents see
 *   it in My house (its tab, its form, "Use last year's details") and list for it; nothing they save is public. The
 *   organizer announces it (upcoming), opens it directly, hides an upcoming event or a draft again, creates an event as
 *   hidden, and the one-current-per-type rule counts hidden events. An organizer's moderation hold reaches a hidden
 *   event's listings too.
 * @dependencies tests/Support/AppTestCase, Listing/ListingRepository
 */
final class HiddenNextEventTest extends AppTestCase
{
    private string $csrf = '';

    public function testClosingCreatesNextYearsEventHiddenFromEveryPublicSurface(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('11', 'alice@example.test');
        $this->organizer();
        $r = $this->json('POST', '/api/admin/events/1/close', ['confirm' => true], $this->h());
        self::assertSame([200, 'hidden', '2027-10-31', true, false], [$this->code(), $r['next']['state'], $r['next']['starts_on'], $r['next']['dates_tentative'], $r['next']['is_default']],
            'next year\'s Halloween is created hidden, with its tentative date');
        $next = $r['next']['id'];
        $this->client->getCookieJar()->clear();

        self::assertSame([[1, 'closed']], array_map(static fn (array $e) => [$e['id'], $e['state']], $this->json('GET', '/api/events')['events']), 'not listed for visitors');
        foreach (['/api/event', '/api/community', '/api/homes', "/api/homes/$home"] as $uri) {
            $body = $this->json('GET', "$uri?e=$next");
            self::assertSame([404, 'not_found'], [$this->code(), $body['error']['code'] ?? null], "$uri answers like an unknown id");
        }
        $this->json('POST', "/api/routes/plan?e=$next", ['stops' => [$home, $home]]);
        self::assertSame(404, $this->code(), 'no route plans for it');
        $this->json('POST', "/api/auth/request-link?e=$next", ['email' => 'b@example.test', 'pow' => $this->solvePow('claim'), 'claim' => ['address' => $this->byId($home), 'listing' => self::listing()]]);
        self::assertSame(404, $this->code(), 'no claim request for it from the public site');

        foreach (["/?e=$next", "/about?e=$next", "/?stops=$home.$home&e=$next", "/theme.css?e=$next", '/'] as $uri) {
            $this->client->request('GET', $uri);
            $html = (string) $this->client->getResponse()->getContent();
            self::assertSame(200, $this->code(), $uri);
            self::assertStringNotContainsString("e=$next", $html, "$uri never names the hidden event");
        }
        $page = $this->client->request('GET', "/?e=$next");
        self::assertSame([1, 'halloween'], [$page->filter('.event-status.is-closed')->count(), $page->filter('html')->attr('data-event-type')], 'a page shows the default event');
        self::assertCount(0, $page->filter('.event-switch'), 'one public event: no switcher, no 2027 tab');
        $closed = $this->client->request('GET', '/');
        self::assertSame('Halloween 2026 was a success — thank you! Come back next year (dates to be announced). Residents: sign in to get your house ready for next year.',
            $closed->filter('.event-status-message')->text(), 'the thank-you text does not name the hidden event\'s dates');
        self::assertSame(['/me'], $closed->filter('.event-status-actions a')->each(static fn ($a) => $a->attr('href')), '"Get my house ready" only, without the hidden event');
        self::assertSame('Get my house ready', $closed->filter('.event-status-actions a')->text());
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM listings WHERE event_date = ?', ['2027-10-31']));
    }

    private const DETAILS = '{"candy": true, "nonfood": false, "adult_drinks": false, "low_noise": false, "jump_scares": false, "flashing_lights": false, "loud_audio": false, "scare_level": "gentle", "stroller_friendly": false}';

    public function testResidentsGetReadyForAHiddenEventInMyHouse(): void
    {
        $this->configureCommunity();
        $mine = $this->listHome('31', 'me@example.test', self::listing(['note' => 'Full-size bars', 'nonfood' => true, 'start' => '18:00', 'end' => '20:00']));
        $this->organizer();
        $next = $this->json('POST', '/api/admin/events/1/close', ['confirm' => true], $this->h())['next']['id'];

        $this->client->getCookieJar()->clear();
        $this->json('GET', "/api/me?e=$next");
        self::assertSame(401, $this->code(), 'residents only');
        $this->client->request('GET', "/me?e=$next");
        self::assertTrue($this->client->getResponse()->isRedirect(), 'My house asks to sign in');

        $this->signIn('me@example.test');
        $csrf = $this->csrf();
        $me = $this->json('GET', "/api/me?e=$next");
        self::assertSame([$next, 'hidden', true], [$me['event']['id'], $me['event']['state'], $me['event']['accepts_listings']]);
        self::assertSame([true, true, null], [$me['homes'][0]['can_list'], $me['homes'][0]['can_copy_previous'], $me['homes'][0]['listing']]);
        self::assertSame([[1, 'closed', true], [$next, 'hidden', false]], array_map(static fn (array $e) => [$e['event_id'], $e['state'], $e['listed']], $me['homes'][0]['events']));
        $default = $this->json('GET', '/api/me');
        self::assertSame(['closed', ['id' => $next, 'name' => 'Halloween', 'state' => 'hidden']], [$default['event']['state'], $default['event']['next']],
            'the ended event points the resident to the next one');

        $page = $this->client->request('GET', "/me?e=$next");
        self::assertSame(200, $this->code());
        self::assertSame(["/me", "/me?e=$next"], $page->filter('.event-switch a')->each(static fn ($a) => $a->attr('href')), 'My house has its tab');
        self::assertStringContainsString('Getting ready', $page->filter('.event-switch a[aria-current="page"]')->text());
        self::assertStringContainsString('Only residents see', $page->filter('#eventHiddenNotice')->text());

        $prev = $this->json('GET', "/api/me/homes/$mine/previous?e=$next");
        self::assertSame([200, 'Full-size bars', '18:00', true], [$this->code(), $prev['listing']['note'], $prev['listing']['start'], $prev['listing']['details']['nonfood']], 'last year\'s details');
        $r = $this->json('POST', "/api/me/homes/$mine/listing?e=$next", ['details' => $prev['listing']['details'], 'note' => $prev['listing']['note'],
            'start' => '18:00', 'end' => '20:00'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), (string) json_encode($r));
        self::assertSame([['2027-10-31', 'Full-size bars']], $this->db()->fetchAllNumeric('SELECT w.local_date, el.note FROM event_listings el JOIN event_listing_windows w ON w.listing_id = el.id WHERE el.event_id = ?', [$next]));
        $this->json('PATCH', "/api/me/homes/$mine?e=$next", ['note' => 'Ready early'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code(), 'the listing can be edited while hidden');
        self::assertSame('Full-size bars', $this->db()->fetchOne('SELECT note FROM listings WHERE address_id = ?', [$mine]), 'the legacy table keeps the closed Halloween');

        $this->client->getCookieJar()->clear();
        $this->json('GET', "/api/homes?e=$next");
        self::assertSame(404, $this->code());
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        self::assertStringNotContainsString('Ready early', (string) $this->client->request('GET', '/')->html());
    }

    public function testTheOrganizerAnnouncesHidesAndOpensAHiddenEvent(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('41', 'r@example.test');
        $this->organizer();
        $next = $this->json('POST', '/api/admin/events/1/close', ['confirm' => true], $this->h())['next']['id'];
        $row = array_values(array_filter($this->json('GET', '/api/admin/events')['events'], static fn (array $e) => $e['id'] === $next))[0];
        self::assertSame(['hidden', 0], [$row['state'], $row['public']], 'the console lists it');
        $preview = $this->client->request('GET', "/admin/events/$next/preview");
        self::assertSame(200, $this->code(), 'the organizer previews it');
        self::assertStringContainsString('Preview of the hidden event “Halloween”', $preview->filter('.preview-banner')->text());
        $this->db()->executeStatement("INSERT INTO event_listings (event_id, address_id, details, status, note, published, updated_at) VALUES (?, ?, CAST(? AS JSONB), 'active', 'Ready', TRUE, NOW())", [$next, $home, self::DETAILS]);
        $this->db()->executeStatement("INSERT INTO event_listing_windows (listing_id, local_date, start_local, end_local) SELECT id, '2027-10-31', '17:00', '21:00' FROM event_listings WHERE event_id = ?", [$next]);

        $r = $this->json('POST', "/api/admin/events/$next/announce", [], $this->h());
        self::assertSame([422, 'confirmation_required'], [$this->code(), $r['error']['code']]);
        $r = $this->json('POST', "/api/admin/events/$next/announce", ['confirm' => true], $this->h());
        self::assertSame([200, 'upcoming', true], [$this->code(), $r['event']['state'], $r['event']['dates_tentative']], 'announced: upcoming with its tentative date');
        $r = $this->json('POST', "/api/admin/events/$next/announce", ['confirm' => true], $this->h());
        self::assertSame([409, 'not_announceable'], [$this->code(), $r['error']['code']]);

        $this->client->getCookieJar()->clear();
        self::assertSame([[1, 'closed'], [$next, 'upcoming']], array_map(static fn (array $e) => [$e['id'], $e['state']], $this->json('GET', '/api/events')['events']));
        self::assertSame([200, []], [200, $this->json('GET', "/api/homes?e=$next")['homes']], 'announced: its dates, no homes');
        $closed = $this->client->request('GET', '/');
        self::assertSame(["/me?e=$next", "/?e=$next"], $closed->filter('.event-status-actions a')->each(static fn ($a) => $a->attr('href')));
        self::assertSame('See Halloween 2027', $closed->filter('.event-status-actions a.secondary')->text());
        self::assertStringContainsString('(Oct 31, 2027, tentative)', $closed->filter('.event-status-message')->text());
        self::assertCount(2, $closed->filter('.event-switch a'), 'the tab appears');

        $this->organizer();
        $r = $this->json('POST', "/api/admin/events/$next/hide", [], $this->h());
        self::assertSame([422, 'confirmation_required'], [$this->code(), $r['error']['code']]);
        $r = $this->json('POST', "/api/admin/events/$next/hide", ['confirm' => true], $this->h());
        self::assertSame([200, 'hidden'], [$this->code(), $r['event']['state']], 'an announced event is hidden again');
        $r = $this->json('POST', "/api/admin/events/$next/hide", ['confirm' => true], $this->h());
        self::assertSame([409, 'not_hideable'], [$this->code(), $r['error']['code']]);
        foreach ([1 => 'closed'] as $id => $why) {
            $r = $this->json('POST', "/api/admin/events/$id/hide", ['confirm' => true], $this->h());
            self::assertSame([409, 'not_hideable'], [$this->code(), $r['error']['code']], $why);
        }
        $this->client->getCookieJar()->clear();
        self::assertCount(1, $this->json('GET', '/api/events')['events'], 'hidden again: gone from the public list');
        $this->json('GET', "/api/event?e=$next");
        self::assertSame(404, $this->code());

        $this->organizer();
        $r = $this->json('POST', "/api/admin/events/$next/open", ['confirm' => true], $this->h());
        self::assertSame([200, 'open', false], [$this->code(), $r['event']['state'], $r['event']['dates_tentative']], 'a hidden event opens directly');
        $r = $this->json('POST', "/api/admin/events/$next/hide", ['confirm' => true], $this->h());
        self::assertSame([409, 'not_hideable'], [$this->code(), $r['error']['code']], 'an open event is closed, never hidden');
        $this->client->getCookieJar()->clear();
        self::assertSame([$home], array_column($this->json('GET', "/api/homes?e=$next")['homes'], 'id'), 'its homes are on the map');
        self::assertSame(['event.closed', 'event.announced', 'event.hidden', 'event.opened'],
            $this->db()->fetchFirstColumn("SELECT action FROM audit_events WHERE target_type = 'event' ORDER BY id"));
    }

    public function testAnEventCanBeCreatedOrDraftedHiddenAndOneCurrentPerTypeCountsHiddenEvents(): void
    {
        $this->configureCommunity();
        $this->organizer();
        $r = $this->json('POST', '/api/admin/events', ['type' => 'holiday_lights', 'name' => 'Lights', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03', 'state' => 'hidden'], $this->h());
        self::assertSame([201, 'hidden'], [$this->code(), $r['event']['state']], 'created hidden: residents can get ready');
        $lights = $r['event']['id'];
        $r = $this->json('POST', '/api/admin/events', ['type' => 'holiday_lights', 'name' => 'Lights 2', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03', 'state' => 'hidden'], $this->h());
        self::assertSame([409, 'type_current'], [$this->code(), $r['error']['code']], 'one current event per type, hidden ones included');
        $r = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'state' => 'open'], $this->h());
        self::assertSame(422, $this->code(), 'a new event starts as a draft or hidden');
        self::assertArrayHasKey('state', $r['error']['fields']);
        $draft = $this->json('POST', '/api/admin/events', ['type' => 'holiday_lights', 'name' => 'Lights draft', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03'], $this->h())['event'];
        self::assertSame('draft', $draft['state']);
        $r = $this->json('POST', '/api/admin/events/'.$draft['id'].'/hide', ['confirm' => true], $this->h());
        self::assertSame([409, 'type_current'], [$this->code(), $r['error']['code']]);
        $r = $this->json('POST', '/api/admin/events/'.$draft['id'].'/announce', ['confirm' => true], $this->h());
        self::assertSame([409, 'type_current'], [$this->code(), $r['error']['code']], 'nor announced while the hidden one is current');
        $sale = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'name' => 'Rummage Sale', 'starts_on' => null, 'ends_on' => null], $this->h())['event'];
        $r = $this->json('POST', '/api/admin/events/'.$sale['id'].'/hide', ['confirm' => true], $this->h());
        self::assertSame([200, 'hidden', null], [$this->code(), $r['event']['state'], $r['event']['starts_on']], 'a draft becomes hidden (its dates may still be to be announced)');

        $this->client->getCookieJar()->clear();
        self::assertSame([1], array_column($this->json('GET', '/api/events')['events'], 'id'));
        $this->organizer();
        $this->json('POST', "/api/admin/events/$lights/announce", ['confirm' => true], $this->h());
        $this->json('POST', "/api/admin/events/$lights/default", [], $this->h());
        self::assertSame([$lights], array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM event_occurrences WHERE is_default')));
        $r = $this->json('POST', "/api/admin/events/$lights/hide", ['confirm' => true], $this->h());
        self::assertSame([200, 'hidden', false], [$this->code(), $r['event']['state'], $r['event']['is_default']]);
        self::assertSame([1], array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM event_occurrences WHERE is_default')), 'the default passes to the open event');
        $r = $this->json('POST', "/api/admin/events/$lights/default", [], $this->h());
        self::assertSame([409, 'not_public'], [$this->code(), $r['error']['code']], 'a hidden event is never the default');
    }

    public function testAModerationHoldReachesAHiddenEventsListing(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('51', 'm@example.test');
        $this->organizer();
        $next = $this->json('POST', '/api/admin/events/1/close', ['confirm' => true], $this->h())['next']['id'];
        $this->db()->executeStatement("INSERT INTO event_listings (event_id, address_id, details, status, note, published, updated_at) VALUES (?, ?, CAST(? AS JSONB), 'active', '', TRUE, NOW())", [$next, $home, self::DETAILS]);
        self::assertSame([$next], static::getContainer()->get(ListingRepository::class)->setModeration($home, true, 'test'), 'a hidden event is current');
        self::assertTrue((bool) $this->db()->fetchOne('SELECT moderation_hidden FROM event_listings WHERE event_id = ?', [$next]));
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

    /** @param array<string,mixed>|null $listing */
    private function listHome(string $house, string $email, ?array $listing = null): int
    {
        $id = $this->addAddress($house, 'Pumpkin Ln', 40.004 + (int) $house / 100000, -75.005, 'west');
        $this->confirm($this->requestLink($email, ['address' => $this->byId($id), 'listing' => $listing ?? self::listing()]));
        self::assertSame(303, $this->code());
        $this->client->getCookieJar()->clear();

        return $id;
    }
}
