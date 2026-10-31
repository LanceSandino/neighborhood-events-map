<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\DemoSeedCommand;
use App\Community\CommunityBootstrapper;
use App\Shared\RuntimeConfig;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @module tests/Integration/MultiEventTest
 * @description Several published events per community (ADR 0011, D-20261031-37 to -43): what visitors see of each state
 *   (only open events show homes; drafts and archived events are never described, and `e=` naming one answers 404
 *   like an unknown id while a page shows the default event), the default event and its fallback when archived,
 *   listings only for upcoming or open events with dates (the client names the event; another type's details or a
 *   draft are refused), "Use last year's details" from the resident's own previous listing of the same home and type
 *   only, closing for each type (next year's tentative dates or dates to be announced; what carries over), opening
 *   rules, the Community → Event block as the default event, and the fictional demo that has every public state.
 * @dependencies tests/Support/AppTestCase
 */
final class MultiEventTest extends AppTestCase
{
    private string $csrf = '';

    public function testEachStateShowsVisitorsOnlyWhatItMay(): void
    {
        $this->configureCommunity();
        $open = 1;
        $home = $this->listHome('11', 'alice@example.test');
        $ids = [];
        foreach (['draft' => ['halloween', '2027-10-31', '2027-10-31'], 'upcoming' => ['holiday_lights', '2026-12-01', '2027-01-03'], 'tbd' => ['garage_sale', null, null],
            'closed' => ['halloween', '2025-10-31', '2025-10-31'], 'archived' => ['halloween', '2024-10-31', '2024-10-31']] as $name => [$type, $from, $to]) {
            $state = 'tbd' === $name ? 'upcoming' : $name;
            $ids[$name] = $this->event($type, ucfirst($name).' event', $from, $to, $state);
            $this->listFor($ids[$name], $home, 'Note of the '.$name.' event'); // stored listings of every state
        }

        $events = $this->json('GET', '/api/events')['events'];
        self::assertSame([[$open, 'open', true], [$ids['closed'], 'closed', false], [$ids['upcoming'], 'upcoming', false], [$ids['tbd'], 'upcoming', false]],
            array_map(static fn (array $e) => [$e['id'], $e['state'], $e['is_default']], $events), 'the default first, then by date (to be announced last); no draft, no archived');
        self::assertSame(['id', 'type', 'name', 'state', 'starts_on', 'ends_on', 'dates_tentative', 'is_default'], array_keys($events[0]));
        self::assertSame(['id', 'type', 'name', 'state', 'starts_on', 'ends_on', 'dates_tentative', 'is_default', 'closing_message'], array_keys($events[1]), 'a closed event adds its thank-you text');
        self::assertSame([null, null], [$events[3]['starts_on'], $events[3]['ends_on']]);
        $list = (string) json_encode($events);
        self::assertStringNotContainsString('Draft event', $list);
        self::assertStringNotContainsString('Archived event', $list);

        self::assertSame([$home], array_column($this->json('GET', '/api/homes')['homes'], 'id'), 'the open default event shows its homes');
        foreach (['upcoming', 'tbd', 'closed'] as $name) {
            $r = $this->json('GET', '/api/homes?e='.$ids[$name]);
            self::assertSame([200, [], $ids[$name]], [$this->code(), $r['homes'], $r['event_id']], "$name: no homes");
            $this->json('GET', "/api/homes/$home?e=".$ids[$name]);
            self::assertSame(404, $this->code(), "$name: not by id either");
            self::assertSame($name === 'tbd' ? 'upcoming' : $name, $this->json('GET', '/api/event?e='.$ids[$name])['event']['state']);
            $page = (string) $this->client->request('GET', '/?e='.$ids[$name])->html();
            self::assertStringNotContainsString('Note of the', $page, "$name: no listing text on its page");
            self::assertStringNotContainsString('home.js', $page);
        }
        foreach (['draft' => $ids['draft'], 'archived' => $ids['archived'], 'unknown' => 999, 'zero' => '0', 'text' => 'abc', 'list' => '1,2'] as $why => $e) {
            foreach (['/api/event', '/api/community', '/api/homes', "/api/homes/$home"] as $uri) {
                $r = $this->json('GET', $uri.'?e='.$e);
                self::assertSame(404, $this->code(), "$why on $uri");
                self::assertSame('not_found', $r['error']['code'] ?? null);
            }
            $this->json('POST', '/api/routes/plan?e='.$e, ['stops' => [$home, $home]]);
            self::assertSame(404, $this->code(), "$why: route plans too");
            $page = (string) $this->client->request('GET', '/?e='.$e)->html();
            self::assertSame(200, $this->code(), "$why: a page shows the default event");
            self::assertSame([$home], array_column($this->json('GET', '/api/homes')['homes'], 'id'), 'the default is unchanged');
            self::assertStringNotContainsString('Draft event', $page);
            self::assertStringNotContainsString('Archived event', $page);
        }
        $closedPage = $this->client->request('GET', '/?e='.$ids['closed']);
        self::assertStringContainsString('Closed event 2025 was a success — thank you! Come back next year (Oct 31, 2026).', $closedPage->filter('.event-status-message')->text(),
            'the Halloween that follows is the open one');
        $upcomingPage = $this->client->request('GET', '/?e='.$ids['upcoming']);
        self::assertSame('holiday_lights', $upcomingPage->filter('html')->attr('data-event-type'), 'the theme follows the selected event');
        self::assertSame('Dec 1, 2026 – Jan 3, 2027', $upcomingPage->filter('#eventStatusTitle')->text());
        self::assertCount(1, $upcomingPage->filter('.event-switch a[aria-current="page"][href="/?e='.$ids['upcoming'].'"]'), 'the switcher marks the page\'s event');
        self::assertStringContainsString('Dates to be announced', $this->client->request('GET', '/?e='.$ids['tbd'])->filter('#eventStatusTitle')->text());
        $this->client->request('GET', '/theme.css?e='.$ids['upcoming']);
        self::assertStringContainsString('--brand:#38597F', (string) $this->client->getResponse()->getContent(), 'the brand colours follow the selected event\'s type');
    }

    public function testTheDefaultEventIsWhatSlashShowsAndPassesOnWhenArchived(): void
    {
        $this->configureCommunity();
        $this->organizer();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        $closed = $this->event('garage_sale', 'Spring Sale', '2026-04-11', '2026-04-11', 'closed');
        $draft = $this->event('halloween', 'Next Halloween', '2027-10-31', '2027-10-31', 'draft');
        self::assertSame('halloween', $this->client->request('GET', '/')->filter('html')->attr('data-event-type'));
        $r = $this->json('POST', "/api/admin/events/$draft/default", [], $this->h());
        self::assertSame([409, 'not_public'], [$this->code(), $r['error']['code']], 'a draft is never the default');
        $this->json('POST', "/api/admin/events/$lights/default", [], $this->h());
        self::assertSame(200, $this->code());
        $this->client->getCookieJar()->clear();
        self::assertSame('holiday_lights', $this->client->request('GET', '/')->filter('html')->attr('data-event-type'), '"/" shows the default event');
        self::assertSame($lights, $this->json('GET', '/api/event')['event']['id']);
        self::assertSame([$lights], array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM event_occurrences WHERE is_default')));

        $this->organizer();
        $this->json('POST', "/api/admin/events/$lights/archive", ['confirm' => true], $this->h());
        self::assertSame([1], array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM event_occurrences WHERE is_default')), 'the default passes to the newest open event');
        $this->json('POST', '/api/admin/events/1/archive', ['confirm' => true], $this->h());
        self::assertSame([$closed], array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM event_occurrences WHERE is_default')), 'no open event: the newest public one');
        self::assertSame(['closed', $closed], [$this->json('GET', '/api/event')['event']['state'], $this->json('GET', '/api/event')['event']['id']]);
        $this->json('POST', "/api/admin/events/$closed/archive", ['confirm' => true], $this->h());
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_occurrences WHERE is_default'), 'nothing public: no default');
        self::assertFalse($this->json('GET', '/api/event')['event']['active']);
        self::assertSame([], $this->json('GET', '/api/events')['events']);
        $this->json('POST', "/api/admin/events/$draft/announce", ['confirm' => true], $this->h());
        self::assertSame([$draft], array_map('intval', $this->db()->fetchFirstColumn('SELECT id FROM event_occurrences WHERE is_default')), 'the next public event becomes the default');
    }

    public function testListingsGoToTheNamedUpcomingOrOpenEventOnly(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'upcoming');
        $tbd = $this->event('garage_sale', 'Rummage Sale', null, null, 'upcoming');
        $closed = $this->event('halloween', 'Halloween 2025', '2025-10-31', '2025-10-31', 'closed');
        $draft = $this->event('garage_sale', 'Draft Sale', '2027-04-10', '2027-04-11', 'draft');
        $a = $this->addAddress('21', 'Pumpkin Ln', 40.0042, -75.005, 'west');
        $lightsListing = ['details' => ['styles' => ['classic']], 'windows' => [['date' => '2026-12-19', 'start' => '18:00', 'end' => '21:00']], 'note' => 'Warm white'];

        // A claim request names its event: the listing waits there (an upcoming event shows no homes yet).
        $token = $this->requestLinkFor('a@example.test', $lightsListing, $a, $lights);
        $this->confirm($token);
        self::assertSame([[$lights, 'Warm white']], array_map(static fn (array $r) => [(int) $r['event_id'], $r['note']], $this->db()->fetchAllAssociative('SELECT event_id, note FROM event_listings')));
        self::assertSame([], $this->json('GET', '/api/homes?e='.$lights)['homes']);
        $this->client->getCookieJar()->clear();

        $b = $this->addAddress('23', 'Pumpkin Ln', 40.0043, -75.005, 'west');
        foreach (['closed' => [$closed, 403], 'to be announced' => [$tbd, 403], 'draft' => [$draft, 404]] as $why => [$event, $code]) {
            $r = $this->json('POST', '/api/auth/request-link?e='.$event, ['email' => 'b@example.test', 'pow' => $this->solvePow('claim'), 'claim' => ['address' => $this->byId($b), 'listing' => self::listing()]]);
            self::assertSame($code, $this->code(), $why.': '.json_encode($r));
        }
        $r = $this->json('POST', '/api/auth/request-link?e='.$lights, ['email' => 'b@example.test', 'pow' => $this->solvePow('claim'), 'claim' => ['address' => $this->byId($b), 'listing' => self::listing()]]);
        self::assertSame(422, $this->code(), 'the named event\'s type checks the details');
        self::assertSame('Unknown field.', $r['error']['fields']['candy'] ?? null);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings'));

        // A signed-in resident lists the claimed home for another event by naming it; the claim is address-scoped.
        $this->signIn('a@example.test');
        $csrf = $this->csrf();
        $this->json('POST', "/api/me/homes/$a/listing?e=$closed", ['details' => ['candy' => true]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(403, $this->code(), 'closed');
        $this->json('POST', "/api/me/homes/$a/listing?e=$draft", ['details' => ['categories' => ['toys']]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(404, $this->code(), 'a draft is like an unknown event');
        $r = $this->json('POST', "/api/me/homes/$a/listing", ['details' => ['candy' => true], 'note' => 'Halloween too'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), 'without e: the default event — '.json_encode($r));
        self::assertSame([[1, 'Halloween too'], [$lights, 'Warm white']], array_map(static fn (array $r) => [(int) $r['event_id'], $r['note']], $this->db()->fetchAllAssociative('SELECT event_id, note FROM event_listings ORDER BY event_id')));
        $me = $this->json('GET', "/api/me?e=$lights");
        self::assertSame(['Warm white', 'upcoming', true], [$me['homes'][0]['listing']['note'], $me['event']['state'], $me['event']['accepts_listings']]);
        $r = $this->json('PATCH', "/api/me/homes/$a?e=$lights", ['details' => ['candy' => true]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code(), 'the lights listing takes only lights details');
        $this->json('PATCH', "/api/me/homes/$a?e=$lights", ['note' => 'Brighter this year'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        self::assertSame('Halloween too', $this->db()->fetchOne('SELECT note FROM event_listings WHERE event_id = 1'), 'the other event\'s listing is untouched');
    }

    public function testUseLastYearsDetailsCopiesOnlyTheResidentsOwnPreviousListingOfTheSameHomeAndType(): void
    {
        $this->configureCommunity();
        $mine = $this->listHome('31', 'me@example.test', self::listing(['note' => 'Last year: full-size bars', 'nonfood' => true, 'start' => '18:00', 'end' => '20:00']));
        $theirs = $this->listHome('33', 'them@example.test', self::listing(['note' => 'Their note']));
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        $this->listFor($lights, $mine, 'Lights note');
        $this->organizer();
        $next = $this->json('POST', '/api/admin/events/1/close', ['confirm' => true], $this->h())['next']['id'];
        $before = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings');

        $this->client->getCookieJar()->clear();
        $this->signIn('me@example.test');
        $me = $this->json('GET', "/api/me?e=$next");
        self::assertTrue($me['homes'][0]['can_copy_previous']);
        $r = $this->json('GET', "/api/me/homes/$mine/previous?e=$next");
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame(['Last year: full-size bars', '18:00', '20:00', true, true, 1], [$r['listing']['note'], $r['listing']['start'], $r['listing']['end'],
            $r['listing']['details']['candy'], $r['listing']['details']['nonfood'], $r['listing']['from_event_id']], 'the same home\'s Halloween listing, not the lights one');
        self::assertSame(['details', 'note', 'start', 'end', 'from_event_id'], array_keys($r['listing']), 'details, note and hours only: never days, status or visibility');
        self::assertSame($before, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings'), 'nothing is saved or published by copying');
        $this->json('GET', "/api/me/homes/$theirs/previous?e=$next");
        self::assertSame(404, $this->code(), 'never another resident\'s home');
        $this->json('GET', "/api/me/homes/$mine/previous?e=$lights");
        self::assertSame(404, $this->code(), 'no earlier event of the lights type: nothing to copy');
        $this->json('GET', "/api/me/homes/$mine/previous?e=999");
        self::assertSame(404, $this->code());
        $this->client->getCookieJar()->clear();
        $this->json('GET', "/api/me/homes/$mine/previous?e=$next");
        self::assertSame(401, $this->code(), 'residents only');
    }

    public function testClosingEachTypeCreatesNextYearsEventWithWhatLasts(): void
    {
        $this->configureCommunity();
        $this->organizer();
        $lights = $this->json('POST', '/api/admin/events', ['type' => 'holiday_lights', 'name' => 'Lights 2026', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03',
            'default_start' => '18:00', 'default_end' => '22:00', 'settings' => ['best_night' => '2026-12-19', 'quiet_hours' => 'Quiet after 10 PM', 'rules' => ['Drive slowly'], 'closing_message' => 'Thanks for the lights!']], $this->h())['event']['id'];
        $sale = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'name' => 'Rummage Sale', 'starts_on' => null, 'ends_on' => null, 'default_start' => '08:00', 'default_end' => '14:00',
            'settings' => ['categories' => ['toys', 'tools'], 'early_bird_time' => '07:30']], $this->h())['event'];
        self::assertSame([null, null, false, ['categories' => ['toys', 'tools'], 'early_bird_time' => '07:30']], [$sale['starts_on'], $sale['ends_on'], $sale['dates_tentative'], $sale['settings']], 'dates to be announced');
        $r = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'starts_on' => null, 'ends_on' => null, 'settings' => ['days' => ['2027-04-10']]], $this->h());
        self::assertSame('Set the event\'s dates first.', $r['error']['fields']['settings.days'] ?? null, 'no sale days without dates');
        $r = $this->json('POST', '/api/admin/events/'.$sale['id'].'/open', ['confirm' => true], $this->h());
        self::assertSame([409, 'dates_tbd'], [$this->code(), $r['error']['code']], 'a sale to be announced cannot open');
        $this->json('POST', '/api/admin/events/'.$sale['id'].'/announce', ['confirm' => true], $this->h());
        self::assertSame(200, $this->code());
        $r = $this->json('PUT', '/api/admin/events/'.$sale['id'], ['starts_on' => '2027-04-10', 'ends_on' => '2027-04-11', 'dates_tentative' => true], $this->h());
        self::assertSame([200, ['2027-04-10', '2027-04-11'], true], [$this->code(), $r['event']['settings']['days'] ?? null, $r['event']['dates_tentative']], 'dates set: a weekend sale takes its days');
        foreach ([$lights, $sale['id']] as $id) {
            $r = $this->json('POST', "/api/admin/events/$id/open", ['confirm' => true], $this->h());
            self::assertSame([200, 'open', false], [$this->code(), $r['event']['state'], $r['event']['dates_tentative']], 'opening confirms the dates');
        }

        $r = $this->json('POST', "/api/admin/events/$lights/close", ['confirm' => true], $this->h());
        self::assertSame([200, 'Thanks for the lights!'], [$this->code(), $r['event']['settings']['closing_message']]);
        self::assertSame(['Lights 2027', '2027-12-01', '2028-01-03', true, 'upcoming', '18:00', ['quiet_hours' => 'Quiet after 10 PM', 'rules' => ['Drive slowly']]],
            [$r['next']['name'], $r['next']['starts_on'], $r['next']['ends_on'], $r['next']['dates_tentative'], $r['next']['state'], $r['next']['default_start'], self::sorted($r['next']['settings'])],
            'the same nights a year later; the best night and the thank-you text stay with the closed season');
        $r = $this->json('POST', '/api/admin/events/'.$sale['id'].'/close', ['confirm' => true], $this->h());
        self::assertSame(['Rummage Sale', null, null, false, ['categories' => ['toys', 'tools'], 'early_bird_time' => '07:30']],
            [$r['next']['name'], $r['next']['starts_on'], $r['next']['ends_on'], $r['next']['dates_tentative'], self::sorted($r['next']['settings'])], 'the next sale\'s dates are to be announced; its days go');
        $this->client->getCookieJar()->clear();
        $sale2 = $r['next']['id'];
        $page = $this->client->request('GET', '/?e='.$r['event']['id']);
        self::assertStringContainsString('Rummage Sale 2027 was a success — thank you! The next sale: dates to be announced.', $page->filter('.event-status-message')->text());
        self::assertCount(1, $page->filter('a[href="/me?e='.$sale2.'"]'), 'residents get ready for the next sale from here');
    }

    public function testTheCommunityEventBlockIsTheDefaultEvent(): void
    {
        $this->configureCommunity();
        $lights = $this->event('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', 'open');
        $this->organizer();
        $this->json('POST', "/api/admin/events/$lights/default", [], $this->h());
        $s = $this->json('GET', '/api/admin/community')['settings'];
        self::assertSame(['Christmas Lights', 'holiday_lights', '2026-12-01', '2027-01-03'], [$s['event']['name'], $s['event']['type'], $s['event']['starts_on'], $s['event']['ends_on']]);
        self::assertSame(['id' => $lights, 'name' => 'Christmas Lights', 'type' => 'holiday_lights'], array_intersect_key($this->json('GET', '/api/admin/overview')['event'], ['id' => 1, 'name' => 1, 'type' => 1]));
        $s['event']['name'] = 'Lights on Maple';
        $this->json('PUT', '/api/admin/community', ['settings' => $s], $this->h());
        self::assertSame(200, $this->code());
        self::assertSame([['Halloween'], ['Lights on Maple']], $this->db()->fetchAllNumeric('SELECT name FROM event_occurrences ORDER BY id'), 'the save goes to the default event only');
        self::assertSame('2026-10-31', json_decode((string) $this->db()->fetchOne('SELECT settings FROM community_settings'), true)['event']['date'],
            'the stored block mirrors the open Halloween for the previous release');
    }

    /**
     * The fictional lights demo (examples/demo-lights, D-20261031-44) has every public state: the lights open and the
     * default, a Halloween that has ended with its thank-you message and next year's upcoming one (tentative), and a
     * rummage sale whose dates are to be announced. Seeding again changes nothing; next year's Halloween comes back if an
     * organizer archived it on the demo stack.
     */
    public function testTheFictionalDemoSeedsEveryPublicState(): void
    {
        $this->clock = static::mockTime('2026-12-19 01:00:00 UTC');
        self::assertSame('imported', static::getContainer()->get(CommunityBootstrapper::class)->bootstrap(\dirname(__DIR__, 2).'/examples/demo-lights/community.yaml', false, true)['status']);
        $this->repo()->reset();
        $out = $this->seed();
        self::assertStringContainsString('Added the FICTIONAL demo event "Halloween" (3 homes, closed).', $out);
        self::assertStringContainsString('Added the FICTIONAL demo event "Community Rummage Sale" (0 homes, announced).', $out);
        $events = $this->json('GET', '/api/events')['events'];
        self::assertSame([['holiday_lights', 'Christmas Lights', 'open', '2026-12-01', true, false], ['halloween', 'Halloween', 'closed', '2026-10-31', false, false],
            ['halloween', 'Halloween', 'upcoming', '2027-10-31', false, true], ['garage_sale', 'Community Rummage Sale', 'upcoming', null, false, false]],
            array_map(static fn (array $e) => [$e['type'], $e['name'], $e['state'], $e['starts_on'], $e['is_default'], $e['dates_tentative']], $events));
        self::assertSame('Halloween 2026 was a success — thank you! Come back next year (Oct 31, 2027, tentative). Residents: sign in to get your house ready for next year.', $events[1]['closing_message']);
        self::assertCount(10, $this->json('GET', '/api/homes')['homes'], 'the lights homes on the default event');
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings WHERE event_id = ?', [$events[1]['id']]));

        self::assertStringContainsString('Demo homes already present; nothing to do.', $this->seed());
        self::assertSame(4, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_occurrences'), 'seeding again adds nothing');
        $this->db()->executeStatement("UPDATE event_occurrences SET state = 'archived', archived_at = NOW() WHERE id = ?", [$events[2]['id']]);
        self::assertStringContainsString('Added next year\'s FICTIONAL demo event after "Halloween".', $this->seed());
        self::assertSame([['upcoming', '2027-10-31']], $this->db()->fetchAllNumeric("SELECT state, starts_on FROM event_occurrences WHERE type = 'halloween' AND state = 'upcoming'"));
    }

    private function seed(): string
    {
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
        $this->repo()->reset();

        return $tester->getDisplay();
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private static function sorted(array $a): array
    {
        ksort($a);

        return $a;
    }

    private function event(string $type, string $name, ?string $from, ?string $to, string $state): int
    {
        return (int) $this->db()->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, activated_at, closed_at, archived_at)
            VALUES (?, ?, ?, ?, '17:00', '21:00', ?, NOW(), CASE WHEN ? IN ('open', 'closed') THEN NOW() END, CASE WHEN ? = 'closed' THEN NOW() END, CASE WHEN ? = 'archived' THEN NOW() END) RETURNING id",
            [$type, $name, $from, $to, $state, $state, $state, $state]);
    }

    /** A stored listing for an event (straight in the table, as a seed of every state). */
    private function listFor(int $event, int $address, string $note): void
    {
        $id = (int) $this->db()->fetchOne("INSERT INTO event_listings (event_id, address_id, details, status, note, published, updated_at) VALUES (?, ?, '{}', 'active', ?, TRUE, NOW()) RETURNING id", [$event, $address, $note]);
        $this->db()->executeStatement("INSERT INTO event_listing_windows (listing_id, local_date, start_local, end_local) SELECT ?, COALESCE(starts_on, DATE '2027-01-01'), '17:00', '21:00' FROM event_occurrences WHERE id = ?", [$id, $event]);
    }

    /** @param array<string,mixed> $listing */
    private function requestLinkFor(string $email, array $listing, int $address, int $event): string
    {
        $r = $this->json('POST', '/api/auth/request-link?e='.$event, ['email' => $email, 'pow' => $this->solvePow('claim'), 'claim' => ['address' => $this->byId($address), 'listing' => $listing]]);
        self::assertSame(202, $this->code(), json_encode($r));
        $messages = self::getMailerMessages();
        preg_match('~/auth/confirm\?t=([A-Za-z0-9_-]{43})~', (string) end($messages)->getTextBody(), $m);

        return $m[1];
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
