<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\CommunityBootstrapper;
use App\Community\LogoStore;
use App\Event\EventLifecycle;
use App\Event\EventOccurrence;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\CustomHalloweenCommunity;
use App\Tests\Support\RunsMigrations;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * @module tests/Integration/LogoStorageTest
 * @description Logos in the database (D-20261105-03): an event's page shows its own logo, else its type's drawn mark, and
 *   the console and pages with no event show the community's logo, else a neutral mark; uploads pass the same checks for
 *   an event as for the community and URLs change with the content; a hidden or draft event's logo answers visitors like an
 *   unknown id; next year's event keeps the logo. The migration copies a volume logo into the community and every Halloween,
 *   leaving the file. The settings key and the volume file the 0.9 image reads stay in step with the mirrored Halloween's
 *   logo, else the community's. Emails use the logo as a bitmap or the drawn mark.
 * @dependencies tests/Support/AppTestCase, tests/Support/CustomHalloweenCommunity, tests/Support/RunsMigrations, Community/LogoStore
 */
final class LogoStorageTest extends AppTestCase
{
    use CustomHalloweenCommunity;
    use RunsMigrations;

    private const V018 = 'DoctrineMigrations\Version20261101000001';
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="orange"/></svg>';
    private const SVG2 = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="3" fill="purple"/></svg>';

    private string $csrf = '';

