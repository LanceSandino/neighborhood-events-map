<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\DemoSeedCommand;
use App\Community\CommunityBootstrapper;
use App\Shared\RuntimeConfig;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @module tests/Integration/HolidayLightsTest
 * @description The Christmas lights event type end to end on the server (ROADMAP Stage 3): a lights community lists a
 *   home with its nights across the year end, the public projection (exactly the event keys plus status_date; strict
 *   details; no draft ever public), "off tonight" named only on its own night and never afterwards (no history leaks),
 *   done for the season, the public event's best night, quiet hours and route modes (driving only with a provider),
 *   strict input (unknown details, FM bounds, nights outside the season), resident text escaped on the page, My house
 *   never reaching another resident's home, and an organizer switching a Halloween community to Christmas lights:
 *   the draft preview shows the lights theme, words and filters, and after the switch the Halloween homes are
 *   archived and invisible while the branding still at Halloween's defaults follows the lights type.
 * @dependencies tests/Support/AppTestCase
 */
final class HolidayLightsTest extends AppTestCase
{
    private const SEASON = ['type' => 'holiday_lights', 'name' => 'Christmas Lights', 'date' => '2026-12-01', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03',
        'starts_at' => '18:00', 'ends_at' => '22:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = static::mockTime('2026-12-12 00:30:00 UTC'); // 7:30 PM on Dec 11 in America/New_York
    }

    /** @return array<string,mixed> a lights listing for some nights with the given details */
    private static function lights(array $details = [], array $nights = ['2026-12-11', '2026-12-12', '2026-12-31', '2027-01-01'], array $over = []): array
    {
        return $over + ['start' => '18:00', 'end' => '22:00', 'note' => 'Best from the corner.',
            'details' => $details + ['styles' => ['classic', 'inflatables'], 'viewing_mode' => 'walk_up_welcome', 'music_enabled' => true, 'fm_frequency' => '88.7', 'cocoa' => true],
            'windows' => array_map(static fn (string $d) => ['date' => $d, 'start' => '18:00', 'end' => '22:00'], $nights)];
    }

    /** The whole response body of a page (the crawler's html() leaves out the <html> element). */
    private function page(string $uri): string
    {
        $this->client->request('GET', $uri);

        return (string) $this->client->getResponse()->getContent();
    }

    private function listHome(string $house, string $email, array $listing): int
    {
        $id = $this->addAddress($house, 'Pumpkin Ln', 40.004 + (int) $house / 100000, -75.005, 'west');
        $this->confirm($this->requestLink($email, ['address' => $this->byId($id), 'listing' => $listing]));
        self::assertSame(303, $this->code());
        $this->client->getCookieJar()->clear();

        return $id;
    }

