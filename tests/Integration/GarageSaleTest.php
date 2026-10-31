<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * @module tests/Integration/GarageSaleTest
 * @description The garage sale event type end to end on the server (ROADMAP Stage 4): a sale community lists a home
 *   with its own hours on each sale day, the public projection (exactly the event keys plus status_date; strict
 *   details; the public event's days, categories, early-bird time and route modes), "sold out" and "paused" named only
 *   on their own day and never afterwards, closed for the sale, strict input (unknown details, categories the sale
 *   does not allow, payment handles, days outside the sale, more than three days, end before start), a note with
 *   contact details refused and never stored (claim request, My house edit, "List for"), resident text escaped on the
 *   page, and My house never reaching another resident's sale home.
 * @dependencies tests/Support/AppTestCase
 */
final class GarageSaleTest extends AppTestCase
{
    private const SALE = ['type' => 'garage_sale', 'name' => 'Community Rummage Sale', 'date' => '2027-04-10', 'starts_on' => '2027-04-10', 'ends_on' => '2027-04-11',
        'starts_at' => '08:00', 'ends_at' => '14:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = static::mockTime('2027-04-10 14:30:00 UTC'); // 10:30 AM on Saturday in America/New_York
    }

    /** @return array<string,mixed> a sale listing with per-day hours */
    private static function sale(array $details = [], array $windows = [['2027-04-10', '07:00', '12:00'], ['2027-04-11', '09:00', '13:00']], array $over = []): array
    {
        return $over + ['note' => 'Baby gear in the garage.',
            'details' => $details + ['categories' => ['kids_baby', 'toys'], 'multi_family' => true, 'payment_types' => ['cash', 'apps']],
            'windows' => array_map(static fn (array $w) => ['date' => $w[0], 'start' => $w[1], 'end' => $w[2]], $windows)];
    }

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

    public function testASaleHomeIsPublicWithItsDaysAndExactlyTheEventKeys(): void
    {
        $this->configureCommunity(['event' => self::SALE]);
        $id = $this->listHome('11', 'alice@example.test', self::sale());
        $homes = $this->json('GET', '/api/homes')['homes'];
        self::assertCount(1, $homes);
        $h = $homes[0];
        self::assertSame(['id', 'address', 'zone', 'lat', 'lon', 'start', 'end', 'note', 'status', 'updated_at', 'event_id', 'event_type', 'details', 'windows', 'display_status', 'status_date'],
            array_keys($h), 'the shared keys, no Halloween flat fields, the event keys and status_date');
        self::assertSame(['garage_sale', 'open', 'active', null, '07:00', '12:00'], [$h['event_type'], $h['display_status'], $h['status'], $h['status_date'], $h['start'], $h['end']]);
        self::assertSame(['categories' => ['kids_baby', 'toys'], 'multi_family' => true, 'early_birds_allowed' => false, 'payment_types' => ['cash', 'apps']], $h['details']);
        self::assertSame([['date' => '2027-04-10', 'start' => '07:00', 'end' => '12:00'], ['date' => '2027-04-11', 'start' => '09:00', 'end' => '13:00']], $h['windows'], 'different hours each day');
        self::assertSame($h, $this->json('GET', "/api/homes/$id")['home']);

        $event = $this->json('GET', '/api/event')['event'];
        self::assertSame(['garage_sale', 'Community Rummage Sale', 'sale_days', '2027-04-10', '2027-04-11', ['start' => '08:00', 'end' => '14:00']],
            [$event['type'], $event['name'], $event['schedule_kind'], $event['starts_on'], $event['ends_on'], $event['default_hours']]);
        self::assertSame(['kidsToys', 'furniture', 'tools', 'household', 'electronics', 'free', 'openNow', 'multiFamily', 'earlyBirds'], array_column($event['filters'], 'key'));
        self::assertSame(['timezone', 'days', 'categories', 'early_bird_time', 'route_modes'], \array_slice(array_keys($event), 14), 'the shared keys keep their order; the type\'s keys follow');
        self::assertSame([['2027-04-10', '2027-04-11'], 10, null, ['walking']], [$event['days'], \count($event['categories']), $event['early_bird_time'], $event['route_modes']], 'a weekend sale takes its days');
        self::assertSame('🏷️ Your sale stops', $event['copy']['route_title']);
        $c = $this->json('GET', '/api/community');
        self::assertSame(['Good finds.', 'Close to home.', '#178D80', '#E77C5D'], [$c['branding']['headline'], $c['branding']['headline_accent'], $c['branding']['primary_color'], $c['branding']['accent_color']]);
        self::assertSame(['homes' => 1, 'multi_family' => 1, 'early_birds' => 0, 'free' => 0], array_intersect_key($c['stats'], ['homes' => 1, 'multi_family' => 1, 'early_birds' => 1, 'free' => 1]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM listings'), 'sale listings live only in the event tables (no legacy row)');

        $html = $this->page('/');
        self::assertStringContainsString('<html lang="en" data-event-type="garage_sale">', $html);
        self::assertStringContainsString('css/events/garage_sale.css', $html);
        self::assertStringContainsString('Sat–Sun, April 10–11, 2027', $html, 'the sale row');
        self::assertStringContainsString('Most homes: 8 AM–2 PM · Individual hours vary', $html);
        self::assertStringContainsString('>Today</span>', $html, 'the pill on a sale day');
        self::assertStringContainsString('data-before="Coming this spring"', $html);
        self::assertStringContainsString('🏷️ Apr 10–11</a>', $html, 'the top bar badge');
        self::assertStringContainsString('Having a sale?', $html);
        self::assertStringContainsString('Good finds. <span>Close to home.</span>', $html);
        self::assertStringContainsString('data-filter="day1"', $html);
        self::assertStringContainsString('Open Saturday</button>', $html);
        self::assertStringContainsString('Open Sunday</button>', $html);
        self::assertStringContainsString('🍼 Kids &amp; baby', $html, 'category tags with words');
        self::assertStringContainsString('🏘️ Multi-family', $html);
        self::assertStringNotContainsString('data-filter="candy"', $html);

        $this->clock->modify('-200 days'); // September: the sale is coming
        self::assertStringContainsString('>Coming this spring</span>', $this->page('/'));
        $this->clock->modify('+202 days'); // the Monday after
        self::assertStringContainsString('>Sale over</span>', $this->page('/'));
        $about = $this->page('/about');
        self::assertStringContainsString('Sat–Sun, April 10–11, 2027', $about);
        self::assertStringContainsString('each home sets its own sale days and hours', $about);
        self::assertStringContainsString('Sale etiquette', $about);
        self::assertStringContainsString('No recalled, unsafe or prohibited items', $about);
    }

    public function testSoldOutAndPausedHoldForTheirOwnDayAndClosedForTheSale(): void
    {
        $this->configureCommunity(['event' => self::SALE]);
        $id = $this->listHome('11', 'alice@example.test', self::sale());
        $this->signIn('alice@example.test');
        $csrf = $this->csrf();
        $r = $this->json('PATCH', "/api/me/homes/$id", ['status' => 'sold_out'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame('2027-04-10', $this->db()->fetchOne('SELECT status_date FROM event_listings'), 'the community-local day');
        $h = $this->json('GET', "/api/homes/$id")['home'];
        self::assertSame(['sold_out', '2027-04-10', 'sold_out'], [$h['status'], $h['status_date'], $h['display_status']], 'sold out never shows as open');
        self::assertCount(2, $h['windows'], 'sold out today keeps Sunday');

        $this->clock->modify('+1 day'); // Sunday 10:30 AM
        $h = $this->json('GET', "/api/homes/$id")['home'];
        self::assertSame(['active', null, 'open'], [$h['status'], $h['status_date'], $h['display_status']], 'an expired day shows no date (no history)');

        $this->client->getCookieJar()->clear();
        $this->signIn('alice@example.test');
        $csrf = $this->csrf();
        $this->json('PATCH', "/api/me/homes/$id", ['status' => 'paused'], ['X-CSRF-Token' => $csrf]);
        $h = $this->json('GET', "/api/homes/$id")['home'];
        self::assertSame(['paused', '2027-04-11', 'paused'], [$h['status'], $h['status_date'], $h['display_status']]);
        $this->json('PATCH', "/api/me/homes/$id", ['status' => 'closed'], ['X-CSRF-Token' => $csrf]);
        $h = $this->json('GET', "/api/homes/$id")['home'];
        self::assertSame(['closed', null, 'closed'], [$h['status'], $h['status_date'], $h['display_status']]);
        $this->json('PATCH', "/api/me/homes/$id", ['status' => 'active'], ['X-CSRF-Token' => $csrf]);
        $this->clock->modify('+3 hours'); // 1:30 PM Sunday: after this home's Sunday hours
        self::assertSame('not_today', $this->json('GET', "/api/homes/$id")['home']['display_status'], 'never "open" outside the day\'s hours');
    }

    public function testANoteWithContactDetailsIsRefusedAndNeverStored(): void
    {
        $this->configureCommunity(['event' => self::SALE]);
        $id = $this->addAddress('11', 'Pumpkin Ln', 40.004, -75.005, 'west');
        foreach (['Call 555-123-4567 to hold an item' => 'phone number', 'jane at example dot com' => 'email address', 'Venmo: jane-doe' => 'payment username',
            'Photos at maple-sale.com' => 'web address'] as $note => $what) {
            $r = $this->json('POST', '/api/auth/request-link', ['email' => 'alice@example.test', 'pow' => $this->solvePow('claim'),
                'claim' => ['address' => $this->byId($id), 'listing' => self::sale([], [['2027-04-10', '08:00', '12:00']], ['note' => $note])]]);
            self::assertSame(422, $this->code(), $note);
            self::assertStringContainsString($what, $r['error']['fields']['note'] ?? '', $note);
        }
        self::assertCount(0, self::getMailerMessages(), 'no link was sent');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claim_intents'), 'no claim request holds the note');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings'));

        $this->confirm($this->requestLink('alice@example.test', ['address' => $this->byId($id), 'listing' => self::sale([], [['2027-04-10', '08:00', '12:00']], ['note' => 'Sat 8-2, $5 bags, 2-for-1 on items 1-100'])]));
        $this->client->getCookieJar()->clear();
        $this->signIn('alice@example.test');
        $csrf = $this->csrf();
        $r = $this->json('PATCH', "/api/me/homes/$id", ['note' => 'Text me: (555) 123-4567', 'published' => false], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code());
        self::assertSame(['note'], array_keys($r['error']['fields']));
        self::assertSame(['Sat 8-2, $5 bags, 2-for-1 on items 1-100', true], [$this->db()->fetchOne('SELECT note FROM event_listings'), (bool) $this->db()->fetchOne('SELECT published FROM event_listings')],
            'nothing of the refused edit is stored, not even unpublished');
        self::assertSame('Sat 8-2, $5 bags, 2-for-1 on items 1-100', $this->json('GET', "/api/homes/$id")['home']['note']);

        $this->db()->executeStatement('DELETE FROM event_listings');
        $r = $this->json('POST', "/api/me/homes/$id/listing", self::sale([], [['2027-04-11', '09:00', '12:00']], ['note' => '$janedoe on Cash App']), ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code(), json_encode($r));
        self::assertStringContainsString('payment username', $r['error']['fields']['note']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_listings'), '"List for" stores nothing either');
    }

    public function testInputIsStrictAndResidentTextIsEscaped(): void
    {
        $this->configureCommunity(['event' => self::SALE]);
        $this->db()->executeStatement("UPDATE event_occurrences SET settings = '{\"days\": [\"2027-04-10\", \"2027-04-11\"], \"categories\": [\"kids_baby\", \"toys\", \"tools\"]}'");
        $this->repo()->reset();
        $id = $this->addAddress('11', 'Pumpkin Ln', 40.004, -75.005, 'west');
        $ask = fn (array $listing) => $this->json('POST', '/api/auth/request-link', ['email' => 'alice@example.test', 'pow' => $this->solvePow('claim'), 'claim' => ['address' => $this->byId($id), 'listing' => $listing]]);
        $r = $ask(self::sale(['photo_url' => 'x', 'price' => 5]));
        self::assertSame(422, $this->code());
        self::assertSame(['details.photo_url', 'details.price'], array_keys($r['error']['fields']));
        $r = $ask(self::sale(['categories' => ['furniture']]));
        self::assertSame('This sale lists Kids & baby, Toys and Tools; choose from those.', $r['error']['fields']['categories'] ?? null, 'only the categories the sale allows');
        $r = $ask(self::sale(['payment_types' => ['cash', 'venmo @jane']]));
        self::assertArrayHasKey('payment_types', $r['error']['fields'], 'kinds only');
        $r = $ask(self::sale([], [['2027-04-09', '08:00', '12:00']]));
        self::assertSame('Choose a day from 2027-04-10 to 2027-04-11.', $r['error']['fields']['windows.0'] ?? null, 'the day before the sale');
        $r = $ask(self::sale([], [['2027-04-10', '08:00', '12:00'], ['2027-04-12', '08:00', '12:00']]));
        self::assertSame('Choose a day from 2027-04-10 to 2027-04-11.', $r['error']['fields']['windows.1'] ?? null, 'the day after');
        $r = $ask(self::sale([], [['2027-04-10', '12:00', '08:00']]));
        self::assertSame('End time must be later than the start time.', $r['error']['fields']['windows.0'] ?? null);
        $r = $ask(self::sale([], [['2027-04-10', '08:00', '12:00'], ['2027-04-10', '13:00', '14:00']]));
        self::assertSame('That day is already chosen.', $r['error']['fields']['windows.1'] ?? null);
        $r = $ask(['details' => ['categories' => ['toys']], 'note' => '']);
        self::assertArrayHasKey('windows', $r['error']['fields'], 'a sale listing names its days');
        $r = $ask(self::sale([], [['2027-04-10', '08:00', '12:00']], ['candy' => true]));
        self::assertSame('Unknown field.', $r['error']['fields']['candy'] ?? null, 'Halloween\'s flat fields are not sale fields');

        $evil = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->confirm($this->requestLink('alice@example.test', ['address' => $this->byId($id), 'listing' => self::sale([], [['2027-04-10', '08:00', '12:00']], ['note' => $evil])]));
        $this->client->getCookieJar()->clear();
        self::assertSame($evil, $this->json('GET', "/api/homes/$id")['home']['note'], 'stored as typed, escaped on output');
        $html = $this->page('/');
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'the server-rendered card');
        self::assertStringNotContainsString('value="furniture"', $html, 'the form offers only the categories the sale allows');
        self::assertStringContainsString('value="tools"', $html);
    }

    public function testOnlyTheCategoriesTheSaleAllowsHaveFiltersAndTheDayTogglesComeBeforeTheKindOfSale(): void
    {
        $this->configureCommunity(['event' => self::SALE]);
        $html = $this->page('/');
        $order = static fn (string $page, array $keys): array => array_map(static fn (string $k) => strpos($page, 'data-filter="'.$k.'"'), $keys);
        foreach (['kidsToys', 'furniture', 'tools', 'household', 'electronics', 'free'] as $k) {
            self::assertStringContainsString('data-filter="'.$k.'"', $html, 'every category is allowed by default: '.$k);
        }
        [$open, $day1, $day2, $family, $early] = $order($html, ['openNow', 'day1', 'day2', 'multiFamily', 'earlyBirds']);
        self::assertTrue($open < $day1 && $day1 < $day2 && $day2 < $family && $family < $early, 'Filters: open now, the sale days, then multi-family and early birds');

        // The organizer lists only kids & baby, books and tools: the furniture, household, electronics and free filters go.
        $this->db()->executeStatement("UPDATE event_occurrences SET settings = '{\"days\": [\"2027-04-10\", \"2027-04-11\"], \"categories\": [\"kids_baby\", \"books_media\", \"tools\"]}'");
        $this->repo()->reset();
        $html = $this->page('/');
        foreach (['kidsToys', 'tools', 'openNow', 'day1', 'day2', 'multiFamily', 'earlyBirds', 'saved'] as $k) {
            self::assertStringContainsString('data-filter="'.$k.'"', $html, 'still offered: '.$k);
        }
        foreach (['furniture', 'household', 'electronics', 'free'] as $k) {
            self::assertStringNotContainsString('data-filter="'.$k.'"', $html, 'a category the sale leaves out has no filter: '.$k);
        }
        $keys = array_column($this->json('GET', '/api/event')['event']['filters'], 'key');
        self::assertSame(['kidsToys', 'tools', 'openNow', 'multiFamily', 'earlyBirds'], $keys, 'the public event names the same filters');
    }

    public function testMyHouseNeverReachesAnotherResidentsSaleHome(): void
    {
        $this->configureCommunity(['event' => self::SALE]);
        $alice = $this->listHome('11', 'alice@example.test', self::sale());
        $bobs = $this->listHome('13', 'bob@example.test', self::sale(['categories' => ['tools']]));
        $this->signIn('bob@example.test');
        $csrf = $this->csrf();
        self::assertSame([$bobs], array_column($this->json('GET', '/api/me')['homes'], 'id'));
        foreach ([['PATCH', "/api/me/homes/$alice", ['status' => 'closed']], ['POST', "/api/me/homes/$alice/listing", self::sale()],
            ['DELETE', "/api/me/homes/$alice", ['confirm' => 'RELEASE']]] as [$method, $uri, $body]) {
            $this->json($method, $uri, $body, ['X-CSRF-Token' => $csrf]);
            self::assertSame(404, $this->code(), "$method $uri");
        }
        self::assertSame('active', $this->db()->fetchOne('SELECT status FROM event_listings WHERE address_id = ?', [$alice]));
        $r = $this->json('PATCH', "/api/me/homes/$bobs", ['status' => 'sold_out', 'details' => ['categories' => ['tools', 'free'], 'early_birds_allowed' => true]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame(['tools', 'free'], $r['home']['listing']['details']['categories']);
        self::assertSame(['2027-04-10', '2027-04-11'], array_column($r['home']['listing']['windows'], 'date'), 'a partial save keeps the days');
        $r = $this->json('PATCH', "/api/me/homes/$bobs", ['windows' => [['date' => '2027-04-11', 'start' => '10:00', 'end' => '15:00']]], ['X-CSRF-Token' => $csrf]);
        self::assertSame([['date' => '2027-04-11', 'start' => '10:00', 'end' => '15:00']], $r['home']['listing']['windows'], 'Sunday only, with its own hours');
    }
}
