<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: privacy and accessibility best practice — public pages set no cookies and load no
 * third-party scripts; a privacy notice, terms of use and accessibility statement are linked from
 * every page; the organizer's contact link is offered for requests.
 */
final class LegalPagesTest extends AppTestCase
{
    public function testPolicyPagesExistAndDescribeWhatTheSiteDoes(): void
    {
        $this->configureCommunity(['community' => ['contact_url' => 'mailto:organizer@example.test']]);
        $pages = [
            '/privacy' => ['no advertising, no analytics and no tracking', 'local storage', 'OpenStreetMap', 'strictly necessary session cookie', 'btnClearLocal'],
            '/terms' => ['self-reported', 'not walking directions', 'without warranties'],
            '/accessibility' => ['WCAG', 'Colour is never the only signal', 'keyboard'],
        ];
        foreach ($pages as $path => $needles) {
            $html = (string) $this->client->request('GET', $path)->html();
            self::assertSame(200, $this->code(), $path);
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $html, "$path mentions $needle");
            }
            self::assertStringContainsString('href="mailto:organizer@example.test"', $html, "$path offers the organizer contact");
        }
    }

    public function testTheLegalPagesUseTheWordsOfTheEventOnTheMap(): void
    {
        $events = [
            'halloween' => [[], '🍬 treats, 🧩 sensory-friendly, 👻 spooky', 'treats and sensory details'],
            'holiday_lights' => [['type' => 'holiday_lights', 'name' => 'Christmas Lights', 'date' => '2026-12-01', 'starts_on' => '2026-12-01', 'ends_on' => '2027-01-03', 'starts_at' => '18:00', 'ends_at' => '22:00'],
                '✨ lights, 🎵 music, ⛄ inflatables', 'display details and flashing reports'],
            'garage_sale' => [['type' => 'garage_sale', 'name' => 'Garage Sale', 'date' => '2027-04-10', 'starts_on' => '2027-04-10', 'ends_on' => '2027-04-11', 'starts_at' => '08:00', 'ends_at' => '14:00'],
                '🧸 toys, 🪑 furniture, 🛠️ tools', 'categories, payment kinds and notes'],
        ];
        foreach ($events as $type => [$event, $icons, $details]) {
            $this->db()->executeStatement('DELETE FROM event_occurrences'); // a fresh install of each type (an event's type never changes)
            $this->configureCommunity(['event' => $event]);
            $a11y = (string) $this->client->request('GET', '/accessibility')->html();
            self::assertStringContainsString('pins carry icons ('.$icons.') and every tag has words', $a11y, $type);
            $terms = (string) $this->client->request('GET', '/terms')->html();
            self::assertStringContainsString('Homes, hours, '.$details.' are entered by residents', $terms, $type);
            $register = (string) $this->client->request('GET', '/')->html();
            self::assertStringContainsString('e.g. '.('halloween' === $type ? '123 Pumpkin Ln' : '123 Main St').'…', $register, $type.': the address example');
            if ('halloween' !== $type) {
                foreach (['spooky', 'treats and ingredients', 'jump scares', 'Pumpkin'] as $word) {
                    self::assertStringNotContainsString($word, $a11y.$terms.$register, $type.' pages say nothing of Halloween: '.$word);
                }
            }
        }
    }

    public function testEveryPublicPageLinksThePoliciesAndSetsNoCookies(): void
    {
        $this->configureCommunity();
        foreach (['/', '/login', '/privacy', '/terms', '/accessibility', '/api/community', '/api/homes', '/theme.css'] as $path) {
            $this->client->getCookieJar()->clear();
            $this->client->request('GET', $path);
            self::assertSame(200, $this->code(), $path);
            self::assertSame([], $this->client->getResponse()->headers->getCookies(), "$path sets no cookie for a visitor");
            if (!str_starts_with($path, '/api') && '/theme.css' !== $path) {
                $html = (string) $this->client->getResponse()->getContent();
                foreach (['href="/privacy"', 'href="/terms"', 'href="/accessibility"'] as $link) {
                    self::assertStringContainsString($link, $html, "$path links $link");
                }
                self::assertDoesNotMatchRegularExpression('~<script[^>]+src="https?://~i', $html, "$path loads no third-party script");
            }
        }
    }

    public function testAboutPageShowsTheCommunityStoryAccessAndLinks(): void
    {
        $this->configureCommunity(['community' => [
            'contact_url' => 'mailto:organizer@example.test', 'access' => 'gated_guests',
            'about' => "Our neighborhood loves Halloween.\n\nParade at <b>6 PM</b>.",
            'links' => [['label' => 'Facebook group', 'url' => 'https://www.facebook.com/groups/example']],
            'gate_notice' => 'Enter by the north gate.',
        ]]);
        $html = (string) $this->client->request('GET', '/about')->html();
        self::assertSame(200, $this->code());
        self::assertStringContainsString('Our neighborhood loves Halloween.', $html);
        self::assertStringContainsString('Parade at &lt;b&gt;6 PM&lt;/b&gt;.', $html, 'organizer text is escaped');
        self::assertStringContainsString('Gated — residents and guests', $html);
        self::assertStringContainsString('Enter by the north gate.', $html);
        self::assertStringContainsString('href="https://www.facebook.com/groups/example" rel="noopener noreferrer"><span aria-hidden="true">🔗</span>Facebook group</a>', $html);
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertStringContainsString('href="/about"', (string) $this->client->request('GET', '/')->html(), 'linked from the map');
        self::assertStringContainsString('Gated — residents and guests', (string) $this->client->getResponse()->getContent(), 'the map\'s info sheet shows access too');
    }

    public function testRemindersDefaultOrOrganizerOwnAndTheDateLinksToAbout(): void
    {
        $this->configureCommunity();
        $html = (string) $this->client->request('GET', '/about')->html();
        self::assertStringContainsString('id="reminders"', $html);
        self::assertStringContainsString('Take one piece of candy unless a sign or the resident says otherwise.', $html);
        self::assertStringContainsString('Look, don’t touch: decorations', $html);
        self::assertStringContainsString('Watch for cars.', $html);
        $home = (string) $this->client->request('GET', '/')->html();
        self::assertStringContainsString('<a class="event-pill" href="/about"', $home, 'the event date opens the About page');
        self::assertStringContainsString('<a class="secondary" href="/about">Full guide &amp; links</a>', $home, 'the map guide links the full guide and reminders');

        $this->configureCommunity(['community' => ['reminders' => ['Costume parade at 6 PM by the pool.', '  ']]]);
        $html = (string) $this->client->request('GET', '/about')->html();
        self::assertStringContainsString('Costume parade at 6 PM by the pool.', $html);
        self::assertStringNotContainsString('Watch for cars.', $html, 'the organizer list replaces the defaults');

        $this->expectException(\App\Community\ConfigException::class);
        $this->expectExceptionMessage('community.reminders can have at most 12 lines.');
        $this->configureCommunity(['community' => ['reminders' => array_fill(0, 13, 'Be nice.')]]);
    }

    public function testPagesStillRenderForSettingsSavedBeforeTheAboutFieldsExisted(): void
    {
        $this->configureCommunity();
        $this->db()->executeStatement("UPDATE community_settings SET settings = settings #- '{community,about}' #- '{community,access}' #- '{community,links}'");
        $this->repo()->reset();
        foreach (['/about', '/', '/privacy'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(200, $this->code(), $path);
        }
    }
}
