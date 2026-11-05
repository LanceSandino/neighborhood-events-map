<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\CommunityBootstrapper;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\CustomHalloweenCommunity;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

/**
 * @module tests/Integration/PerEventBrandingTest
 * @description Community branding vs. each event's own branding (ADR 0012, D-20261031-46 to -50). Acceptance: a
 *   fictional community with its own Halloween branding (a ghost headline, a trick-or-treat intro, colours, an About
 *   text) and Halloween, Christmas lights and a yard sale open at once — the lights' and the sale's pages (map, About,
 *   sign-in, My house, the legal pages, What's new) carry no Halloween word outside the switcher's tab labels, and
 *   neither do their emails, while Halloween keeps its branding. Organizers set an event's branding under Events (every
 *   field optional, the type's defaults as placeholders, strict checks, kept when a client leaves it out, carried to
 *   next year's event, shown by the draft preview); the community console keeps only the community's own fields;
 *   community.yaml's branding brands the first event; the keys the 0.9 image reads follow the Halloween it shows.
 * @dependencies tests/Support/AppTestCase, tests/Support/CustomHalloweenCommunity, Community/CommunityBootstrapper
 */
final class PerEventBrandingTest extends AppTestCase
{
    use CustomHalloweenCommunity;

    /** Words of Halloween (the owner's list): none may show on another event's page or email. */
    private const HALLOWEEN = '~ghost|trick[- ]?or[- ]?treat|\btreats?\b|candy|candies|scare|pumpkin|🎃|👻~iu';
    /** The pages a visitor of one event can open. */
    private const PAGES = ['/', '/about', '/login', '/me', '/privacy', '/terms', '/accessibility', '/changelog'];

    private string $csrf = '';

    public function testTheOtherEventsCarryNoHalloweenWordWhileHalloweenKeepsItsBranding(): void
    {
        $ids = $this->freshCustomHalloween();
        foreach (['visitor', 'resident'] as $who) {
            if ('resident' === $who) {
                $this->signIn('resident@example.test'); // My house as a signed-in resident
            }
            foreach (['lights' => 'holiday_lights', 'sale' => 'garage_sale'] as $name => $type) {
                foreach ('resident' === $who ? ['/me', '/'] : array_diff(self::PAGES, ['/me']) as $path) {
                    $crawler = $this->client->request('GET', $path.'?e='.$ids[$name]);
                    self::assertSame(200, $this->code(), "$name $path ($who)");
                    self::assertSame($type, $crawler->filter('html')->attr('data-event-type'), "$name $path is the $name's page");
                    self::assertSame([], self::halloweenWords($crawler, $type), "$name $path ($who) shows no Halloween word");
                }
            }
        }
        $this->client->getCookieJar()->clear();
        foreach (['lights' => '/assets/img/favicon-lights.svg', 'sale' => '/assets/img/favicon-sale.svg'] as $name => $icon) {
            self::assertSame($icon, $this->client->request('GET', '/?e='.$ids[$name])->filter('link[rel="icon"]')->attr('href'), "$name: its own tab icon, not Halloween's pumpkin");
        }
        $home = $this->client->request('GET', '/');
        self::assertSame('/assets/img/favicon.svg', $home->filter('link[rel="icon"]')->attr('href'), 'Halloween keeps its icon');
        self::assertSame('👻 Tiny ghosts. Big adventures.', $home->filter('section.intro h1')->text(), 'Halloween keeps its own headline');
        self::assertStringContainsString('find the candy stops of Fernbrook Glen', $home->filter('section.intro p')->text());
        self::assertSame('🎃', $home->filter('.brand-icon')->text());
        self::assertStringContainsString("Fernbrook Glen's trick-or-treat night", $this->client->request('GET', '/about')->filter('#aboutTitle')->closest('section')->text());
        $this->client->request('GET', '/theme.css');
        self::assertStringContainsString('--brand:#D9652B', (string) $this->client->getResponse()->getContent());
        $tabs = $this->client->request('GET', '/?e='.$ids['lights'])->filter('.event-switch a')->each(static fn (Crawler $a) => $a->text());
        self::assertSame(['🎃Halloween', '✨Christmas Lights', '🏷️Yard Sale'], $tabs, 'the switcher names every event (its tab labels are the one place another event shows)');
    }

