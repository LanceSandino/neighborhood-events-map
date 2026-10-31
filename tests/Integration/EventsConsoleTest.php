<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * @module tests/Integration/EventsConsoleTest
 * @description Organizer → Events (D-20261031-05/-07, ADR 0011): drafts of offered types only, strict input, an
 *   organizer-only preview without homes, one current event of a kind, closing (the thank-you message, next year's
 *   upcoming event with a tentative date, nothing republished, never reopened), season isolation for residents ("List
 *   for <event>" starts empty, a closed event is read-only), opening and the default, archive, CSRF-checked export,
 *   retention purge, moving the event on the map, the Community → Event block as a view of the default event, and a
 *   type this release does not offer. Every change is audited and mirrored for the previous release.
 * @dependencies tests/Support/AppTestCase
 */
final class EventsConsoleTest extends AppTestCase
{
    private string $csrf = '';

    /**
     * The season changes by closing (0.15, ADR 0011; this replaces 0.11's switch, which archived the event on the map):
     * the closed event shows its thank-you message instead of homes, next year's event is created as upcoming with a
     * tentative date, residents keep their claims and list for it from an empty form, nothing is carried over or
     * revived, and it goes on the map when the organizer opens it.
     */
    public function testAnOrganizerClosesTheSeasonNextYearIsCreatedAndNothingCarriesOver(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('11', 'alice@example.test', self::listing(['nonfood' => true, 'note' => 'Last year: full-size bars']));
        $this->organizer();
        $list = $this->json('GET', '/api/admin/events');
        self::assertSame([['key' => 'halloween', 'name' => 'Halloween', 'schedule_kind' => 'single_day', 'max_days' => 1, 'route_profiles' => ['walking'], 'new_dates' => ['2026-10-31', '2026-10-31']],
            ['key' => 'holiday_lights', 'name' => 'Christmas Lights', 'schedule_kind' => 'nightly_range', 'max_days' => 62, 'route_profiles' => ['walking', 'driving'], 'new_dates' => ['2026-11-01', '2027-01-01']],
            ['key' => 'garage_sale', 'name' => 'Garage Sale', 'schedule_kind' => 'sale_days', 'max_days' => 3, 'route_profiles' => ['walking', 'driving'], 'new_dates' => [null, null]]], array_map(
                static fn (array $t) => array_intersect_key($t, array_flip(['key', 'name', 'schedule_kind', 'max_days', 'route_profiles', 'new_dates'])), $list['types']), 'a new event starts on the type\'s usual dates');
        [$current] = $list['events'];
        self::assertSame(['open', true, 1, 1, true], [$current['state'], $current['is_default'], $current['opted_in'], $current['public'], $current['type_offered']]);
        self::assertSame($current['id'], $list['default_id']);
        self::assertSame(['Halloween 2026 was a success — thank you! Come back next year (Oct 31, 2027, tentative). Residents: sign in to get your house ready for next year.',
            'Halloween', '2027-10-31', true], [$current['close_preview']['message'], $current['close_preview']['next']['name'], $current['close_preview']['next']['starts_on'],
            $current['close_preview']['next']['dates_tentative']], 'the organizer sees what closing will say and create');

        $bad = $this->json('POST', '/api/admin/events', ['type' => 'fireworks', 'starts_on' => '2027-05-01'], $this->h());
        self::assertSame(422, $this->code(), 'only types this version offers');
        self::assertSame('Choose one of the event types this version offers: halloween, holiday_lights, garage_sale.', $bad['error']['fields']['type'] ?? null);
        $bad = $this->json('POST', '/api/admin/events', ['type' => 'halloween', 'starts_on' => '2027-10-31', 'default_start' => '21:00', 'default_end' => '18:00', 'colour' => 'x', 'settings' => ['rules' => [str_repeat('r', 161)], 'max_days' => 2, 'closing_message' => str_repeat('t', 401)]], $this->h());
        self::assertSame(422, $this->code());
        self::assertSame(['colour', 'default_end', 'settings.rules', 'settings.max_days', 'settings.closing_message'], array_keys($bad['error']['fields']));

        // An organizer's own draft of the same kind waits while this year's event is current, and is never public.
        $draft = $this->json('POST', '/api/admin/events', ['type' => 'halloween', 'name' => 'Halloween 2027', 'starts_on' => '2027-10-31', 'ends_on' => '2027-11-02',
            'default_start' => '17:30', 'default_end' => '20:30', 'settings' => ['rules' => ['Park on one side only', '  ']]], $this->h())['event'];
        self::assertSame(201, $this->code());
        self::assertSame(['draft', '2027-10-31', '2027-10-31', ['rules' => ['Park on one side only']]], [$draft['state'], $draft['starts_on'], $draft['ends_on'], $draft['settings']], 'one day for a one-day type');
        self::assertStringNotContainsString('Halloween 2027', (string) json_encode($this->json('GET', '/api/event')), 'a draft is never public');
        self::assertStringNotContainsString('Halloween 2027', (string) json_encode($this->json('GET', '/api/events')));
        $html = $this->client->request('GET', '/admin/events/'.$draft['id'].'/preview')->html();
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Preview of the draft “Halloween 2027”', $html);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('11 Pumpkin Ln', $html);
        self::assertStringNotContainsString('home.js', $html, 'no map scripts load the open event\'s homes');
        $this->client->request('GET', '/admin/events/'.$current['id'].'/preview');
        self::assertSame(404, $this->code(), 'only drafts have a preview');
        $r = $this->json('POST', '/api/admin/events/'.$draft['id'].'/open', ['confirm' => true], $this->h());
        self::assertSame(409, $this->code(), json_encode($r));
        self::assertSame('type_current', $r['error']['code'], 'one current event of a kind');
        $this->json('POST', '/api/admin/events/'.$draft['id'].'/archive', ['confirm' => true], $this->h());
        self::assertSame(200, $this->code());

        // Closing: confirmed, with the organizer's thank-you text; next year's event is created in the same step.
        $this->json('POST', '/api/admin/events/'.$current['id'].'/close', [], $this->h());
        self::assertSame(422, $this->code(), 'closing is confirmed');
        $r = $this->json('POST', '/api/admin/events/'.$current['id'].'/close', ['confirm' => true, 'closing_message' => 'Thanks, Testville! See you next October.'], $this->h());
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame(['closed', true], [$r['event']['state'], $r['event']['is_default']], 'the closed event stays the default until the organizer changes it');
        $next = $r['next'];
        self::assertSame(['halloween', 'Halloween', '2027-10-31', '2027-10-31', true, 'upcoming', '17:00', '21:00'],
            [$next['type'], $next['name'], $next['starts_on'], $next['ends_on'], $next['dates_tentative'], $next['state'], $next['default_start'], $next['default_end']]);
        $this->json('POST', '/api/admin/events/'.$current['id'].'/close', ['confirm' => true], $this->h());
        self::assertSame(409, $this->code(), 'an event closes once');
        $this->json('POST', '/api/admin/events/'.$current['id'].'/open', ['confirm' => true], $this->h());
        self::assertSame(409, $this->code(), 'a closed event never reopens');

        // The closed season's homes leave the map at once; visitors see the thank-you text and next year's event.
        $this->client->getCookieJar()->clear();
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        $this->json('GET', '/api/homes/'.$home);
        self::assertSame(404, $this->code());
        $event = $this->json('GET', '/api/event')['event'];
        self::assertSame([$current['id'], 'closed', 'Thanks, Testville! See you next October.'], [$event['id'], $event['state'], $event['closing_message']]);
        self::assertSame([[$current['id'], 'closed', true], [$next['id'], 'upcoming', false]],
            array_map(static fn (array $e) => [$e['id'], $e['state'], $e['is_default']], $this->json('GET', '/api/events')['events']));
        $crawler = $this->client->request('GET', '/');
        self::assertSame('Thanks, Testville! See you next October.', $crawler->filter('.event-status-message')->text());
        self::assertCount(0, $crawler->filter('script[src*="home.js"]'), 'no homes, no map');
        self::assertSame([['Halloween 2026', 'Ended', 'page', '/'], ['Halloween 2027', 'Soon', null, '/?e='.$next['id']]], $crawler->filter('.event-switch a')->each(
            static fn ($a) => [$a->filter('.event-tab-name')->text(), $a->filter('.event-tab-state')->text(), $a->attr('aria-current'), $a->attr('href')]), 'the switcher names both seasons, in words');
        self::assertSame([], $this->json('GET', '/api/homes?e='.$next['id'])['homes'], 'an upcoming event shows no homes');
        self::assertTrue((bool) $this->db()->fetchOne('SELECT published FROM listings WHERE address_id = ?', [$home]), 'the previous release still shows the closed Halloween (the newest open or closed one)');
        self::assertSame('2026-10-31', json_decode((string) $this->db()->fetchOne('SELECT settings FROM community_settings'), true)['event']['date'], 'the settings mirror names it');

        // The resident keeps the claim; the home is listed for next year only from an empty form (or last year's, by choice).
        $this->signIn('alice@example.test');
        $csrf = $this->csrf();
        $me = $this->json('GET', '/api/me?e='.$next['id']);
        self::assertNull($me['homes'][0]['listing'], 'last season\'s listing is not shown as next year\'s');
        self::assertSame([true, true], [$me['homes'][0]['can_list'], $me['homes'][0]['can_copy_previous']]);
        self::assertSame(['id' => $next['id'], 'name' => 'Halloween', 'type' => 'halloween', 'active' => true, 'state' => 'upcoming'],
            array_intersect_key($me['event'], ['id' => 1, 'name' => 1, 'type' => 1, 'active' => 1, 'state' => 1]));
        self::assertSame([[$current['id'], true], [$next['id'], false]], array_map(static fn (array $x) => [$x['event_id'], $x['listed']], $me['homes'][0]['events']));
        $this->json('PATCH', "/api/me/homes/$home?e=".$next['id'], ['note' => 'Revived?'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(404, $this->code(), 'an old listing cannot be revived by editing it');
        $this->json('PATCH', "/api/me/homes/$home", ['note' => 'Edited after the end'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(403, $this->code(), 'a closed event\'s listing is read-only');
        $r = $this->json('POST', "/api/me/homes/$home/listing?e=".$next['id'], ['details' => ['candy' => true], 'event_id' => $current['id']], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code(), 'the event is named by e only');
        self::assertSame('Unknown field.', $r['error']['fields']['event_id']);
        $this->json('POST', "/api/me/homes/$home/listing?e=".$current['id'], ['details' => ['candy' => true]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(403, $this->code(), 'a closed event takes no listings');
        $r = $this->json('POST', "/api/me/homes/$home/listing?e=".$next['id'], ['details' => ['candy' => true]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), json_encode($r));
        $l = $r['home']['listing'];
        self::assertSame(['', false, '17:00', '21:00'], [$l['note'], $l['details']['nonfood'], $l['start'], $l['end']], 'nothing from last season: the note, non-food and hours are the new event\'s defaults');
        $this->json('POST', "/api/me/homes/$home/listing?e=".$next['id'], ['details' => ['candy' => true]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(409, $this->code());
        self::assertSame([], $this->json('GET', '/api/homes?e='.$next['id'])['homes'], 'listed, but not public before the event opens');
        self::assertSame('Last year: full-size bars', $this->db()->fetchOne('SELECT el.note FROM event_listings el WHERE el.event_id = ?', [$current['id']]), 'last season stays with last season');
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings WHERE address_id = ?', [$home]));

        // Next year opens (its date confirmed) and becomes the default: its homes are on the map.
        $this->organizer();
        $r = $this->json('POST', '/api/admin/events/'.$next['id'].'/open', ['confirm' => true], $this->h());
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame(['open', false], [$r['event']['state'], $r['event']['dates_tentative']]);
        $this->json('POST', '/api/admin/events/'.$next['id'].'/default', [], $this->h());
        self::assertSame(200, $this->code());
        self::assertSame([$home], array_column($this->json('GET', '/api/homes')['homes'], 'id'));
        self::assertSame('2027-10-31', json_decode((string) $this->db()->fetchOne('SELECT settings FROM community_settings'), true)['event']['date'], 'the previous release now mirrors next year\'s event');
        self::assertSame(['event.created', 'event.archived', 'event.closed', 'event.opened', 'event.default'], $this->db()->fetchFirstColumn("SELECT action FROM audit_events WHERE target_type = 'event' ORDER BY id"));
    }

    public function testEndingTheEventLeavesANeutralMapAndRetentionDeletesItsListings(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('21', 'bob@example.test', self::listing());
        $this->organizer();
        $id = (int) $this->json('GET', '/api/admin/events')['default_id'];
        $this->json('POST', "/api/admin/events/$id/purge-listings", ['confirm' => 'DELETE'], $this->h());
        self::assertSame(409, $this->code(), 'only an archived event\'s listings');
        $this->json('POST', "/api/admin/events/$id/archive", [], $this->h());
        self::assertSame(422, $this->code());
        $this->json('POST', "/api/admin/events/$id/archive", ['confirm' => true], $this->h());
        self::assertSame(200, $this->code());

        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', '/');
        self::assertSame(200, $this->code());
        self::assertSame('No event on the map right now', $crawler->filter('h1')->text());
        self::assertCount(0, $crawler->filter('script[src*="home.js"]'));
        self::assertCount(0, $crawler->filter('[data-action="register"]'), 'no listing action between events');
        self::assertSame('Testville', $crawler->filter('title')->text());
        self::assertStringContainsString('No event on the map right now', $this->client->request('GET', '/about')->filter('.about-facts')->text(), 'About names no ended event as coming');
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        $e = $this->json('GET', '/api/event');
        self::assertSame([false, null, null], [$e['event']['active'], $e['event']['id'], $e['event']['type']]);
        self::assertFalse($e['registration']['open']);
        $r = $this->json('POST', '/api/auth/request-link', ['email' => 'carol@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => $this->byId($this->addAddress('23', 'Pumpkin Ln', 40.006, -75.004, 'west')), 'listing' => self::listing()]]);
        self::assertSame(403, $this->code(), json_encode($r));

        $this->organizer();
        $this->client->request('POST', "/api/admin/events/$id/export", ['_csrf' => $this->csrf]);
        self::assertSame(200, $this->code());
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('event_id,event,address_id,address,zone,status', $csv);
        self::assertStringContainsString('21 Pumpkin Ln', $csv);
        self::assertStringNotContainsString('bob@example.test', $csv, 'no owner emails');
        $this->client->request('POST', "/api/admin/events/$id/export", ['_csrf' => 'forged']);
        self::assertSame(403, $this->code());
        $this->client->request('GET', "/api/admin/events/$id/export");
        self::assertSame(405, $this->code(), 'never a GET');

        $r = $this->json('POST', "/api/admin/events/$id/purge-listings", ['confirm' => 'DELETE'], $this->h());
        self::assertSame(['purged' => 1], $r);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings'));
        self::assertFalse($this->db()->fetchOne('SELECT 1 FROM listings WHERE address_id = ?', [$home]), 'the legacy row goes too');
        self::assertNotFalse($this->db()->fetchOne('SELECT 1 FROM claims WHERE address_id = ?', [$home]), 'claims stay');
        self::assertSame(['event.archived', 'event.exported', 'event.listings_purged'], $this->db()->fetchFirstColumn("SELECT action FROM audit_events WHERE target_type = 'event' ORDER BY id"));
    }

    public function testTheActiveEventMovesOnlyWithItsHomesAndTheCommunityFormIsAViewOfIt(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('31', 'dave@example.test', self::listing());
        $this->organizer();
        $id = (int) $this->json('GET', '/api/admin/events')['default_id'];
        $s = $this->json('GET', '/api/admin/community')['settings'];
        self::assertSame(['Halloween', '2026-10-31', '17:00'], [$s['event']['name'], $s['event']['date'], $s['event']['starts_at']]);
        $s['event']['name'] = 'Spooky Saturday';
        $s['event']['ends_at'] = '21:30';
        $this->json('PUT', '/api/admin/community', ['settings' => $s], $this->h());
        self::assertSame(200, $this->code());
        self::assertSame(['Spooky Saturday', '21:30:00'], array_values($this->db()->fetchAssociative('SELECT name, default_end_local FROM event_occurrences WHERE id = ?', [$id]) ?: []), 'name and hours go to the event');
        $s['event']['date'] = '2027-10-31';
        $r = $this->json('PUT', '/api/admin/community', ['settings' => $s], $this->h());
        self::assertSame(422, $this->code(), 'next season is a new event, not a date change');
        self::assertStringContainsString('1 home(s) are listed for this event', $r['error']['problems'][0]);
        self::assertSame('2026-10-31', $this->json('GET', '/api/event')['event']['date']);

        $r = $this->json('PUT', "/api/admin/events/$id", ['starts_on' => '2026-10-30'], $this->h());
        self::assertSame(409, $this->code());
        self::assertSame('listings_move', $r['error']['code']);
        $this->json('PUT', "/api/admin/events/$id", ['type' => 'garage_sale'], $this->h());
        self::assertSame(422, $this->code(), 'the type never changes');
        $r = $this->json('PUT', "/api/admin/events/$id", ['starts_on' => '2026-10-30', 'move_listings' => true], $this->h());
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame([['date' => '2026-10-30', 'start' => '17:30', 'end' => '20:30']], $this->json('GET', "/api/homes/$home")['home']['windows'], 'the home moved with its event');
        self::assertSame('2026-10-30', $this->db()->fetchOne('SELECT event_date FROM listings WHERE address_id = ?', [$home]), 'and its legacy row');
        self::assertSame('2026-10-30', $this->json('GET', '/api/event')['event']['date']);
        self::assertSame('2026-10-30', json_decode((string) $this->db()->fetchOne('SELECT settings FROM community_settings'), true)['event']['date']);
    }

    public function testAnEventOfATypeThisVersionDoesNotOfferBreaksNothingAndCanBeArchived(): void
    {
        $this->configureCommunity();
        $this->listHome('41', 'erin@example.test', self::listing());
        $this->db()->executeStatement("UPDATE event_occurrences SET state = 'archived', archived_at = NOW(), is_default = FALSE WHERE state = 'open'");
        // Every reserved type is offered since 0.13, so the event comes from a newer release (after an image-only
        // rollback): that release's migration widened the type check, as this test does for its own duration.
        $this->db()->executeStatement('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_type_check');
        $this->db()->executeStatement("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_type_check CHECK (type IN ('halloween', 'holiday_lights', 'garage_sale', 'fireworks'))");
        try {
            $this->aNewerReleasesEventBreaksNothing();
        } finally {
            $this->db()->executeStatement("DELETE FROM event_occurrences WHERE type = 'fireworks'");
            $this->db()->executeStatement('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_type_check');
            $this->db()->executeStatement("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_type_check CHECK (type IN ('halloween', 'holiday_lights', 'garage_sale'))");
        }
    }

    private function aNewerReleasesEventBreaksNothing(): void
    {
        $raw = (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, activated_at, is_default)
            VALUES ('fireworks', 'Fireworks Night', '2027-07-04', '2027-07-04', '20:00', '23:00', 'open', NOW(), NOW(), TRUE) RETURNING id");
        $this->repo()->reset();

        $crawler = $this->client->request('GET', '/');
        self::assertSame(200, $this->code());
        self::assertSame('No event on the map right now', $crawler->filter('h1')->text());
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        self::assertFalse($this->json('GET', '/api/event')['event']['active']);
        foreach (['/about', '/login', '/privacy'] as $page) {
            $this->client->request('GET', $page);
            self::assertSame(200, $this->code(), $page);
        }

        $this->organizer();
        $this->client->request('GET', '/admin/events');
        self::assertSame(200, $this->code());
        $listed = array_column($this->json('GET', '/api/admin/events')['events'], null, 'id')[$raw];
        self::assertSame(['fireworks', false, 'fireworks'], [$listed['type'], $listed['type_offered'], $listed['type_name']], 'shown by its key');
        $this->json('PUT', "/api/admin/events/$raw", ['name' => 'Renamed'], $this->h());
        self::assertSame(409, $this->code());
        $this->json('GET', '/api/admin/overview');
        self::assertSame(200, $this->code());
        $this->json('POST', "/api/admin/events/$raw/archive", ['confirm' => true], $this->h());
        self::assertSame(200, $this->code());
        self::assertFalse($this->db()->fetchOne("SELECT 1 FROM event_occurrences WHERE state = 'open'"));
    }

    public function testOnlyOrganizersReachEventsAndThePreview(): void
    {
        $this->configureCommunity();
        $draft = (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
            VALUES ('halloween', 'Next Halloween', '2027-10-31', '2027-10-31', '17:00', '21:00', 'draft', NOW()) RETURNING id");
        $this->client->request('GET', "/admin/events/$draft/preview");
        self::assertSame(302, $this->code());
        self::assertStringStartsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
        $this->json('GET', '/api/admin/events');
        self::assertSame(401, $this->code());
        $this->listHome('51', 'resident@example.test', self::listing());
        $this->signIn('resident@example.test');
        $csrf = $this->csrf();
        $this->client->request('GET', "/admin/events/$draft/preview");
        self::assertSame(403, $this->code());
        $this->json('GET', '/api/admin/events');
        self::assertSame(403, $this->code());
        foreach (['open' => ['confirm' => true], 'announce' => ['confirm' => true], 'default' => [], 'close' => ['confirm' => true]] as $step => $body) {
            $this->json('POST', "/api/admin/events/$draft/$step", $body, ['X-CSRF-Token' => $csrf]);
            self::assertSame(403, $this->code(), $step);
        }
        self::assertSame('draft', $this->db()->fetchOne('SELECT state FROM event_occurrences WHERE id = ?', [$draft]));
        $this->organizer();
        $this->json('POST', "/api/admin/events/$draft/announce", ['confirm' => true]);
        self::assertSame(403, $this->code(), 'organizer writes need the CSRF token');
    }

    /** The organizer's hold stays on the address: a home hidden last season starts hidden when listed for the next one. */
    public function testAHomeHiddenLastSeasonStartsHiddenForTheNextEvent(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('71', 'held@example.test', self::listing());
        $this->organizer();
        $this->json('POST', "/api/admin/listings/$home/hide", ['reason' => 'Unsafe porch'], $this->h());
        self::assertSame(200, $this->code());
        $current = (int) $this->json('GET', '/api/admin/events')['default_id'];
        $next = $this->json('POST', "/api/admin/events/$current/close", ['confirm' => true], $this->h())['next']['id'];
        $this->json('POST', "/api/admin/listings/$home/hide", [], $this->h());
        self::assertSame(422, $this->code(), 'no listing for a current event yet: nothing to hide');
        $this->json('POST', "/api/admin/events/$next/open", ['confirm' => true], $this->h());
        $this->json('POST', "/api/admin/events/$next/default", [], $this->h());
        $this->client->getCookieJar()->clear();
        $this->signIn('held@example.test');
        $r = $this->json('POST', "/api/me/homes/$home/listing", ['details' => ['candy' => true]], ['X-CSRF-Token' => $this->csrf()]);
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertTrue($r['home']['listing']['moderation_hidden'], 'the hold applies to the new listing');
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        self::assertTrue((bool) $this->db()->fetchOne('SELECT moderation_hidden FROM listings WHERE address_id = ?', [$home]), 'and to its legacy row (next year\'s, now the mirrored Halloween)');
        self::assertSame('2027-10-31', $this->db()->fetchOne('SELECT event_date FROM listings WHERE address_id = ?', [$home]));
    }

    /** A claim request (15-minute link) made for an event that ended before it was confirmed lists nothing. */
    public function testAClaimRequestMadeForAnEventThatEndedMeanwhileIsRefused(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('61', 'Pumpkin Ln', 40.0046, -75.005, 'west');
        $token = $this->requestLink('late@example.test', ['address' => $this->byId($id), 'listing' => self::listing(['note' => 'For the old event'])]);
        $this->organizer();
        $current = (int) $this->json('GET', '/api/admin/events')['default_id'];
        $this->json('POST', "/api/admin/events/$current/close", ['confirm' => true], $this->h());
        self::assertSame(200, $this->code());
        $this->client->getCookieJar()->clear();
        $this->confirm($token);
        $me = $this->json('GET', '/api/me');
        self::assertSame('rejected', $me['claim_outcome']['status']);
        self::assertStringContainsString('not on the map any more', $me['claim_outcome']['message']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings'), 'nothing was listed for either event');
        self::assertFalse($this->db()->fetchOne('SELECT 1 FROM claims WHERE address_id = ?', [$id]), 'and nothing was claimed');
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

    /** @param array<string,mixed> $listing */
    private function listHome(string $house, string $email, array $listing): int
    {
        $id = $this->addAddress($house, 'Pumpkin Ln', 40.004 + (int) $house / 100000, -75.005, 'west');
        $this->confirm($this->requestLink($email, ['address' => $this->byId($id), 'listing' => $listing]));
        self::assertSame(303, $this->code());
        $this->client->getCookieJar()->clear();

        return $id;
    }
}