    protected function tearDown(): void
    {
        foreach (glob($this->volume().'/logo.*') ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testEachPageShowsItsOwnLogoThenItsDrawnMark(): void
    {
        $ids = $this->organizer();
        foreach ([['lights', 'brand-lights'], ['sale', 'brand-sale'], ['halloween', 'brand-halloween']] as [$name, $mark]) {
            $page = $this->client->request('GET', '/?e='.$ids[$name]);
            self::assertStringEndsWith('#'.$mark, (string) $page->filter('.brand-icon svg use')->attr('href'), "$name: its type's mark");
            self::assertSame(0, $page->filter('.brand-icon img')->count());
        }
        $this->upload(self::SVG, 'lights.svg', $ids['lights']);
        self::assertSame(200, $this->code(), (string) $this->client->getResponse()->getContent());
        $page = $this->client->request('GET', '/?e='.$ids['lights']);
        $url = (string) $page->filter('.brand-icon img')->attr('src');
        self::assertMatchesRegularExpression('~^/branding/event-'.$ids['lights'].'/logo\.svg\?v=[0-9a-f]{10}$~', $url, 'the event\'s logo, with a content version');
        self::assertSame($url, $page->filter('link[rel="icon"]')->attr('href'), 'and the browser tab');
        self::assertSame(1, $this->client->request('GET', '/?e='.$ids['sale'])->filter('.brand-icon svg')->count(), 'another event keeps its mark');

        $this->upload(self::SVG2, 'lights.svg', $ids['lights']);
        $newUrl = (string) $this->client->request('GET', '/?e='.$ids['lights'])->filter('.brand-icon img')->attr('src');
        self::assertNotSame($url, $newUrl, 'a replaced logo gets a new URL');
        $this->client->request('GET', $newUrl);
        self::assertSame(200, $this->code());
        self::assertSame(self::SVG2, $this->client->getResponse()->getContent());
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'its URL changes with its content');
        $this->client->request('GET', $url);
        self::assertStringNotContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'an old version in the URL is not kept for long');
        self::assertStringContainsString('image/svg+xml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

        $this->removeLogo($ids['lights']);
        $page = $this->client->request('GET', '/?e='.$ids['lights']);
        self::assertSame(0, $page->filter('.brand-icon img')->count(), 'removed: the drawn mark again');
        self::assertStringEndsWith('#brand-lights', (string) $page->filter('.brand-icon svg use')->attr('href'));
    }

    public function testTheConsoleAndNeutralPagesUseTheCommunitysLogo(): void
    {
        $ids = $this->organizer();
        $console = $this->client->request('GET', '/admin');
        self::assertStringEndsWith('#brand-community', (string) $console->filter('.admin-top .brand-icon svg use')->attr('href'), 'a neutral mark without a logo');
        $this->upload(self::SVG, 'community.svg');
        self::assertSame(200, $this->code());
        $console = $this->client->request('GET', '/admin');
        $url = (string) $console->filter('.admin-top .brand-icon img')->attr('src');
        self::assertMatchesRegularExpression('~^/branding/logo\.svg\?v=[0-9a-f]{10}$~', $url);
        self::assertSame($url, $console->filter('link[rel="icon"]')->attr('href'));
        self::assertSame(0, $this->client->request('GET', '/?e='.$ids['lights'])->filter('.brand-icon img')->count(), 'an event\'s page does not take the community\'s logo');

        // Between events the page is the community's.
        $this->db()->executeStatement("UPDATE event_occurrences SET state = 'archived', is_default = FALSE, archived_at = NOW()");
        $this->repo()->reset();
        $page = $this->client->request('GET', '/');
        self::assertSame($url, $page->filter('.brand-icon img')->attr('src'));
        $this->removeLogo();
        $page = $this->client->request('GET', '/');
        self::assertStringEndsWith('#brand-community', (string) $page->filter('.brand-icon svg use')->attr('href'));
        self::assertSame('/assets/img/favicon-community.svg', $page->filter('link[rel="icon"]')->attr('href'));
    }

    public function testAnEventLogoPassesTheSameChecks(): void
    {
        $ids = $this->organizer();
        foreach ([
            ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
            ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject></foreignObject></svg>'],
            ['logo.txt', 'hello'],
            ['logo.png', 'not an image'],
            ['logo.png', self::SVG],
        ] as [$name, $content]) {
            $this->upload($content, $name, $ids['lights']);
            self::assertSame(422, $this->code(), "$name refused: $content");
            self::assertNull(static::getContainer()->get(LogoStore::class)->url($ids['lights']));
        }
        $this->client->request('POST', '/api/admin/community/logo', ['_csrf' => $this->csrf, 'event' => '99999', 'remove' => '1']);
        self::assertSame(404, $this->code(), 'an unknown event');
        $this->client->request('GET', '/branding/event-'.$ids['lights'].'/logo.php');
        self::assertSame(404, $this->code());
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/admin/community/logo', ['event' => (string) $ids['lights'], 'remove' => '1']);
        self::assertContains($this->code(), [401, 403], 'organizers only');
    }

    public function testAHiddenOrDraftEventsLogoAnswersVisitorsLikeAnUnknownId(): void
    {
        $ids = $this->organizer();
        $events = static::getContainer()->get(EventLifecycle::class);
        $admin = (int) $this->db()->fetchOne("SELECT id FROM users WHERE email_normalized = 'logo-organizer@example.test'");
        $draft = $events->createDraft(['type' => 'halloween', 'name' => 'Draft Halloween', 'starts_on' => '2029-10-31', 'ends_on' => '2029-10-31', 'default_start' => '17:00', 'default_end' => '21:00'], $admin)->id;
        $this->upload(self::SVG, 'draft.svg', $draft);
        self::assertSame(200, $this->code(), (string) $this->client->getResponse()->getContent());
        $url = '/branding/event-'.$draft.'/logo.svg';
        $this->client->request('GET', $url);
        self::assertSame(200, $this->code(), 'an organizer sees it');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'never cached');
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        self::assertSame(404, $this->code(), 'a visitor gets what an unknown id gets');
        $this->client->request('GET', '/branding/event-99999/logo.svg');
        self::assertSame(404, $this->code());
        unset($ids);
    }

    public function testNextYearsEventKeepsTheLogo(): void
    {
        $ids = $this->organizer();
        $this->upload(self::SVG, 'halloween.svg', $ids['halloween']);
        $admin = (int) $this->db()->fetchOne("SELECT id FROM users WHERE email_normalized = 'logo-organizer@example.test'");
        $result = static::getContainer()->get(EventLifecycle::class)->close($ids['halloween'], null, $admin);
        self::assertInstanceOf(EventOccurrence::class, $result['next']);
        $logos = static::getContainer()->get(LogoStore::class);
        self::assertSame($logos->meta($ids['halloween'])['sha256'], $logos->meta($result['next']->id)['sha256']);
    }