    public function testEmailsNameTheEventTheyConcernAndCarryNoOtherEventsWords(): void
    {
        $ids = $this->freshCustomHalloween();
        $this->requestLink('visitor@example.test');
        $signIn = self::lastMail();
        self::assertSame('Your Fernbrook Glen sign-in link', $signIn->getSubject(), 'an account is the community\'s: no event name');
        self::assertSame([], self::halloweenText($signIn->getSubject().' '.$signIn->getTextBody().' '.strip_tags((string) $signIn->getHtmlBody())), 'the sign-in link carries no event\'s words');

        $home = $this->addAddress('31', 'Lantern Way', 40.006, -75.004, 'west');
        $lights = ['start' => '18:00', 'end' => '21:00', 'details' => ['styles' => ['classic']],
            'windows' => [['date' => '2026-12-05', 'start' => '18:00', 'end' => '21:00']]];
        $r = $this->json('POST', '/api/auth/request-link?e='.$ids['lights'], ['email' => 'lights@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => $this->byId($home), 'listing' => $lights]]);
        self::assertSame(202, $this->code(), json_encode($r));
        $claim = self::lastMail();
        self::assertSame('Confirm your Fernbrook Glen Christmas Lights listing for 31 Lantern Way', $claim->getSubject(), 'the event of the listing, and its address');
        self::assertStringContainsString('✨ FERNBROOK GLEN CHRISTMAS LIGHTS', (string) $claim->getHtmlBody(), 'the lights\' own emoji');
        self::assertSame([], self::halloweenText($claim->getSubject().' '.$claim->getTextBody().' '.strip_tags((string) $claim->getHtmlBody())));

        // A review request from the sale's page names the sale (a home claimed by someone else).
        $taken = $this->addAddress('33', 'Lantern Way', 40.0062, -75.004, 'west');
        $this->confirm($this->requestLink('owner@example.test', ['address' => $this->byId($taken), 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $r = $this->json('POST', '/api/disputes?e='.$ids['sale'], ['address_id' => $taken, 'house_number' => '33', 'reporter_email' => 'neighbor@example.test',
            'reason' => str_repeat('This is my house and I can show the deed to the organizer. ', 5), 'pow' => $this->solvePow('dispute')]);
        self::assertSame(201, $this->code(), json_encode($r));
        $review = self::lastMail();
        self::assertMatchesRegularExpression('~^\[Fernbrook Glen Yard Sale\] Review request D-\d{6} for 33 Lantern Way$~', (string) $review->getSubject());
        self::assertSame([], self::halloweenText($review->getSubject().' '.$review->getTextBody()));
    }

    public function testOrganizersSetAnEventsBrandingUnderEvents(): void
    {
        $ids = $this->freshCustomHalloween();
        $this->organizer();
        $h = ['X-CSRF-Token' => $this->csrf];
        $types = array_column($this->json('GET', '/api/admin/events')['types'], null, 'key');
        self::assertSame(['brand_emoji' => '✨', 'headline' => 'Holiday nights.', 'headline_accent' => 'Bright lights.',
            'intro' => 'Find festive displays, discover quiet viewing options and plan an evening route at your own pace.', 'primary_color' => '#38597F', 'accent_color' => '#CE9D3D',
            'about' => 'A neighborhood map of homes with holiday light displays: their nights, hours and viewing notes, and a suggested viewing order for walking or a drive-by tour.'],
            $types['holiday_lights']['branding'], 'the type\'s defaults, the placeholders of an event\'s branding');

        $bad = $this->json('PUT', '/api/admin/events/'.$ids['lights'], ['settings' => ['branding' => ['headline' => str_repeat('x', 41), 'logo' => 'x.svg', 'accent_color' => 'gold']]], $h);
        self::assertSame(422, $this->code());
        self::assertSame(['settings.branding.headline', 'settings.branding.logo', 'settings.branding.accent_color'], array_keys($bad['error']['fields']));
        $r = $this->json('PUT', '/api/admin/events/'.$ids['lights'], ['settings' => ['rules' => ['Drive slowly'], 'branding' => ['headline' => 'Glen of lights.', 'headline_accent' => '',
            'accent_color' => '#aa3344', 'about' => 'Our lights season, every evening of December.']]], $h);
        self::assertSame(200, $this->code(), json_encode($r));
        $own = $r['event']['settings']['branding'];
        ksort($own);
        self::assertSame(['about' => 'Our lights season, every evening of December.', 'accent_color' => '#AA3344', 'headline' => 'Glen of lights.'], $own, 'an empty field is the type default');
        $page = $this->client->request('GET', '/?e='.$ids['lights']);
        self::assertSame('Glen of lights. Bright lights.', $page->filter('section.intro h1')->text());
        self::assertSame('Our lights season, every evening of December.', $this->client->request('GET', '/about?e='.$ids['lights'])->filter('#aboutTitle')->closest('section')->filter('p')->text());
        $this->client->request('GET', '/theme.css?e='.$ids['lights']);
        self::assertStringContainsString('--brand:#38597F;--brand-accent:#AA3344', (string) $this->client->getResponse()->getContent(), 'BrandPalette per event');
        self::assertSame('👻 Tiny ghosts. Big adventures.', $this->client->request('GET', '/')->filter('section.intro h1')->text(), 'Halloween is untouched');

        // A client that leaves the branding out keeps it; settings of a closed event's next year carry it.
        $this->json('PUT', '/api/admin/events/'.$ids['lights'], ['settings' => ['rules' => ['Drive slowly', 'Lights off at 10 PM']]], $h);
        self::assertSame(200, $this->code());
        self::assertSame('Glen of lights.', $this->json('GET', '/api/community?e='.$ids['lights'])['branding']['headline'], 'kept');
        $closed = $this->json('POST', '/api/admin/events/'.$ids['halloween'].'/close', ['confirm' => true], $h);
        self::assertSame(200, $this->code(), json_encode($closed));
        self::assertSame('👻 Tiny ghosts.', $closed['next']['settings']['branding']['headline'], 'next year\'s Halloween keeps the branding');
        self::assertSame('👻 Tiny ghosts. Big adventures.', $this->client->request('GET', '/admin/events/'.$closed['next']['id'].'/preview')->filter('.intro h1')->text(), 'its preview shows it (hidden: visitors do not see it)');
        self::assertSame('👻 Tiny ghosts. Big adventures.', $this->client->request('GET', '/?e='.$ids['halloween'])->filter('.intro h1')->text(), 'the closed Halloween keeps it');

        // A draft's preview shows the draft's own branding.
        $draft = $this->json('POST', '/api/admin/events', ['type' => 'garage_sale', 'name' => 'Fall Swap', 'starts_on' => '2027-09-11', 'ends_on' => '2027-09-11',
            'settings' => ['branding' => ['headline' => 'Swap and save.', 'primary_color' => '#225577']]], $h)['event'];
        self::assertSame(201, $this->code());
        $preview = $this->client->request('GET', '/admin/events/'.$draft['id'].'/preview');
        self::assertSame('Swap and save. Close to home.', $preview->filter('section.intro h1')->text());
        self::assertSame([], self::halloweenWords($preview, 'garage_sale'));
    }

    public function testTheKeysThe09ImageReadsFollowTheHalloweenItShows(): void
    {
        $ids = $this->freshCustomHalloween();
        $this->organizer();
        $h = ['X-CSRF-Token' => $this->csrf];
        self::assertSame(['🎃', '👻 Tiny ghosts.', '#D9652B', self::CUSTOM_ABOUT], $this->legacyKeys(), 'the stored keys are the Halloween\'s branding');
        $this->json('PUT', '/api/admin/events/'.$ids['lights'], ['settings' => ['branding' => ['headline' => 'Glen of lights.']]], $h);
        self::assertSame('👻 Tiny ghosts.', $this->legacyKeys()[1], 'another event\'s branding never reaches them');
        $this->json('PUT', '/api/admin/events/'.$ids['halloween'], ['settings' => ['branding' => ['headline' => 'Little owls.', 'about' => 'Owls night.']]], $h);
        self::assertSame(['🎃', 'Little owls.', '#F07936', 'Owls night.'], $this->legacyKeys(), 'the Halloween\'s change does, with its type\'s defaults where it has none');
        $this->json('POST', '/api/admin/events/'.$ids['halloween'].'/close', ['confirm' => true], $h);
        self::assertSame('Little owls.', $this->legacyKeys()[1], 'closed, it is still the Halloween the 0.9 image shows');
    }

    public function testTheCommunityConsoleKeepsOnlyTheCommunitysOwnFields(): void
    {
        $ids = $this->freshCustomHalloween();
        $this->organizer();
        $h = ['X-CSRF-Token' => $this->csrf];
        $s = $this->json('GET', '/api/admin/community')['settings'];
        self::assertSame(['logo', 'show_locality'], array_keys($s['branding']));
        self::assertArrayNotHasKey('about', $s['community'], 'each event\'s About text is the event\'s');
        self::assertSame('', $s['community']['general_about']);
        // Meanwhile an organizer renames the default event under Events; the console's save leaves the event alone.
        $this->json('PUT', '/api/admin/events/'.$ids['halloween'], ['name' => 'Halloween Night'], $h);
        unset($s['event']);
        $s['community']['general_about'] = 'Fernbrook Glen is forty homes around Larkfield Pond.';
        $r = $this->json('PUT', '/api/admin/community', ['settings' => $s], $h);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertSame('Halloween Night', $this->json('GET', '/api/event')['event']['name'], 'no event block: the default event keeps its own values');
        self::assertSame('Fernbrook Glen is forty homes around Larkfield Pond.', $this->json('GET', '/api/community?e='.$ids['sale'])['community']['about'], 'an event without its own About text shows the general one');
        self::assertSame(self::CUSTOM_ABOUT, $this->json('GET', '/api/community')['community']['about'], 'Halloween keeps its own');
        self::assertSame('👻 Tiny ghosts.', $this->json('GET', '/api/community')['branding']['headline']);
    }

    public function testCommunityYamlBrandsTheFirstEventAndAnExplicitEventBrandingWins(): void
    {
        $boot = static::getContainer()->get(CommunityBootstrapper::class);
        self::assertSame('imported', $boot->bootstrap('examples/demo/community.yaml', false, true)['status']);
        $this->repo()->reset();
        $event = $this->db()->fetchOne("SELECT settings->'branding' FROM event_occurrences");
        self::assertEquals(['brand_emoji' => '🦇', 'primary_color' => '#2E7D6B', 'accent_color' => '#6B4FA0', 'headline' => 'Spooky streets.', 'headline_accent' => 'Sweet treats.',
            'intro' => 'Fictional demo: explore how trick-or-treaters find treats and plan stops.',
            'about' => "Maple Hollow is a FICTIONAL neighborhood used to demonstrate the app. Every home, street and note here is invented.\n\nAnyone can browse the map, filter for gentle or spooky stops and plan a route; residents list their own homes with a one-time email link."],
            json_decode((string) $event, true), 'the 0.9-shaped branding and About text are the first event\'s');
        self::assertSame('Spooky streets.', (string) $this->db()->fetchOne("SELECT settings->'branding'->>'headline' FROM community_settings"), 'and the 0.9 keys mirror it');

        $file = sys_get_temp_dir().'/branding-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($file, "community: {id: owlwood, name: Owlwood, timezone: America/Chicago, general_about: 'Owlwood is a fictional lane.'}\n"
            ."event: {type: holiday_lights, name: Owlwood Lights, date: '2026-12-01', ends_on: '2026-12-31', settings: {branding: {headline: 'Owl lights.'}}}\n"
            ."branding: {headline: 'Ignored here.', intro: 'Lights along the lane.', primary_color: '#38597F'}\n");
        $this->db()->executeStatement('TRUNCATE community_settings, event_occurrences RESTART IDENTITY CASCADE');
        $this->repo()->reset();
        self::assertSame('imported', $boot->bootstrap($file, false, false)['status']);
        $this->repo()->reset();
        $c = $this->json('GET', '/api/community');
        self::assertSame(['Owl lights.', 'Bright lights.', 'Lights along the lane.', '#38597F', 'Owlwood is a fictional lane.'],
            [$c['branding']['headline'], $c['branding']['headline_accent'], $c['branding']['intro'], $c['branding']['primary_color'], $c['community']['about']],
            'event.settings.branding wins over the top-level branding; the lights\' blue is its own default; the general About text is the community\'s');
        unlink($file);
    }

    /**
     * The acceptance community built on this release: the custom Halloween branding given in the settings' 0.9 shape (as
     * community.yaml gives it) brands the first event; Christmas lights and a yard sale open next to it.
     *
     * @return array{halloween:int, lights:int, sale:int}
     */
    private function freshCustomHalloween(): array
    {
        $this->configureCommunity(['community' => ['id' => 'fernbrook-glen', 'name' => 'Fernbrook Glen', 'locality' => 'Larkfield', 'region' => 'OR', 'about' => self::CUSTOM_ABOUT],
            'branding' => self::CUSTOM_BRANDING, 'registration' => ['street_allowlist' => ['Lantern Way', 'Larkfield Rd']]]);
        $ids = $this->openLightsAndSale();
        $this->client->getCookieJar()->clear();

        return $ids;
    }

    /** @return array{halloween:int, lights:int, sale:int} */
    private function openLightsAndSale(): array
    {
        $this->organizer();
        $h = ['X-CSRF-Token' => $this->csrf];
        $ids = ['halloween' => (int) $this->db()->fetchOne("SELECT id FROM event_occurrences WHERE type = 'halloween'")];
        foreach (['lights' => ['holiday_lights', 'Christmas Lights', '2026-11-27', '2027-01-01'], 'sale' => ['garage_sale', 'Yard Sale', '2027-04-10', '2027-04-11']] as $name => [$type, $label, $from, $to]) {
            $ids[$name] = (int) $this->json('POST', '/api/admin/events', ['type' => $type, 'name' => $label, 'starts_on' => $from, 'ends_on' => $to], $h)['event']['id'];
            $this->json('POST', '/api/admin/events/'.$ids[$name].'/open', ['confirm' => true], $h);
            self::assertSame(200, $this->code(), "$name opens");
        }

        return $ids;
    }

    private function organizer(): void
    {
        if (false === $this->db()->fetchOne("SELECT 1 FROM users WHERE email_normalized = 'org@example.test'")) {
            $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, NOW())");
        }
        $this->signIn('org@example.test');
        $this->csrf = $this->csrf();
    }

    /** @return list<string> the stored keys the 0.9 image reads: emoji, headline, primary colour, About text */
    private function legacyKeys(): array
    {
        $s = json_decode((string) $this->db()->fetchOne('SELECT settings FROM community_settings WHERE id = 1'), true, 64, \JSON_THROW_ON_ERROR);

        return [$s['branding']['brand_emoji'], $s['branding']['headline'], $s['branding']['primary_color'], $s['community']['about']];
    }

    private static function lastMail(): Email
    {
        $messages = self::getMailerMessages();
        $mail = end($messages);
        \assert($mail instanceof Email);

        return $mail;
    }

    /**
     * Halloween words a visitor of the page can read: its text and the text of its attributes (titles, labels,
     * placeholders, alternative text, the description), leaving out the event switcher's tab labels and a closed event's
     * thank-you message (they name other events on purpose) and the scripts' data. The lights' own "Treats" (a lights
     * listing may offer treats and cocoa) is the lights' word.
     *
     * @return list<string>
     */
    private static function halloweenWords(Crawler $page, string $type): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$page->outerHtml(), \LIBXML_NOERROR);
        $xpath = new \DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//script|//style|//nav[contains(@class,"event-switch")]|//*[contains(@class,"event-status-message")]') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
        $texts = [(string) $doc->textContent];
        foreach ($xpath->query('//@title|//@aria-label|//@placeholder|//@alt|//meta[@name="description"]/@content') ?: [] as $attr) {
            $texts[] = (string) $attr->nodeValue;
        }
        $text = implode(' ', $texts);
        if ('' !== (string) getenv('NHM_DEBUG_WORDS')) {
            preg_match_all('~.{0,60}('.trim(self::HALLOWEEN, '~iu').').{0,60}~iu', $text, $ctx);
            fwrite(\STDERR, implode("\n", $ctx[0])."\n");
        }
        if ('holiday_lights' === $type) {
            $text = str_replace(['🍬 Treats', 'Music, flashing and treats'], '', $text); // the lights' listing option and its group
        }

        return self::halloweenText($text);
    }

    /** @return list<string> */
    private static function halloweenText(string $text): array
    {
        preg_match_all(self::HALLOWEEN, $text, $m);

        return array_values(array_unique($m[0]));
    }
}
