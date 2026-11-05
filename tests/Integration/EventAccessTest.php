<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\CommunityBootstrapper;
use App\Community\ConfigException;
use App\Event\EndedEvents;
use App\Event\EventLifecycle;
use App\Tests\Support\AppTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @module tests/Integration/EventAccessTest
 * @description Each event's own access (owner request 2026-11-04, D-20261104-05): an optional settings.access with the
 *   community's allowed values, strictly checked; unset falls back to the community's value, so existing events are
 *   unchanged. Two events of one community show their own notice on the map's Rules sheet and on About, and in the public
 *   API. The console offers the type's labels with "Same as the community" as the empty choice; community.yaml sets it
 *   through event.settings; next year's event keeps it. The community's stored value — what the 0.9 image reads, and what
 *   the community console edits — never takes an event's. The community's access notes are written for its own access, so
 *   an event with a different one does not show them. A default event's hours edit reaches its season row.
 * @dependencies tests/Support/AppTestCase, Community/CommunityBootstrapper, Event/EventLifecycle, Event/EndedEvents
 */
final class EventAccessTest extends AppTestCase
{
    private string $csrf = '';

    public function testTheValueIsStrictlyChecked(): void
    {
        $this->configureCommunity();
        $this->organizer();
        foreach (['open-house', 'PUBLIC', ' public', 7, true, ['public'], ['a' => 'public']] as $bad) {
            $r = $this->json('PUT', '/api/admin/events/1', ['settings' => ['access' => $bad]], $this->h());
            self::assertSame(422, $this->code(), json_encode($bad));
            self::assertArrayHasKey('settings.access', $r['error']['fields'], json_encode($bad));
        }
        $r = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'settings' => ['access' => 'nope']], $this->h());
        self::assertSame([422, true], [$this->code(), isset($r['error']['fields']['settings.access'])], 'a new event too');
        foreach (['unspecified', 'public', 'gated_open', 'gated_guests', 'private'] as $good) {
            $r = $this->json('PUT', '/api/admin/events/1', ['settings' => ['access' => $good]], $this->h());
            self::assertSame([200, $good], [$this->code(), $r['event']['settings']['access']], $good);
        }
    }

    public function testUnsetFallsBackToTheCommunityAndLeavesTheAnswersAsTheyWere(): void
    {
        $this->configureCommunity(['community' => ['access' => 'gated_guests', 'gate_notice' => 'Enter by the north gate.']]);
        $before = $this->json('GET', '/api/community');
        self::assertSame(['gated_guests', 'Enter by the north gate.'], [$before['community']['access'], $before['community']['gate_notice']]);
        self::assertArrayNotHasKey('access', $before['event'], 'an event without its own keeps exactly its keys');
        self::assertSame('{}', str_replace(' ', '', (string) $this->db()->fetchOne('SELECT settings FROM event_occurrences WHERE id = 1')));
        self::assertSame('Gated — residents and guests', self::rulesAccess($this->client->request('GET', '/'))[0]);
        self::assertContains('Gated — residents and guests', self::aboutFacts($this->client->request('GET', '/about')));

        $this->organizer();
        $r = $this->json('PUT', '/api/admin/events/1', ['settings' => ['access' => 'public']], $this->h());
        self::assertSame('public', $r['event']['settings']['access']);
        $r = $this->json('PUT', '/api/admin/events/1', ['settings' => ['rules' => ['Walk slowly']]], $this->h());
        self::assertSame(['public', ['Walk slowly']], [$r['event']['settings']['access'], $r['event']['settings']['rules']], 'a client that leaves it out keeps it');
        foreach (['', null] as $empty) {
            $this->json('PUT', '/api/admin/events/1', ['settings' => ['access' => 'public']], $this->h());
            $r = $this->json('PUT', '/api/admin/events/1', ['settings' => ['access' => $empty]], $this->h());
            self::assertSame(200, $this->code());
            self::assertArrayNotHasKey('access', $r['event']['settings'], '"Same as the community" clears it');
        }
        $this->client->getCookieJar()->clear();
        self::assertSame('gated_guests', $this->json('GET', '/api/community')['community']['access']);
    }

    public function testTwoEventsShowTheirOwnNoticeOnTheMapAboutAndTheApi(): void
    {
        // The owner's case: the rummage sale is open to the public, the holiday lights are not.
        $this->configureCommunity(['community' => ['access' => 'gated_open']]);
        $sale = $this->event('garage_sale', 'Rummage Sale', '2027-04-10', '2027-04-11', 'upcoming', '{"access": "public"}');
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open', '{"access": "gated_guests"}');
        $expect = [
            "/?e=$sale" => ['public', 'Open to the public', 'Public streets: visitors from anywhere are welcome to walk the neighborhood.'],
            "/?e=$lights" => ['gated_guests', 'Gated — residents and guests', 'Only residents and their invited guests can enter. This map does not grant gate access.'],
            '/' => ['gated_open', 'Gated — open for trick-or-treating', 'The gates are opened for visitors during the suggested hours. Follow posted rules and any gate staff.'], // Halloween: the community's, in its type's words
        ];
        foreach ($expect as $uri => [$access, $title, $text]) {
            $page = $this->client->request('GET', $uri);
            self::assertSame(200, $this->code(), $uri);
            self::assertSame([$title, $text], self::rulesAccess($page), "$uri: the Rules sheet");
            $query = (string) parse_url($uri, \PHP_URL_QUERY);
            $about = $this->client->request('GET', '/about'.('' !== $query ? "?$query" : ''));
            self::assertContains($title, self::aboutFacts($about), "$uri: About");
            self::assertStringContainsString($text, $about->filter('.about-facts')->text());
            foreach (['/api/community', '/api/event'] as $api) {
                self::assertSame($access, $this->json('GET', $api.('' !== $query ? "?$query" : ''))['community']['access'], "$api for $uri");
            }
        }
        self::assertSame('public', $this->json('GET', "/api/event?e=$sale")['event']['access'], 'an event with its own names it');
        self::assertSame('gated_guests', $this->json('GET', "/api/event?e=$lights")['event']['access']);
        self::assertArrayNotHasKey('access', $this->json('GET', '/api/event')['event'], 'Halloween keeps exactly its keys');

        $saleOpen = $this->event('garage_sale', 'Second Sale', '2027-05-01', '2027-05-01', 'draft', '{"access": "gated_open"}');
        self::assertSame('Gated — open for the sale', self::rulesAccess($this->preview($saleOpen))[0], 'the type\'s words for "gates open", in the draft preview too');
    }

    public function testTheCommunitysAccessNotesShowOnlyWhereItsAccessApplies(): void
    {
        $this->configureCommunity(['community' => ['access' => 'gated_guests', 'gate_notice' => 'Residents only past the north gate.']]);
        $sale = $this->event('garage_sale', 'Rummage Sale', '2027-04-10', '2027-04-11', 'upcoming', '{"access": "public"}');
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open', '{"access": "gated_guests"}');

        $page = $this->client->request('GET', "/?e=$sale");
        self::assertSame(['Open to the public', 'Public streets: visitors from anywhere are welcome to walk the neighborhood.'], self::rulesAccess($page), 'never "residents only" under "open to the public"');
        self::assertStringNotContainsString('north gate', (string) $this->client->getResponse()->getContent());
        self::assertSame('', $this->json('GET', "/api/community?e=$sale")['community']['gate_notice']);

        self::assertSame(['Gated — residents and guests', 'Residents only past the north gate.'], self::rulesAccess($this->client->request('GET', "/?e=$lights")), 'its own access equals the community\'s: the notes apply');
        self::assertSame(['Gated — residents and guests', 'Residents only past the north gate.'], self::rulesAccess($this->client->request('GET', '/')), 'no access of its own');
    }

    public function testTheConsoleOffersTheTypesLabels(): void
    {
        $this->configureCommunity();
        $this->organizer();
        $types = array_column($this->json('GET', '/api/admin/events')['types'], null, 'key');
        self::assertSame([['value' => 'public', 'label' => 'Open to the public'], ['value' => 'gated_open', 'label' => 'Gated — gates open to shoppers during sale hours'],
            ['value' => 'gated_guests', 'label' => 'Gated — residents and guests'], ['value' => 'private', 'label' => 'Residents only'], ['value' => 'unspecified', 'label' => 'Not stated']],
            $types['garage_sale']['access_options']);
        self::assertSame('Gated — gates open to trick-or-treaters during event hours', $types['halloween']['access_options'][1]['label']);
        self::assertSame('Gated — gates open to visitors during viewing hours', $types['holiday_lights']['access_options'][1]['label']);
    }

    public function testNextYearsEventKeepsTheAccessByHandAndAutomatically(): void
    {
        $this->configureCommunity(['community' => ['access' => 'private']]);
        $this->organizer();
        $this->json('PUT', '/api/admin/events/1', ['settings' => ['access' => 'gated_open']], $this->h());
        $next = $this->json('POST', '/api/admin/events/1/close', ['confirm' => true], $this->h())['next'];
        self::assertSame(['hidden', 'gated_open'], [$next['state'], $next['settings']['access']]);

        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-10-01', '2026-10-20', 'open', '{"access": "gated_guests"}');
        static::getContainer()->get(EndedEvents::class)->run();
        self::assertSame('closed', $this->db()->fetchOne('SELECT state FROM event_occurrences WHERE id = ?', [$lights]));
        self::assertSame('gated_guests', json_decode((string) $this->db()->fetchOne("SELECT settings FROM event_occurrences WHERE type = 'holiday_lights' AND state = 'hidden'"), true)['access']);
    }

    public function testCommunityYamlSetsItThroughEventSettings(): void
    {
        $file = $this->yaml("event:\n  type: garage_sale\n  date: '2027-04-10'\n  ends_on: '2027-04-11'\n  settings:\n    access: public\n");
        static::getContainer()->get(CommunityBootstrapper::class)->bootstrap($file);
        self::assertSame('public', json_decode((string) $this->db()->fetchOne('SELECT settings FROM event_occurrences'), true)['access']);
        self::assertSame('public', $this->json('GET', '/api/community')['community']['access']);

        $this->db()->executeStatement('TRUNCATE community_settings, event_occurrences RESTART IDENTITY CASCADE');
        try {
            static::getContainer()->get(CommunityBootstrapper::class)->bootstrap($this->yaml("event:\n  type: garage_sale\n  date: '2027-04-10'\n  settings:\n    access: anyone\n"));
            self::fail('a bad value is refused');
        } catch (ConfigException $e) {
            self::assertStringStartsWith('event.settings.access: ', $e->errors[0]);
        }
        self::assertFalse($this->db()->fetchOne('SELECT 1 FROM event_occurrences'), 'nothing imported');
    }

    /**
     * The community's stored value is its own: what the community console edits and what the 0.9 image reads after an
     * image rollback. Saving anything from a page that shows a default event with its own access never writes that in.
     */
    public function testTheCommunitysStoredValueNeverTakesAnEventsAccess(): void
    {
        $this->configureCommunity(['community' => ['access' => 'gated_guests']]);
        $this->organizer();
        $stored = fn () => json_decode((string) $this->db()->fetchOne('SELECT settings FROM community_settings'), true)['community']['access'];
        $this->json('PUT', '/api/admin/events/1', ['settings' => ['access' => 'public']], $this->h());
        self::assertSame(200, $this->code());
        self::assertSame('gated_guests', $stored(), 'an event change mirrors for the 0.9 image without touching it');
        self::assertSame('gated_guests', $this->json('GET', '/api/admin/community')['settings']['community']['access'], 'the console edits the community\'s');

        $this->json('POST', '/api/admin/registration', ['enabled' => false], $this->h());
        self::assertSame(200, $this->code());
        self::assertSame('gated_guests', $stored(), 'the registration switch');
        $settings = $this->json('GET', '/api/admin/community')['settings'];
        $this->json('PUT', '/api/admin/community', ['settings' => $settings], $this->h());
        self::assertSame([200, 'gated_guests'], [$this->code(), $stored()], 'a community save');
        $this->json('POST', '/api/admin/events/1/close', ['confirm' => true], $this->h());
        self::assertSame('gated_guests', $stored(), 'closing');
        self::assertSame('public', static::getContainer()->get(EventLifecycle::class)->get(1)->settings['access']);
    }

    /** Coverage the owner asked to keep: a default event's hours edit shows in its season row. */
    public function testADefaultEventsHoursEditShowsInItsSeasonRow(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open', '{}');
        $this->organizer();
        $this->json('POST', "/api/admin/events/$lights/default", [], $this->h());
        self::assertSame(200, $this->code());
        self::assertStringContainsString('5–9 PM', $this->client->request('GET', '/')->filter('.schedule-row')->text());
        $r = $this->json('PUT', "/api/admin/events/$lights", ['default_end' => '22:30'], $this->h());
        self::assertSame([200, '22:30'], [$this->code(), $r['event']['default_end']]);
        $page = $this->client->request('GET', '/');
        self::assertSame('holiday_lights', $page->filter('html')->attr('data-event-type'));
        self::assertStringContainsString('5–10:30 PM', $page->filter('.schedule-row')->text(), 'the season row, at "/"');
        self::assertSame(['start' => '17:00', 'end' => '22:30'], $this->json('GET', '/api/event')['event']['default_hours']);
        $this->json('PUT', "/api/admin/events/$lights", ['default_start' => '18:00'], $this->h());
        self::assertStringContainsString('6–10:30 PM', $this->client->request('GET', "/?e=$lights")->filter('.schedule-row')->text(), 'and at its own link');
    }

    /** @return array{0:string, 1:string} the Rules sheet's access fact: title and text */
    private static function rulesAccess(Crawler $page): array
    {
        $fact = $page->filter('#infoModal .about-fact')->eq(1);

        return [$fact->filter('b')->text(), $fact->filter('p')->text()];
    }

    /** @return list<string> the About page's fact titles */
    private static function aboutFacts(Crawler $page): array
    {
        return $page->filter('.about-facts .about-fact b')->each(static fn (Crawler $b) => $b->text());
    }

    private function preview(int $id): Crawler
    {
        $this->organizer();
        $page = $this->client->request('GET', "/admin/events/$id/preview");
        self::assertSame(200, $this->code());

        return $page;
    }

    private function event(string $type, string $name, ?string $from, ?string $to, string $state, string $settings): int
    {
        return (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, settings, state, created_at, activated_at)
            VALUES (?, ?, ?, ?, '17:00', '21:00', CAST(? AS JSONB), ?, NOW(), CASE WHEN ? = 'open' THEN NOW() END) RETURNING id",
            [$type, $name, $from, $to, $settings, $state, $state]);
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

    private function yaml(string $event): string
    {
        $file = sys_get_temp_dir().'/community-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($file, "community:\n  id: bootville\n  name: Bootville\n  timezone: America/New_York\n".$event);

        return $file;
    }
}
