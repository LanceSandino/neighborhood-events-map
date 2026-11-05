<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\LogoStore;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\CustomHalloweenCommunity;

/**
 * @module tests/Integration/OrganizerConsoleNeutralTest
 * @description The organizer console belongs to the community, not to the default event (owner request 2026-10-31,
 *   D-20261031-52): on a fictional community with its own Halloween branding (pumpkin emoji, ghost headline, orange)
 *   and Halloween, Christmas lights and a yard sale open, every console page's chrome — title, header mark, colours,
 *   tabs — names the community and uses the logo or a neutral mark and the console's own colours (no /theme.css of an
 *   event), and carries no Halloween word or emoji; the data the pages show for an event names that event and its type
 *   (the addresses' listing column, the Events list). Each event's branding stays in Organizer → Events and the draft
 *   preview, which uses the draft's own colours. tests/e2e/specs/admin.spec.mjs checks the rendered pages in a browser.
 * @dependencies tests/Support/AppTestCase, tests/Support/CustomHalloweenCommunity
 */
final class OrganizerConsoleNeutralTest extends AppTestCase
{
    use CustomHalloweenCommunity;

    private const HALLOWEEN = '~ghost|trick[- ]?or[- ]?treat|\btreats?\b|candy|candies|scare|pumpkin|halloween|🎃|👻~iu';
    private const PAGES = ['/admin', '/admin/events', '/admin/community', '/admin/community/map', '/admin/addresses', '/admin/disputes', '/admin/audit'];

    public function testTheConsoleChromeIsTheCommunitysOnEveryPage(): void
    {
        $ids = $this->community();
        foreach (self::PAGES as $path) {
            $page = $this->client->request('GET', $path);
            self::assertSame(200, $this->code(), $path);
            $html = (string) $page->html();
            self::assertStringEndsWith('#brand-community', (string) $page->filter('.admin-top .brand-icon svg use')->attr('href'), "$path: the community's neutral drawn mark (no logo set)");
            self::assertStringNotContainsString('/theme.css', $html, "$path: no event's colours in the console");
            self::assertSame('/assets/img/favicon-community.svg', $page->filter('link[rel="icon"]')->attr('href'), "$path: the community's icon");
            preg_match_all(self::HALLOWEEN, (string) preg_replace('~<script.*?</script>~s', '', $html), $m);
            self::assertSame([], array_values(array_unique($m[0])), "$path: no Halloween word or emoji in the console's page");
            self::assertStringEndsWith(' · Organizer · Fernbrook Glen', $page->filter('title')->text());
        }
        // A logo, when the community has one, is its mark.
        static::getContainer()->get(LogoStore::class)->put(null, 'svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->repo()->reset();
        $page = $this->client->request('GET', '/admin');
        self::assertStringStartsWith('/branding/logo.svg?v=', (string) $page->filter('.admin-top .brand-icon img')->attr('src'));
        self::assertStringStartsWith('/branding/logo.svg?v=', (string) $page->filter('link[rel="icon"]')->attr('href'));
        static::getContainer()->get(LogoStore::class)->remove(null);

        // The draft preview is the event's own page: its type, its icon and its own colours (not the default event's).
        $h = ['X-CSRF-Token' => $this->csrf];
        $draft = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'name' => 'Fall Swap', 'starts_on' => '2027-09-11', 'ends_on' => '2027-09-11',
            'settings' => ['branding' => ['primary_color' => '#225577']]], $h)['event'];
        $preview = $this->client->request('GET', '/admin/events/'.$draft['id'].'/preview');
        $theme = (string) $preview->filter('link[rel="stylesheet"][href*="theme.css"]')->attr('href');
        self::assertSame('/admin/events/'.$draft['id'].'/theme.css', strtok($theme, '?'), 'the draft\'s own colours');
        $this->client->request('GET', $theme);
        self::assertStringContainsString('--brand:#225577', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'never cached');
        self::assertSame('/assets/img/favicon-sale.svg', $preview->filter('link[rel="icon"]')->attr('href'), 'the sale\'s icon');
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $theme);
        self::assertNotSame(200, $this->code(), 'organizers only');
    }

    public function testTheDataOfAnEventNamesItsEventAndType(): void
    {
        $ids = $this->community();
        $events = $this->json('GET', '/api/admin/events');
        $byId = array_column($events['events'], null, 'id');
        self::assertSame(['i-pumpkin', 'i-c9-red', 'i-tag'], [$byId[$ids['halloween']]['type_icon'], $byId[$ids['lights']]['type_icon'], $byId[$ids['sale']]['type_icon']], 'each event\'s type mark');
        $home = $this->addAddress('31', 'Lantern Way', 40.006, -75.004, 'west');
        $this->client->getCookieJar()->clear();
        $this->confirm($this->requestLink('resident@example.test', ['address' => $this->byId($home), 'listing' => self::listing()]));
        $this->signIn('org@example.test');
        $r = $this->json('GET', '/api/admin/addresses?q=31%20Lantern');
        self::assertSame(['id' => $ids['halloween'], 'name' => 'Halloween', 'type' => 'halloween', 'icon' => 'i-pumpkin'], $r['event'], 'the event whose listings the list shows');
        self::assertSame(['active', 'Handing out'], [$r['addresses'][0]['listing']['status'], $r['addresses'][0]['listing']['status_label']], 'its type\'s words for the status');
    }

    /**
     * The community with its own Halloween branding on its Halloween (given in the settings' 0.9 shape, as community.yaml
     * gives it) and Christmas lights and a yard sale opened next to it by an organizer, who stays signed in.
     *
     * @return array{halloween:int, lights:int, sale:int}
     */
    private function community(): array
    {
        $this->configureCommunity(['community' => ['id' => 'fernbrook-glen', 'name' => 'Fernbrook Glen', 'locality' => 'Larkfield', 'region' => 'OR', 'about' => self::CUSTOM_ABOUT],
            'branding' => self::CUSTOM_BRANDING, 'registration' => ['street_allowlist' => ['Lantern Way', 'Larkfield Rd']]]);
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, NOW())");
        $this->signIn('org@example.test');
        $this->csrf = $this->csrf();
        $ids = ['halloween' => (int) $this->db()->fetchOne("SELECT id FROM event_occurrences WHERE type = 'halloween'")];
        foreach (['lights' => ['holiday_lights', 'Christmas Lights', '2026-11-27', '2027-01-01'], 'sale' => ['garage_sale', 'Yard Sale', '2027-04-10', '2027-04-11']] as $name => [$type, $label, $from, $to]) {
            $ids[$name] = (int) $this->json('POST', '/api/admin/events', ['type' => $type, 'name' => $label, 'starts_on' => $from, 'ends_on' => $to], ['X-CSRF-Token' => $this->csrf])['event']['id'];
            $this->json('POST', '/api/admin/events/'.$ids[$name].'/open', ['confirm' => true], ['X-CSRF-Token' => $this->csrf]);
        }
        self::assertSame('👻 Tiny ghosts.', $this->json('GET', '/api/community')['branding']['headline'], 'the Halloween has its own branding');

        return $ids;
    }

    private string $csrf = '';
}
