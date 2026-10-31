<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\DemoSeedCommand;
use App\Community\CommunityBootstrapper;
use App\Shared\RuntimeConfig;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @module tests/Integration/GarageSaleSeasonTest
 * @description Organizers and seasons for the garage sale (ROADMAP Stage 4): Organizer → Events prepares a sale with
 *   its sale days, the categories it takes, the early-bird time, rules and the driving tour (bad settings refused), the
 *   draft is never public and its preview shows the sale theme, words and filters, the switch puts it on the map, and
 *   a sale day that homes chose cannot be removed. One address lists for Halloween, then Christmas lights, then the
 *   rummage sale: three independent listings, and an ended season's listing is never public again (acceptance item 2).
 *   The fictional sale demo seeds its days, hours and fixed statuses.
 * @dependencies tests/Support/AppTestCase, Command/DemoSeedCommand
 */
final class GarageSaleSeasonTest extends AppTestCase
{
    private string $csrf = '';

    private function page(string $uri): string
    {
        $this->client->request('GET', $uri);

        return (string) $this->client->getResponse()->getContent();
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

    private function resident(string $email): string
    {
        $this->client->getCookieJar()->clear();
        $this->signIn($email);

        return $this->csrf();
    }

    /** Activates a draft in place of the event on the map. */
    private function switchTo(int $draft): void
    {
        $active = $this->json('GET', '/api/admin/events')['active_id'];
        $r = $this->json('POST', "/api/admin/events/$draft/activate", ['confirm' => true, 'replaces' => $active], $this->h());
        self::assertSame(200, $this->code(), json_encode($r));
    }

    public function testAnOrganizerPreparesPreviewsAndSwitchesToARummageSale(): void
    {
        $this->clock = static::mockTime('2027-03-20 15:00:00 UTC');
        $this->configureCommunity();
        $this->organizer();
        $bad = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'starts_on' => '2027-04-10', 'ends_on' => '2027-04-18', 'settings' => [
            'days' => ['2027-04-10', '2027-04-11', '2027-04-17', '2027-04-18'], 'categories' => [], 'early_bird_time' => '7am', 'route_modes' => ['walking', 'flying'], 'best_night' => '2027-04-10']], $this->h());
        self::assertSame(422, $this->code());
        self::assertSame(['settings.days', 'settings.categories', 'settings.early_bird_time', 'settings.route_modes', 'settings.best_night'], array_keys($bad['error']['fields']),
            'more than three days, no category, a bad time, an unknown route mode, another type\'s setting');
        $bad = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'starts_on' => '2027-04-10', 'ends_on' => '2027-04-17'], $this->h());
        self::assertSame(['settings.days' => 'Choose the sale days (1 to 3) inside the event.'], $bad['error']['fields'], 'a span longer than three days names its days');
        $bad = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'starts_on' => '2027-04-10', 'ends_on' => '2027-04-11', 'settings' => ['days' => ['2027-04-12']]], $this->h());
        self::assertArrayHasKey('settings.days', $bad['error']['fields'], 'a day outside the event');

        $draft = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'name' => 'Community Rummage Sale', 'starts_on' => '2027-04-10', 'ends_on' => '2027-04-17',
            'default_start' => '08:00', 'default_end' => '14:00', 'settings' => ['days' => ['2027-04-17', '2027-04-10', '2027-04-11'], 'categories' => ['kids_baby', 'toys', 'furniture', 'free'],
                'early_bird_time' => '07:30', 'rules' => ['No early birds before 7:30 AM', 'Park on one side of the street'], 'route_modes' => ['walking', 'driving']]], $this->h())['event'];
        self::assertSame(201, $this->code(), json_encode($draft));
        self::assertSame(['Community Rummage Sale', ['2027-04-10', '2027-04-11', '2027-04-17'], ['kids_baby', 'toys', 'furniture', 'free'], '07:30'],
            [$draft['name'], $draft['settings']['days'], $draft['settings']['categories'], $draft['settings']['early_bird_time']]);
        $types = array_column($this->json('GET', '/api/admin/events')['types'], null, 'key');
        self::assertSame(['days', 'categories', 'early_bird_time'], $types['garage_sale']['settings']);
        self::assertSame('Kids & baby', $types['garage_sale']['setting_options']['categories']['kids_baby']);
        self::assertStringNotContainsString('Rummage', (string) json_encode($this->json('GET', '/api/event')), 'a draft is never public');

        $html = $this->page('/admin/events/'.$draft['id'].'/preview');
        self::assertSame(200, $this->code());
        self::assertStringContainsString('data-event-type="garage_sale"', $html, 'the draft type\'s theme');
        self::assertStringContainsString('Good finds. <span>Close to home.</span>', $html, 'the draft type\'s default headline (the community kept Halloween\'s default)');
        self::assertStringContainsString('Sat, April 10, Sun, April 11 and Sat, April 17, 2027', $html, 'its sale days');
        self::assertStringContainsString('Early birds: not before 7:30 AM', $html);
        self::assertStringContainsString('>Coming this spring</span>', $html);
        self::assertStringContainsString('data-filter="kidsToys"', $html, 'the draft type\'s filters');
        self::assertStringContainsString('Open Saturday, Apr 10</button>', $html, 'one toggle per sale day; the same weekday twice names the dates');
        self::assertStringNotContainsString('data-filter="candy"', $html, 'not Halloween\'s');
        self::assertStringNotContainsString('value="tools"', $html, 'only the categories the sale takes');

        $this->switchTo($draft['id']);
        $event = $this->json('GET', '/api/event')['event'];
        self::assertSame(['garage_sale', 'Community Rummage Sale', ['2027-04-10', '2027-04-11', '2027-04-17'], '07:30', ['walking']],
            [$event['type'], $event['name'], $event['days'], $event['early_bird_time'], $event['route_modes']], 'driving needs a provider too');
        self::assertStringContainsString('--brand:#178D80', $this->page('/theme.css'));
        $about = $this->page('/about');
        self::assertStringContainsString('Park on one side of the street', $about);
        self::assertStringContainsString('🐦 Early birds: not before 7:30 AM', $about);
        self::assertStringContainsString('This map sells nothing: prices and payment stay between you and the seller.', $about);

        // The organizer enables a provider: the driving tour is offered.
        $settings = $this->json('GET', '/api/admin/community')['settings'];
        $settings['routing']['provider_enabled'] = true;
        $this->json('PUT', '/api/admin/community', ['settings' => $settings], $this->h());
        self::assertSame(200, $this->code());
        self::assertSame(['walking', 'driving'], $this->json('GET', '/api/event')['event']['route_modes']);

        // A home takes part on Sunday; that day stays a sale day while it is listed.
        $id = $this->addAddress('21', 'Lantern Way', 40.006, -74.995, 'east');
        $this->confirm($this->requestLink('dana@example.test', ['address' => $this->byId($id), 'listing' => ['details' => ['categories' => ['toys']],
            'windows' => [['date' => '2027-04-11', 'start' => '09:00', 'end' => '12:00']]]]));
        $this->organizer();
        $r = $this->json('PUT', '/api/admin/events/'.$draft['id'], ['settings' => ['days' => ['2027-04-10', '2027-04-17'], 'categories' => ['toys']]], $this->h());
        self::assertSame(409, $this->code());
        self::assertSame('listings_days', $r['error']['code']);
        self::assertSame(['2027-04-10', '2027-04-11', '2027-04-17'], $this->json('GET', '/api/event')['event']['days'], 'nothing changed');
        $r = $this->json('PUT', '/api/admin/events/'.$draft['id'], ['settings' => ['days' => ['2027-04-10', '2027-04-11'], 'categories' => ['toys'], 'rules' => ['Be kind']]], $this->h());
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame(['2027-04-10', '2027-04-11'], $this->json('GET', '/api/event')['event']['days'], 'an unlisted day can go');
    }

    public function testOneAddressListsForHalloweenChristmasAndTheRummageSaleIndependently(): void
    {
        $this->clock = static::mockTime('2026-10-31 22:00:00 UTC');
        $this->configureCommunity();
        $home = $this->addAddress('11', 'Pumpkin Ln', 40.004, -75.005, 'west');
        $this->confirm($this->requestLink('alice@example.test', ['address' => $this->byId($home), 'listing' => self::listing(['note' => 'Full-size bars'])]));
        $halloween = (int) $this->json('GET', '/api/event')['event']['id'];

        $this->organizer();
        $lights = $this->json('POST', '/api/admin/events', ['type' => 'holiday_lights', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03', 'default_start' => '18:00', 'default_end' => '22:00'], $this->h())['event'];
        $this->switchTo($lights['id']);
        $csrf = $this->resident('alice@example.test');
        $r = $this->json('POST', "/api/me/homes/$home/listing", ['note' => 'Inflatables on the lawn', 'details' => ['styles' => ['inflatables']],
            'windows' => [['date' => '2026-12-19', 'start' => '18:00', 'end' => '22:00']]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), json_encode($r));

        $this->organizer();
        $sale = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'name' => 'Community Rummage Sale', 'starts_on' => '2027-04-10', 'ends_on' => '2027-04-11',
            'default_start' => '08:00', 'default_end' => '14:00'], $this->h())['event'];
        $this->switchTo($sale['id']);
        $csrf = $this->resident('alice@example.test');
        $me = $this->json('GET', '/api/me');
        self::assertSame(['garage_sale', null, true], [$me['event_type'], $me['homes'][0]['listing'], $me['homes'][0]['can_list']], 'the claim stays; the listing starts empty');
        $r = $this->json('POST', "/api/me/homes/$home/listing", ['note' => 'Toys and a crib', 'details' => ['categories' => ['toys', 'kids_baby']],
            'windows' => [['date' => '2027-04-10', 'start' => '08:00', 'end' => '12:00'], ['date' => '2027-04-11', 'start' => '09:00', 'end' => '11:00']]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertSame('Toys and a crib', $r['home']['listing']['note'], 'only what was sent now');

        $rows = $this->db()->fetchAllAssociative('SELECT o.type, el.note, el.details FROM event_listings el JOIN event_occurrences o ON o.id = el.event_id WHERE el.address_id = ? ORDER BY el.id', [$home]);
        self::assertSame(['halloween', 'holiday_lights', 'garage_sale'], array_column($rows, 'type'), 'three listings for one address');
        self::assertSame(['Full-size bars', 'Inflatables on the lawn', 'Toys and a crib'], array_column($rows, 'note'), 'each season keeps its own');
        self::assertSame(['kids_baby', 'toys'], json_decode((string) $rows[2]['details'], true)['categories']);
        self::assertArrayNotHasKey('candy', json_decode((string) $rows[2]['details'], true), 'nothing carried over');
        $public = $this->json('GET', '/api/homes')['homes'];
        self::assertSame([[$home, 'garage_sale', (int) $sale['id'], 'Toys and a crib']], array_map(static fn (array $h) => [$h['id'], $h['event_type'], $h['event_id'], $h['note']], $public),
            'only the sale is public');
        self::assertSame('Toys and a crib', $this->json('GET', "/api/homes/$home")['home']['note'], 'the address id resolves within the sale only');
        self::assertSame([$halloween, (int) $lights['id']], array_map('intval', $this->db()->fetchFirstColumn("SELECT id FROM event_occurrences WHERE state = 'archived' ORDER BY id")));
        self::assertStringNotContainsString('Full-size bars', $this->page('/'), 'past seasons are never public');
        self::assertStringNotContainsString('Inflatables on the lawn', $this->page('/'));
        self::assertFalse((bool) $this->db()->fetchOne('SELECT published FROM listings WHERE address_id = ?', [$home]), 'the previous release hides the Halloween row too');
    }

    public function testTheFictionalSaleDemoSeedsItsDaysHoursAndFixedStatuses(): void
    {
        $this->clock = static::mockTime('2027-04-10 14:30:00 UTC'); // 9:30 AM on Saturday in America/Chicago
        $result = static::getContainer()->get(CommunityBootstrapper::class)->bootstrap(\dirname(__DIR__, 2).'/examples/demo-sale/community.yaml', false, true);
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
        self::assertSame(0, $tester->execute(['file' => 'examples/demo-sale/homes.json']), $tester->getDisplay());
        self::assertStringContainsString('Seeded 10 FICTIONAL demo homes.', $tester->getDisplay());
        $this->repo()->reset();
        $homes = array_column($this->json('GET', '/api/homes')['homes'], null, 'address');
        self::assertCount(10, $homes);
        self::assertSame([['2027-04-10', '07:00', '12:00'], ['2027-04-11', '09:00', '13:00']], array_map(static fn (array $w) => [$w['date'], $w['start'], $w['end']], $homes['33 Juniper Ct']['windows']), 'different Saturday and Sunday hours');
        self::assertSame(['sold_out', '2027-04-10', 'sold_out'], [$homes['52 Cedar Way']['status'], $homes['52 Cedar Way']['status_date'], $homes['52 Cedar Way']['display_status']]);
        self::assertSame(['paused', '2027-04-11', 'open'], [$homes['66 Birch Ln']['status'], $homes['66 Birch Ln']['status_date'], $homes['66 Birch Ln']['display_status']], 'paused for Sunday, open on Saturday');
        self::assertSame('closed', $homes['78 Juniper Ct']['display_status']);
        self::assertSame('opens_at', $homes['85 Cedar Way']['display_status'], 'opens at 10 AM');
        $c = $this->json('GET', '/api/community');
        $event = $this->json('GET', '/api/event')['event'];
        self::assertSame(['Good finds.', 'maple-hollow-sale-demo', '#178D80', 'Community Rummage Sale', '07:30', ['walking']],
            [$c['branding']['headline'], $c['community']['id'], $c['branding']['primary_color'], $event['name'], $event['early_bird_time'], $event['route_modes']], 'no branding block: the sale defaults');
    }
}
