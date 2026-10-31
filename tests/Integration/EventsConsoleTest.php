<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * @module tests/Integration/EventsConsoleTest
 * @description Organizer → Events (D-20261031-05/-07): drafts of offered types only, strict input, an organizer-only
 *   preview without homes, activation that archives the current event in one step without republishing anything,
 *   season isolation for residents ("List for <event>" starts empty), archive, CSRF-checked export, retention purge,
 *   moving the active event, the Community → Event block as a view of the active event, and a type this release
 *   does not offer. Every change is audited and mirrored for the previous release.
 * @dependencies tests/Support/AppTestCase
 */
final class EventsConsoleTest extends AppTestCase
{
    private string $csrf = '';

    public function testAnOrganizerPreparesPreviewsAndSwitchesToTheNextEventWithoutCarryingAnythingOver(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('11', 'alice@example.test', self::listing(['nonfood' => true, 'note' => 'Last year: full-size bars']));
        $this->organizer();
        $list = $this->json('GET', '/api/admin/events');
        self::assertSame([['key' => 'halloween', 'name' => 'Halloween', 'schedule_kind' => 'single_day', 'max_days' => 1, 'route_profiles' => ['walking']],
            ['key' => 'holiday_lights', 'name' => 'Christmas Lights', 'schedule_kind' => 'nightly_range', 'max_days' => 62, 'route_profiles' => ['walking', 'driving']]], array_map(
                static fn (array $t) => array_intersect_key($t, array_flip(['key', 'name', 'schedule_kind', 'max_days', 'route_profiles'])), $list['types']));
        [$current] = $list['events'];
        self::assertSame(['active', 1, 1, true], [$current['state'], $current['opted_in'], $current['public'], $current['type_offered']]);
        self::assertSame($current['id'], $list['active_id']);

        $bad = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'starts_on' => '2027-05-01'], $this->h());
        self::assertSame(422, $this->code(), 'only types this version offers');
        self::assertArrayHasKey('type', $bad['error']['fields']);
        $bad = $this->json('POST', '/api/admin/events', ['type' => 'halloween', 'starts_on' => '2027-10-31', 'default_start' => '21:00', 'default_end' => '18:00', 'colour' => 'x', 'settings' => ['rules' => [str_repeat('r', 161)], 'max_days' => 2]], $this->h());
        self::assertSame(422, $this->code());
        self::assertSame(['colour', 'default_end', 'settings.rules', 'settings.max_days'], array_keys($bad['error']['fields']));

        $draft = $this->json('POST', '/api/admin/events', ['type' => 'halloween', 'name' => 'Halloween 2027', 'starts_on' => '2027-10-31', 'ends_on' => '2027-11-02',
            'default_start' => '17:30', 'default_end' => '20:30', 'settings' => ['rules' => ['Park on one side only', '  ']]], $this->h())['event'];
        self::assertSame(201, $this->code());
        self::assertSame(['draft', '2027-10-31', '2027-10-31', ['rules' => ['Park on one side only']]], [$draft['state'], $draft['starts_on'], $draft['ends_on'], $draft['settings']], 'one day for a one-day type');
        self::assertStringNotContainsString('Halloween 2027', (string) json_encode($this->json('GET', '/api/event')), 'a draft is never public');

        $html = $this->client->request('GET', '/admin/events/'.$draft['id'].'/preview')->html();
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Preview of the draft “Halloween 2027”', $html);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('11 Pumpkin Ln', $html);
        self::assertStringNotContainsString('home.js', $html, 'no map scripts load the active event\'s homes');
        $this->client->request('GET', '/admin/events/'.$current['id'].'/preview');
        self::assertSame(404, $this->code(), 'only drafts have a preview');

        $r = $this->json('POST', '/api/admin/events/'.$draft['id'].'/activate', ['confirm' => true], $this->h());
        self::assertSame(422, $this->code(), 'the organizer names the event the switch replaces');
        $r = $this->json('POST', '/api/admin/events/'.$draft['id'].'/activate', ['confirm' => true, 'replaces' => null], $this->h());
        self::assertSame(409, $this->code(), json_encode($r));
        self::assertSame('changed', $r['error']['code']);
        $r = $this->json('POST', '/api/admin/events/'.$draft['id'].'/activate', ['confirm' => true, 'replaces' => $current['id']], $this->h());
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame('active', $r['event']['state']);
        $this->json('POST', '/api/admin/events/'.$draft['id'].'/activate', ['confirm' => true, 'replaces' => $current['id']], $this->h());
        self::assertSame(409, $this->code(), 'a draft is activated once');

        // The archived season's homes leave the map at once and nothing is republished.
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        $this->json('GET', '/api/homes/'.$home);
        self::assertSame(404, $this->code());
        $event = $this->json('GET', '/api/event')['event'];
        self::assertSame([$draft['id'], '2027-10-31', 'Halloween 2027', '17:30', ['Park on one side only']], [$event['id'], $event['date'], $event['name'], $event['starts_at'], $event['rules']]);
        self::assertFalse((bool) $this->db()->fetchOne('SELECT published FROM listings WHERE address_id = ?', [$home]), 'the previous release does not show it either');
        self::assertSame('2027-10-31', json_decode((string) $this->db()->fetchOne('SELECT settings FROM community_settings'), true)['event']['date'], 'the settings mirror names the new event');
        self::assertSame(['archived', 'active'], array_column($this->db()->fetchAllAssociative('SELECT state FROM event_occurrences ORDER BY id'), 'state'));
        self::assertSame(['event.created', 'event.activated'], $this->db()->fetchFirstColumn("SELECT action FROM audit_events WHERE target_type = 'event' ORDER BY id"));

        // The resident keeps the claim; the home is listed for the new event only from an empty form.
        $this->client->getCookieJar()->clear();
        $this->signIn('alice@example.test');
        $csrf = $this->csrf();
        $me = $this->json('GET', '/api/me');
        self::assertNull($me['homes'][0]['listing'], 'last season\'s listing is not shown as this event\'s');
        self::assertTrue($me['homes'][0]['can_list']);
        self::assertSame(['id' => $draft['id'], 'name' => 'Halloween 2027', 'type' => 'halloween', 'active' => true], array_intersect_key($me['event'], ['id' => 1, 'name' => 1, 'type' => 1, 'active' => 1]));
        $this->json('PATCH', "/api/me/homes/$home", ['note' => 'Revived?'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(404, $this->code(), 'an old listing cannot be revived by editing it');
        $r = $this->json('POST', "/api/me/homes/$home/listing", ['details' => ['candy' => true], 'event_id' => $current['id']], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code(), 'the client never names an event');
        self::assertSame('Unknown field.', $r['error']['fields']['event_id']);
        $r = $this->json('POST', "/api/me/homes/$home/listing", ['details' => ['candy' => true]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), json_encode($r));
        $l = $r['home']['listing'];
        self::assertSame(['', false, '17:30', '20:30'], [$l['note'], $l['details']['nonfood'], $l['start'], $l['end']], 'nothing from last season: the note, non-food and hours are the new event\'s defaults');
        $this->json('POST', "/api/me/homes/$home/listing", ['details' => ['candy' => true]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(409, $this->code());
        self::assertSame([$home], array_column($this->json('GET', '/api/homes')['homes'], 'id'));
        self::assertSame('Last year: full-size bars', $this->db()->fetchOne('SELECT el.note FROM event_listings el WHERE el.event_id = ?', [$current['id']]), 'last season stays with last season');
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings WHERE address_id = ?', [$home]));
    }

    public function testEndingTheEventLeavesANeutralMapAndRetentionDeletesItsListings(): void
    {
        $this->configureCommunity();
        $home = $this->listHome('21', 'bob@example.test', self::listing());
        $this->organizer();
        $id = (int) $this->json('GET', '/api/admin/events')['active_id'];
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
        $id = (int) $this->json('GET', '/api/admin/events')['active_id'];
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
        $this->db()->executeStatement("UPDATE event_occurrences SET state = 'archived', archived_at = NOW() WHERE state = 'active'");
        $raw = (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, activated_at)
            VALUES ('garage_sale', 'Community Rummage Sale', '2027-05-01', '2027-05-02', '08:00', '13:00', 'active', NOW(), NOW()) RETURNING id");
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
        self::assertSame(['garage_sale', false, 'garage_sale'], [$listed['type'], $listed['type_offered'], $listed['type_name']], 'shown by its key');
        $this->json('PUT', "/api/admin/events/$raw", ['name' => 'Renamed'], $this->h());
        self::assertSame(409, $this->code());
        $this->json('GET', '/api/admin/overview');
        self::assertSame(200, $this->code());
        $this->json('POST', "/api/admin/events/$raw/archive", ['confirm' => true], $this->h());
        self::assertSame(200, $this->code());
        self::assertFalse($this->db()->fetchOne("SELECT 1 FROM event_occurrences WHERE state = 'active'"));
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
        $this->json('POST', "/api/admin/events/$draft/activate", ['confirm' => true, 'replaces' => 1], ['X-CSRF-Token' => $csrf]);
        self::assertSame(403, $this->code());
        self::assertSame('draft', $this->db()->fetchOne('SELECT state FROM event_occurrences WHERE id = ?', [$draft]));
        $this->organizer();
        $this->json('POST', "/api/admin/events/$draft/activate", ['confirm' => true, 'replaces' => 1]);
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
        $current = (int) $this->json('GET', '/api/admin/events')['active_id'];
        $next = $this->json('POST', '/api/admin/events', ['type' => 'halloween', 'starts_on' => '2027-10-31'], $this->h())['event']['id'];
        $this->json('POST', "/api/admin/events/$next/activate", ['confirm' => true, 'replaces' => $current], $this->h());
        $this->json('POST', "/api/admin/listings/$home/hide", [], $this->h());
        self::assertSame(422, $this->code(), 'no listing for the new event yet: nothing to hide');
        $this->client->getCookieJar()->clear();
        $this->signIn('held@example.test');
        $r = $this->json('POST', "/api/me/homes/$home/listing", ['details' => ['candy' => true]], ['X-CSRF-Token' => $this->csrf()]);
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertTrue($r['home']['listing']['moderation_hidden'], 'the hold applies to the new listing');
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        self::assertTrue((bool) $this->db()->fetchOne('SELECT moderation_hidden FROM listings WHERE address_id = ?', [$home]), 'and to its legacy row');
    }

    /** A claim request (15-minute link) made for an event that ended before it was confirmed lists nothing. */
    public function testAClaimRequestMadeForAnEventThatEndedMeanwhileIsRefused(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('61', 'Pumpkin Ln', 40.0046, -75.005, 'west');
        $token = $this->requestLink('late@example.test', ['address' => $this->byId($id), 'listing' => self::listing(['note' => 'For the old event'])]);
        $this->organizer();
        $current = (int) $this->json('GET', '/api/admin/events')['active_id'];
        $next = $this->json('POST', '/api/admin/events', ['type' => 'halloween', 'starts_on' => '2027-10-31'], $this->h())['event']['id'];
        $this->json('POST', "/api/admin/events/$next/activate", ['confirm' => true, 'replaces' => $current], $this->h());
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