    public function testTheMigrationCopiesAVolumeLogoAndLeavesTheFile(): void
    {
        try {
            $ids = $this->organizer();
            // A logo file in the volume, named by the settings, as 0.18 kept it; another type's event takes nothing.
            @mkdir($this->volume(), 0o775, true);
            file_put_contents($this->volume().'/logo.svg', self::SVG);
            $this->db()->executeStatement("UPDATE community_settings SET settings = jsonb_set(settings, '{branding,logo}', '\"logo.svg\"')");
            $this->migrateTo(self::V018);
            $this->migrateTo('latest');
            $this->repo()->reset();

            $logos = static::getContainer()->get(LogoStore::class);
            self::assertNotNull($logos->meta(null), 'the community\'s');
            self::assertNotNull($logos->meta($ids['halloween']), 'every Halloween\'s, so its page looks unchanged');
            self::assertNull($logos->meta($ids['lights']));
            self::assertNull($logos->meta($ids['sale']));
            self::assertSame(self::SVG, $logos->load($ids['halloween'])['bytes']);
            self::assertSame(self::SVG, file_get_contents($this->volume().'/logo.svg'), 'the file stays for the 0.9 image');
            self::assertSame('logo.svg', $this->storedLogo(), 'and the key naming it');
            $page = $this->client->request('GET', '/?e='.$ids['halloween']);
            self::assertStringStartsWith('/branding/event-'.$ids['halloween'].'/logo.svg?v=', (string) $page->filter('.brand-icon img')->attr('src'));

            // Migrating twice over the same data neither fails nor resurrects anything removed since.
            $this->migrateTo(self::V018);
            self::assertSame(self::SVG, file_get_contents($this->volume().'/logo.svg'), 'down leaves the file');
        } finally {
            $this->migrateTo('latest');
        }
    }

    public function testTheKeysThe09ImageReadsStayInStep(): void
    {
        $ids = $this->organizer();
        self::assertNull($this->storedLogo());
        $this->upload(self::SVG, 'community.svg');
        self::assertSame('logo.svg', $this->storedLogo(), 'the community\'s, while the Halloween has none');
        self::assertSame(self::SVG, file_get_contents($this->volume().'/logo.svg'));

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $this->upload((string) $png, 'halloween.png', $ids['halloween']);
        self::assertSame(200, $this->code(), (string) $this->client->getResponse()->getContent());
        self::assertSame('logo.png', $this->storedLogo(), 'the Halloween 0.9 shows wins');
        self::assertSame($png, file_get_contents($this->volume().'/logo.png'));
        self::assertFileDoesNotExist($this->volume().'/logo.svg');

        $this->upload(self::SVG2, 'lights.svg', $ids['lights']);
        self::assertSame('logo.png', $this->storedLogo(), 'another type\'s logo is nothing to 0.9');

        $this->removeLogo($ids['halloween']);
        self::assertSame('logo.svg', $this->storedLogo(), 'back to the community\'s');
        self::assertSame(self::SVG, file_get_contents($this->volume().'/logo.svg'));
        self::assertFileDoesNotExist($this->volume().'/logo.png');

        $this->removeLogo();
        self::assertNull($this->storedLogo(), 'none left');
        self::assertFileDoesNotExist($this->volume().'/logo.svg');
    }