    public function testALightsHomeIsPublicWithItsNightsAndExactlyTheEventKeys(): void
    {
        $this->configureCommunity(['event' => self::SEASON]);
        $id = $this->listHome('11', 'alice@example.test', self::lights());
        $homes = $this->json('GET', '/api/homes')['homes'];
        self::assertCount(1, $homes);
        $h = $homes[0];
        self::assertSame(['id', 'address', 'zone', 'lat', 'lon', 'start', 'end', 'note', 'status', 'updated_at', 'event_id', 'event_type', 'details', 'windows', 'display_status', 'status_date'],
            array_keys($h), 'the shared keys, no Halloween flat fields, the event keys and status_date');
        self::assertSame(['holiday_lights', 'lights_on', 'active', null, '18:00', '22:00'], [$h['event_type'], $h['display_status'], $h['status'], $h['status_date'], $h['start'], $h['end']]);
        self::assertSame(['styles' => ['classic', 'inflatables'], 'flashing_warning' => false, 'music_enabled' => true, 'fm_frequency' => '88.7',
            'viewing_mode' => 'walk_up_welcome', 'treats' => false, 'cocoa' => true], $h['details']);
        self::assertSame(['2026-12-11', '2026-12-12', '2026-12-31', '2027-01-01'], array_column($h['windows'], 'date'), 'nights across the year end');
        self::assertSame($h, $this->json('GET', "/api/homes/$id")['home']);

        $event = $this->json('GET', '/api/event')['event'];
        self::assertSame(['holiday_lights', 'nightly_range', '2026-12-01', '2027-01-03', ['start' => '18:00', 'end' => '22:00']],
            [$event['type'], $event['schedule_kind'], $event['starts_on'], $event['ends_on'], $event['default_hours']]);
        self::assertSame(['driveBy', 'noFlashing', 'music', 'walkUp', 'openTonight'], array_column($event['filters'], 'key'));
        self::assertSame(['best_night' => null, 'quiet_hours' => '', 'route_modes' => ['walking']], array_intersect_key($event, ['best_night' => 1, 'quiet_hours' => 1, 'route_modes' => 1]),
            'no drive-by tour without a provider and the event\'s choice');
        self::assertSame('timezone', array_keys($event)[14], 'the shared keys keep their order; the type\'s keys follow');
        self::assertSame('Your lights tour', trim(str_replace('✨', '', $event['copy']['route_title'])));
        $c = $this->json('GET', '/api/community');
        self::assertSame(['Holiday nights.', 'Bright lights.', '#38597F', '#CE9D3D'], [$c['branding']['headline'], $c['branding']['headline_accent'], $c['branding']['primary_color'], $c['branding']['accent_color']]);
        self::assertSame(['homes' => 1, 'music' => 1, 'drive_by' => 0, 'no_flashing' => 1], array_intersect_key($c['stats'], ['homes' => 1, 'music' => 1, 'drive_by' => 1, 'no_flashing' => 1]));
        self::assertSame('1', (string) $this->db()->fetchOne("SELECT COUNT(*) FROM event_occurrences WHERE type = 'holiday_lights' AND state = 'active'"));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM listings'), 'lights listings live only in the event tables (no legacy row)');

        $html = $this->page('/');
        self::assertStringContainsString('<html lang="en" data-event-type="holiday_lights">', $html);
        self::assertStringContainsString('css/events/holiday_lights.css', $html);
        self::assertStringContainsString('Dec 1, 2026 – Jan 3, 2027', $html, 'the season row');
        self::assertStringContainsString('Most homes: 6–10 PM · Times vary by house', $html);
        self::assertStringContainsString('>Tonight</span>', $html, 'the season pill on Dec 11');
        self::assertStringContainsString('✨ Dec 1 – Jan 3</a>', $html, 'the top bar badge');
        self::assertStringContainsString('🎵 Music 88.7 FM', $html);
        self::assertStringContainsString('🕯️ No flashing reported', $html);
        self::assertStringNotContainsString('safe for', strtolower(strip_tags($html)), 'no safety promise');
    }