    public function testEmailsShowTheLogoAsABitmapOrTheDrawnMark(): void
    {
        $ids = $this->organizer();
        $this->client->getCookieJar()->clear();
        $this->requestLink('email-visitor@example.test');
        $html = (string) self::lastMail()->getHtmlBody();
        self::assertStringContainsString('/assets/img/email/brand-community.png', $html, 'the sign-in link is the community\'s: its neutral mark');
        self::assertSame([], \App\Tests\Support\Glyphs::in($html.self::lastMail()->getSubject()));
        self::assertStringContainsString('background:#38465C', $html, 'on no event\'s colours');

        // A claim is the event's: its mark while it has no logo or only an SVG one, which mail clients do not draw; a bitmap logo itself.
        $claim = function (string $house, float $lat) use ($ids): string {
            $home = $this->addAddress($house, 'Lantern Way', $lat, -75.004, 'west');
            $this->json('POST', '/api/auth/request-link?e='.$ids['lights'], ['email' => 'claim'.$house.'@example.test', 'pow' => $this->solvePow('claim'), 'claim' => ['address' => $this->byId($home),
                'listing' => ['start' => '18:00', 'end' => '21:00', 'details' => ['styles' => ['classic']], 'windows' => [['date' => '2026-12-05', 'start' => '18:00', 'end' => '21:00']]]]]);
            self::assertSame(202, $this->code());

            return (string) self::lastMail()->getHtmlBody();
        };
        self::assertStringContainsString('/assets/img/email/brand-lights.png', $claim('31', 40.006));
        $logos = static::getContainer()->get(LogoStore::class);
        $logos->put($ids['lights'], 'svg', self::SVG);
        self::assertStringContainsString('/assets/img/email/brand-lights.png', $claim('32', 40.0061), 'an SVG logo gives way to the mark');
        $logos->put($ids['lights'], 'png', 'bitmap-bytes');
        self::assertStringContainsString('/branding/event-'.$ids['lights'].'/logo.png?v=', $claim('33', 40.0062), 'a bitmap logo shows');
    }

    public function testCommunityYamlLogoIsTheCommunitysAndTheFirstEvents(): void
    {
        $dir = sys_get_temp_dir().'/nhm-logo-'.bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir.'/mark.svg', self::SVG);
            $yaml = (string) file_get_contents(\dirname(__DIR__, 2).'/examples/demo/community.yaml');
            $yaml = (string) preg_replace('~^branding:\n~m', "branding:\n  logo: mark.svg\n", $yaml, 1);
            $yaml = (string) preg_replace('~^(\s*(boundary_file|zones_file|zones_files|addresses_file):).*$~m', '$1 null', $yaml);
            file_put_contents($dir.'/community.yaml', $yaml);
            $result = static::getContainer()->get(CommunityBootstrapper::class)->bootstrap($dir.'/community.yaml', true, false);
            self::assertSame('imported', $result['status']);
            $logos = static::getContainer()->get(LogoStore::class);
            self::assertNotNull($logos->meta(null));
            $first = (int) $this->db()->fetchOne('SELECT id FROM event_occurrences ORDER BY id LIMIT 1');
            self::assertNotNull($logos->meta($first));
        } finally {
            @unlink($dir.'/mark.svg');
            @unlink($dir.'/community.yaml');
            @rmdir($dir);
        }
    }

    /** @return array{halloween:int, lights:int, sale:int} */
    private function organizer(): array
    {
        $ids = $this->customHalloweenCommunity();
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('logo-organizer@example.test', TRUE, NOW()) ON CONFLICT DO NOTHING");
        $this->signIn('logo-organizer@example.test');
        $this->csrf = $this->csrf();

        return $ids;
    }

    private function upload(string $content, string $name, ?int $event = null): void
    {
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, $content);
        $file = new UploadedFile($path, $name, null, null, true);
        $this->client->request('POST', '/api/admin/community/logo', ['_csrf' => $this->csrf] + (null === $event ? [] : ['event' => (string) $event]), ['logo' => $file]);
    }

    private function removeLogo(?int $event = null): void
    {
        $this->client->request('POST', '/api/admin/community/logo', ['_csrf' => $this->csrf, 'remove' => '1'] + (null === $event ? [] : ['event' => (string) $event]));
        self::assertSame(200, $this->code());
    }

    private function volume(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/var/branding';
    }

    private function storedLogo(): ?string
    {
        $v = $this->db()->fetchOne("SELECT settings->'branding'->>'logo' FROM community_settings WHERE id = 1");

        return false === $v ? null : $v;
    }

    private static function lastMail(): Email
    {
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }
}