    public function testOffTonightIsNamedOnlyOnItsOwnNightAndDoneForTheSeasonNeverShowsADate(): void
    {
        $this->configureCommunity(['event' => self::SEASON]);
        $id = $this->listHome('11', 'alice@example.test', self::lights());
        $this->signIn('alice@example.test');
        $csrf = $this->csrf();
        $r = $this->json('PATCH', "/api/me/homes/$id", ['status' => 'off_tonight'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame('2026-12-11', $this->db()->fetchOne('SELECT status_date FROM event_listings'), 'the community-local night (7:30 PM in New York, already Dec 12 in UTC)');
        $h = $this->json('GET', "/api/homes/$id")['home'];
        self::assertSame(['off_tonight', '2026-12-11', 'off_tonight'], [$h['status'], $h['status_date'], $h['display_status']]);
        self::assertCount(4, $h['windows'], 'off tonight keeps the season');

        $this->clock->modify('+1 day'); // Dec 12, 7:30 PM: the next night
        $h = $this->json('GET', "/api/homes/$id")['home'];
        self::assertSame(['active', null, 'lights_on'], [$h['status'], $h['status_date'], $h['display_status']], 'an expired night shows no date (no history)');

        $this->client->getCookieJar()->clear(); // a day later the session is long over
        $this->signIn('alice@example.test');
        $csrf = $this->csrf();
        $this->json('PATCH', "/api/me/homes/$id", ['status' => 'finished'], ['X-CSRF-Token' => $csrf]);
        $h = $this->json('GET', "/api/homes/$id")['home'];
        self::assertSame(['finished', null, 'finished'], [$h['status'], $h['status_date'], $h['display_status']]);
        $this->clock->modify('+20 minutes');
        $this->json('PATCH', "/api/me/homes/$id", ['status' => 'active'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        $this->clock->modify('+160 minutes'); // 10:30 PM: after tonight's window
        self::assertSame('not_tonight', $this->json('GET', "/api/homes/$id")['home']['display_status'], 'never "lights on" outside a window');
    }

    public function testInputIsStrictAndResidentTextIsEscaped(): void
    {
        $this->configureCommunity(['event' => self::SEASON]);
        $id = $this->addAddress('11', 'Pumpkin Ln', 40.004, -75.005, 'west');
        $ask = fn (array $listing) => $this->json('POST', '/api/auth/request-link', ['email' => 'alice@example.test', 'pow' => $this->solvePow('claim'), 'claim' => ['address' => $this->byId($id), 'listing' => $listing]]);
        $r = $ask(self::lights(['glitter' => true, 'photo_url' => 'x']));
        self::assertSame(422, $this->code());
        self::assertSame(['details.glitter', 'details.photo_url'], array_keys($r['error']['fields']));
        $r = $ask(self::lights(['music_enabled' => false]));
        self::assertSame('Turn on music to add its radio station, or leave the station empty.', $r['error']['fields']['fm_frequency'] ?? null);
        $r = $ask(self::lights(['fm_frequency' => '108.1']));
        self::assertArrayHasKey('fm_frequency', $r['error']['fields']);
        $r = $ask(self::lights([], ['2026-11-30', '2026-12-01']));
        self::assertSame('Choose a day from 2026-12-01 to 2027-01-03.', $r['error']['fields']['windows.0'] ?? null, 'nights stay inside the season');
        $r = $ask(self::lights([], ['2026-12-05'], ['candy' => true]));
        self::assertSame('Unknown field.', $r['error']['fields']['candy'] ?? null, 'Halloween\'s flat fields are not lights fields');
        $r = $ask(['start' => '18:00', 'end' => '22:00', 'details' => ['styles' => ['classic']]]);
        self::assertSame(422, $this->code(), 'a lights listing names its nights');
        self::assertArrayHasKey('windows', $r['error']['fields']);

        $evil = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->confirm($this->requestLink('alice@example.test', ['address' => $this->byId($id), 'listing' => self::lights([], ['2026-12-11'], ['note' => $evil])]));
        $this->client->getCookieJar()->clear();
        self::assertSame($evil, $this->json('GET', "/api/homes/$id")['home']['note'], 'stored as typed, escaped on output');
        $html = $this->page('/');
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'the server-rendered card');
    }

    public function testMyHouseNeverReachesAnotherResidentsLightsHome(): void
    {
        $this->configureCommunity(['event' => self::SEASON]);
        $alice = $this->listHome('11', 'alice@example.test', self::lights());
        $bobs = $this->listHome('13', 'bob@example.test', self::lights(['viewing_mode' => 'drive_by_only']));
        $this->signIn('bob@example.test');
        $csrf = $this->csrf();
        self::assertSame([$bobs], array_column($this->json('GET', '/api/me')['homes'], 'id'));
        foreach ([['PATCH', "/api/me/homes/$alice", ['status' => 'finished']], ['POST', "/api/me/homes/$alice/listing", self::lights()],
            ['DELETE', "/api/me/homes/$alice", ['confirm' => 'RELEASE']]] as [$method, $uri, $body]) {
            $this->json($method, $uri, $body, ['X-CSRF-Token' => $csrf]);
            self::assertSame(404, $this->code(), "$method $uri");
        }
        self::assertSame('active', $this->db()->fetchOne('SELECT status FROM event_listings WHERE address_id = ?', [$alice]));
        $r = $this->json('PATCH', "/api/me/homes/$bobs", ['status' => 'off_tonight', 'details' => ['styles' => ['walkthrough'], 'viewing_mode' => 'drive_by_only']], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame(['walkthrough'], $r['home']['listing']['details']['styles']);
        self::assertSame(['2026-12-11', '2026-12-12', '2026-12-31', '2027-01-01'], array_column($r['home']['listing']['windows'], 'date'), 'a partial save keeps the nights');
    }

    public function testTheFictionalLightsDemoSeedsItsNightsAndAFixedOffTonight(): void
    {
        $result = static::getContainer()->get(CommunityBootstrapper::class)->bootstrap(\dirname(__DIR__, 2).'/examples/demo-lights/community.yaml', false, true);
        self::assertSame('imported', $result['status']);
        $this->repo()->reset();
        $demo = new RuntimeConfig('http://localhost', 'Demo <demo@example.test>', 'demo@example.test', str_repeat('s', 32), '', '', 8, 120, [], 'none', '', '', true);
        $ctor = (new \ReflectionClass(DemoSeedCommand::class))->getConstructor();
        \assert(null !== $ctor);
        $args = array_map(static function (\ReflectionParameter $p) use ($demo): object {
            $type = $p->getType();
            \assert($type instanceof \ReflectionNamedType);

            return RuntimeConfig::class === $type->getName() ? $demo : static::getContainer()->get($type->getName());
        }, $ctor->getParameters());
        $tester = new CommandTester((new \ReflectionClass(DemoSeedCommand::class))->newInstanceArgs($args));
        self::assertSame(0, $tester->execute(['file' => 'examples/demo-lights/homes.json']), $tester->getDisplay());
        self::assertStringContainsString('Seeded 10 FICTIONAL demo homes.', $tester->getDisplay());
        $this->repo()->reset();
        $homes = array_column($this->json('GET', '/api/homes')['homes'], null, 'address');
        self::assertCount(10, $homes);
        self::assertSame([34, 20, 12], [\count($homes['12 Evergreen Ct']['windows']), \count($homes['36 Starlight Way']['windows']), \count($homes['48 Holly Dr']['windows'])], 'every night, a range, a list of nights');
        self::assertSame(['off_tonight', '2026-12-12'], [$homes['60 Evergreen Ct']['status'], $homes['60 Evergreen Ct']['status_date']], 'a fixed fictional night, later than the test clock\'s Dec 11');
        self::assertSame('finished', $homes['84 Starlight Way']['status']);
        $c = $this->json('GET', '/api/community');
        self::assertSame(['Holiday nights.', 'maple-hollow-lights-demo', '#38597F'], [$c['branding']['headline'], $c['community']['id'], $c['branding']['primary_color']], 'no branding block: the lights defaults');
    }

    public function testAnOrganizerSwitchesHalloweenToChristmasLights(): void
    {
        $this->clock = static::mockTime('2026-10-31 22:00:00 UTC');
        $this->configureCommunity();
        $halloween = $this->listHome('11', 'alice@example.test', self::listing(['note' => 'Full-size bars']));
        self::assertCount(1, $this->json('GET', '/api/homes')['homes']);
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, now())");
        $this->signIn('org@example.test');
        $h = ['X-CSRF-Token' => $this->csrf()];

        $bad = $this->json('POST', '/api/admin/events', ['type' => 'holiday_lights', 'starts_on' => '2026-12-01', 'ends_on' => '2027-02-15', 'settings' => ['best_night' => '2027-03-01', 'quiet_hours' => str_repeat('q', 121), 'route_modes' => ['walking', 'flying']]], $h);
        self::assertSame(422, $this->code());
        self::assertSame(['ends_on', 'settings.best_night', 'settings.quiet_hours', 'settings.route_modes'], array_keys($bad['error']['fields']), 'at most 62 nights; the type\'s own settings are checked');
        $draft = $this->json('POST', '/api/admin/events', ['type' => 'holiday_lights', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03', 'default_start' => '18:00', 'default_end' => '22:00',
            'settings' => ['rules' => ['Park on one side of the street'], 'best_night' => '2026-12-19', 'quiet_hours' => 'Quiet after 10 PM', 'route_modes' => ['walking', 'driving'], 'max_days' => 62]], $h)['event'];
        self::assertSame(201, $this->code(), json_encode($draft));
        self::assertSame(['Christmas Lights', '2026-12-01', '2027-01-03', '2026-12-19'], [$draft['name'], $draft['starts_on'], $draft['ends_on'], $draft['settings']['best_night']]);
        self::assertStringNotContainsString('Christmas Lights', (string) json_encode($this->json('GET', '/api/event')), 'a draft is never public');

        $html = $this->page('/admin/events/'.$draft['id'].'/preview');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('data-event-type="holiday_lights"', $html, 'the draft type\'s theme');
        self::assertStringContainsString('Holiday nights.', $html, 'the draft type\'s default headline (the community kept Halloween\'s default)');
        self::assertStringContainsString('data-filter="driveBy"', $html, 'the draft type\'s filters');
        self::assertStringNotContainsString('data-filter="candy"', $html, 'not Halloween\'s');
        self::assertStringContainsString('Best night for a neighborhood walk: Dec 19, 2026', $html);
        self::assertStringNotContainsString('11 Pumpkin Ln', $html);

        $active = $this->json('GET', '/api/admin/events')['active_id'];
        $this->json('POST', '/api/admin/events/'.$draft['id'].'/activate', ['confirm' => true, 'replaces' => $active], $h);
        self::assertSame(200, $this->code());
        self::assertSame([], $this->json('GET', '/api/homes')['homes'], 'the Halloween homes are archived and invisible');
        $this->json('GET', "/api/homes/$halloween");
        self::assertSame(404, $this->code());
        $event = $this->json('GET', '/api/event')['event'];
        self::assertSame(['holiday_lights', '2026-12-19', 'Quiet after 10 PM', ['walking']], [$event['type'], $event['best_night'], $event['quiet_hours'], $event['route_modes']],
            'the event offers driving, but no provider is enabled in the settings: no drive-by tour');
        self::assertStringContainsString('--brand:#38597F', $this->page('/theme.css'));
        self::assertFalse((bool) $this->db()->fetchOne('SELECT published FROM listings WHERE address_id = ?', [$halloween]), 'the previous release hides it too');
        $about = $this->page('/about');
        self::assertStringContainsString('Park on one side of the street', $about);
        self::assertStringContainsString('Quiet hours: Quiet after 10 PM', $about);
        self::assertStringContainsString('Viewing etiquette', $about);

        // The organizer enables a provider: the drive-by tour is offered.
        $s = $this->json('GET', '/api/admin/community')['settings'];
        self::assertSame('holiday_lights', $s['event']['type']);
        $s['routing']['provider_enabled'] = true;
        $this->json('PUT', '/api/admin/community', ['settings' => $s], $h);
        self::assertSame(200, $this->code());
        self::assertSame(['walking', 'driving'], $this->json('GET', '/api/event')['event']['route_modes']);

        // The resident keeps the claim and lists the home for the lights from an empty form.
        $this->client->getCookieJar()->clear();
        $this->signIn('alice@example.test');
        $csrf = $this->csrf();
        $me = $this->json('GET', '/api/me');
        self::assertSame(['holiday_lights', null, true], [$me['event_type'], $me['homes'][0]['listing'], $me['homes'][0]['can_list']]);
        $r = $this->json('POST', "/api/me/homes/$halloween/listing", self::lights([], ['2026-12-19']), ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertSame('Best from the corner.', $r['home']['listing']['note'], 'only what was sent now');
        self::assertSame('Full-size bars', $this->db()->fetchOne("SELECT el.note FROM event_listings el JOIN event_occurrences o ON o.id = el.event_id WHERE o.type = 'halloween'"), 'last season stays with last season');
    }
}
