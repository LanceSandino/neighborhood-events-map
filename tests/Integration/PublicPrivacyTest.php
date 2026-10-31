<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: ACCEPTANCE #2, #12, #13 — public endpoints never expose unclaimed inventory, hidden or
 * out-of-season listings, drafts or archived events, emails or tokens; security headers/CSP; no fake pins. The home
 * keys are the 0.9 ones plus exactly the event-scoped ones (D-20261031-09).
 */
final class PublicPrivacyTest extends AppTestCase
{
    /** A public home's keys in 0.9.0-beta.1 (and 0.10), in their order. */
    private const KEYS_09 = ['id', 'address', 'zone', 'lat', 'lon', 'start', 'end', 'candy', 'nonfood', 'adult_drinks', 'low_noise', 'jump_scares',
        'flashing_lights', 'loud_audio', 'scare_level', 'stroller_friendly', 'note', 'status', 'updated_at'];
    /** The keys event-scoped listings add (0.11), nothing else. */
    private const EVENT_KEYS = ['event_id', 'event_type', 'details', 'windows', 'display_status'];

    public function testOnlyOptedInCurrentSeasonListingsArePublic(): void
    {
        $this->configureCommunity();
        $a = $this->addAddress('1', 'Pumpkin Ln', 40.004, -75.004, 'west');
        $b = $this->addAddress('3', 'Pumpkin Ln', 40.004, -75.005, 'west');
        $this->addAddress('5', 'Pumpkin Ln', 40.004, -75.006, 'west'); // never claimed
        $this->confirm($this->requestLink('a@example.test', ['address' => $this->byId($a), 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $this->confirm($this->requestLink('b@example.test', ['address' => $this->byId($b), 'listing' => self::listing()]));
        $this->db()->executeStatement("UPDATE listings SET event_date = '2025-10-31' WHERE address_id = ?", [$b]);

        $this->client->request('GET', '/api/homes');
        $raw = (string) $this->client->getResponse()->getContent();
        $homes = json_decode($raw, true)['homes'];
        self::assertSame(['1 Pumpkin Ln'], array_column($homes, 'address'));
        foreach (['@', '5 Pumpkin', 'user', 'token', 'claimed_at', 'normalized'] as $needle) {
            self::assertStringNotContainsString($needle, $raw);
        }
        // The 0.9 keys in their order, then (D-20261031-09, widened on purpose) exactly the event-scoped ones.
        self::assertSame([...self::KEYS_09, ...self::EVENT_KEYS], array_keys($homes[0]));
        $this->json('GET', '/api/homes/'.$b);
        self::assertSame(404, $this->code(), 'last season is not public');
        $c = (string) json_encode($this->json('GET', '/api/community'));
        self::assertStringNotContainsString('@example.test', $c);
        self::assertStringNotContainsString('organizer', strtolower($c));
    }

    /**
     * Widening the whitelist changed no 0.9 key or value: the 0.9 part of a home is what 0.10 published for the same
     * listing (its flat details equal the new details object), and the new keys say nothing private.
     */
    public function testTheNewKeysLeaveThe09KeysAndValuesUnchanged(): void
    {
        $this->configureCommunity();
        $a = $this->addAddress('1', 'Pumpkin Ln', 40.004, -75.004, 'west');
        $this->confirm($this->requestLink('a@example.test', ['address' => $this->byId($a), 'listing' => self::listing(['nonfood' => true, 'jump_scares' => true, 'scare_level' => 'mild'])]));
        $home = $this->json('GET', '/api/homes')['homes'][0];
        self::assertSame($home, $this->json('GET', '/api/homes/'.$a)['home'], 'the list and the single home agree');
        $row = $this->db()->fetchAssociative('SELECT start_local, end_local, candy, nonfood, adult_drinks, low_noise, jump_scares, flashing_lights, loud_audio, scare_level,
            stroller_friendly, note, status, updated_at FROM listings WHERE address_id = ?', [$a]);
        self::assertIsArray($row);
        $expected = ['id' => $a, 'address' => '1 Pumpkin Ln', 'zone' => 'west', 'lat' => 40.004, 'lon' => -75.004, 'start' => '17:30', 'end' => '20:30',
            'candy' => true, 'nonfood' => true, 'adult_drinks' => false, 'low_noise' => true, 'jump_scares' => true, 'flashing_lights' => false, 'loud_audio' => false,
            'scare_level' => 'mild', 'stroller_friendly' => false, 'note' => 'Porch light on.', 'status' => 'active',
            'updated_at' => (new \DateTimeImmutable((string) $row['updated_at']))->format(\DATE_ATOM)];
        self::assertSame($expected, array_intersect_key($home, array_flip(self::KEYS_09)), 'the 0.9 keys keep their values (the legacy row says the same)');
        self::assertSame(array_intersect_key($expected, (new \App\Event\Type\HalloweenType())->defaultDetails()), $home['details'], 'details is the flat details as an object');
        self::assertSame([['date' => '2026-10-31', 'start' => '17:30', 'end' => '20:30']], $home['windows']);
        self::assertSame('halloween', $home['event_type']);
        self::assertSame($this->json('GET', '/api/event')['event']['id'], $home['event_id']);
        self::assertSame('open', $home['display_status'], '6 PM in New York on the event day');
    }

    /** Drafts and archived events are never public: not their homes, not their names. */
    public function testNoDraftOrArchivedEventIsEverPublic(): void
    {
        $this->configureCommunity();
        $a = $this->addAddress('1', 'Pumpkin Ln', 40.004, -75.004, 'west');
        $this->confirm($this->requestLink('a@example.test', ['address' => $this->byId($a), 'listing' => self::listing()]));
        $db = $this->db();
        $draft = (int) $db->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
            VALUES ('halloween', 'Secret Draft Night', '2027-10-31', '2027-10-31', '17:00', '21:00', 'draft', NOW()) RETURNING id");
        $archived = (int) $db->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, archived_at)
            VALUES ('halloween', 'Old Archived Night', '2025-10-31', '2025-10-31', '17:00', '21:00', 'archived', NOW(), NOW()) RETURNING id");
        foreach ([$draft, $archived] as $event) {
            $id = (int) $db->fetchOne("INSERT INTO event_listings (event_id, address_id, details, status, note, published, updated_at) VALUES (?, ?, '{}', 'active', 'Not for today', TRUE, NOW()) RETURNING id", [$event, $a]);
            $db->executeStatement("INSERT INTO event_listing_windows (listing_id, local_date, start_local, end_local) SELECT ?, starts_on, '17:00', '21:00' FROM event_occurrences WHERE id = ?", [$id, $event]);
        }
        $this->client->getCookieJar()->clear();
        foreach (['/api/homes', '/api/homes/'.$a, '/api/event', '/api/community', '/'] as $uri) {
            $this->client->request('GET', $uri);
            $raw = (string) $this->client->getResponse()->getContent();
            foreach (['Secret Draft', 'Old Archived', 'Not for today', '2027-10-31', '2025-10-31'] as $needle) {
                self::assertStringNotContainsString($needle, $raw, "$uri never shows $needle");
            }
        }
        self::assertCount(1, $this->json('GET', '/api/homes')['homes'], 'only the active event\'s listing');
    }

    public function testSecurityHeadersAndCsp(): void
    {
        $this->configureCommunity();
        $this->client->request('GET', '/');
        $h = $this->client->getResponse()->headers;
        $csp = (string) $h->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringContainsString('https://tile.openstreetmap.org', $csp);
        self::assertSame('DENY', $h->get('X-Frame-Options'));
        self::assertSame('nosniff', $h->get('X-Content-Type-Options'));
        // Regression: OSM tile servers block requests without a Referer (same-origin policy stripped it).
        self::assertSame('strict-origin-when-cross-origin', $h->get('Referrer-Policy'));
        $this->client->request('GET', '/auth/confirm?t=x');
        self::assertSame('same-origin', $this->client->getResponse()->headers->get('Referrer-Policy'), 'no cross-site leak; real Origin on confirm POST');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    /** Analytics are off unless the operator turns them on (D-20261021-34): nothing is allowed, nothing is claimed. */
    public function testCloudflareWebAnalyticsIsOffUnlessConfigured(): void
    {
        $this->configureCommunity();
        $html = (string) $this->client->request('GET', '/')->html();
        self::assertStringNotContainsString('cloudflareinsights', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('No ads · No tracking', $html);
        $privacy = $this->client->request('GET', '/privacy')->filter('article.legal')->html(); // the notice, not the What's new notes
        self::assertStringContainsString('no advertising, no analytics and no tracking', $privacy);
        self::assertStringNotContainsString('Cloudflare Web Analytics', $privacy);
    }

    /**
     * CLOUDFLARE_WEB_ANALYTICS=1 (a site proxied by Cloudflare, Web Analytics automatic setup on): the policy allows only
     * Cloudflare's beacon host, its reports go to the site's own /cdn-cgi/rum, and the footer and privacy page say so.
     * The app adds no script itself; Cloudflare injects it.
     */
    public function testCloudflareWebAnalyticsWhenConfiguredIsAllowedAndDisclosed(): void
    {
        $_ENV['CLOUDFLARE_WEB_ANALYTICS'] = $_SERVER['CLOUDFLARE_WEB_ANALYTICS'] = '1';
        try {
            static::ensureKernelShutdown();
            $this->client = static::createClient([], ['HTTP_ORIGIN' => 'http://localhost', 'REMOTE_ADDR' => '127.0.0.1']);
            $this->clock = static::mockTime('2026-10-31 22:00:00 UTC');
            $this->configureCommunity();
            $html = (string) $this->client->request('GET', '/')->html();
            $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
            self::assertStringContainsString("script-src 'self' https://static.cloudflareinsights.com;", $csp);
            self::assertStringContainsString("connect-src 'self';", $csp, 'reports go to the site itself (/cdn-cgi/rum)');
            self::assertStringNotContainsString('unsafe-inline', $csp);
            self::assertStringNotContainsString('static.cloudflareinsights.com/beacon', $html, 'Cloudflare injects the beacon, not the app');
            self::assertStringContainsString('No ads · Cookie-free visit statistics', $html);
            $this->client->request('GET', '/privacy');
            $privacy = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('Cloudflare Web Analytics', $privacy);
            self::assertStringNotContainsString('no analytics', $privacy);
        } finally {
            unset($_ENV['CLOUDFLARE_WEB_ANALYTICS'], $_SERVER['CLOUDFLARE_WEB_ANALYTICS']);
            static::ensureKernelShutdown();
        }
    }

    /**
     * The privacy notice lists every cookie and storage the site uses and says why there is no consent banner
     * (D-20261021-35). Without CLOUDFLARE_PROXY it names no third party besides the map tiles.
     */
    public function testThePrivacyNoticeListsEveryCookieAndWhyThereIsNoBanner(): void
    {
        $this->configureCommunity();
        $privacy = $this->client->request('GET', '/privacy')->filter('article.legal')->html(); // the notice, not the What's new notes
        self::assertStringContainsString('<h2>Cookies and storage on your device</h2>', $privacy);
        self::assertStringContainsString('strictly necessary', $privacy);
        self::assertStringContainsString('no cookie banner', $privacy);
        self::assertStringContainsString('email delivery service', $privacy);
        self::assertStringNotContainsString('Cloudflare', $privacy);
        self::assertStringNotContainsString('__cf_bm', $privacy);
        self::assertMatchesRegularExpression('~Last updated [A-Z][a-z]+ \d{4}~', $privacy);
        self::assertStringContainsString('Last updated October 2026', $privacy, 'a fixed date, changed only when the notice changes');
    }

    /** CLOUDFLARE_PROXY=1: Cloudflare carries every request and may set its strictly necessary security cookies; the notice says so. */
    public function testThePrivacyNoticeDisclosesCloudflareWhenTheSiteIsDeliveredThroughIt(): void
    {
        foreach (['CLOUDFLARE_PROXY', 'CLOUDFLARE_WEB_ANALYTICS'] as $setting) {
            $_ENV[$setting] = $_SERVER[$setting] = '1'; // either one means the site is behind Cloudflare
            try {
                static::ensureKernelShutdown();
                $this->client = static::createClient([], ['HTTP_ORIGIN' => 'http://localhost', 'REMOTE_ADDR' => '127.0.0.1']);
                $this->clock = static::mockTime('2026-10-31 22:00:00 UTC');
                $this->configureCommunity();
                $privacy = $this->client->request('GET', '/privacy')->filter('article.legal')->html();
                self::assertStringContainsString('Delivered through Cloudflare', $privacy, $setting);
                self::assertStringContainsString('__cf_bm', $privacy, $setting);
                self::assertStringContainsString('cf_clearance', $privacy, $setting);
                self::assertStringContainsString('https://www.cloudflare.com/privacypolicy/', $privacy, $setting);
                self::assertStringNotContainsString('set no cookies.', $privacy, $setting.': the site itself sets none, Cloudflare may');
                self::assertSame('CLOUDFLARE_WEB_ANALYTICS' === $setting, str_contains($privacy, 'Visit statistics'), $setting);
            } finally {
                unset($_ENV[$setting], $_SERVER[$setting]);
                static::ensureKernelShutdown();
            }
        }
    }

    public function testAnonymousCrossOriginPostIsBlocked(): void
    {
        $this->configureCommunity();
        $this->json('POST', '/api/auth/request-link', ['email' => 'x@example.test'], ['Origin' => 'https://evil.example']);
        self::assertSame(403, $this->code());
        static::ensureKernelShutdown();
        $bare = static::createClient(); // no default Origin and no history-derived Referer
        $bare->request('POST', '/api/disputes', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(403, $bare->getResponse()->getStatusCode(), 'missing Origin and Referer');
    }

    public function testNoFakePinsWhenNothingIsListed(): void
    {
        $this->configureCommunity();
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        $html = (string) $this->client->request('GET', '/')->html();
        self::assertStringContainsString('No homes are listed yet', $html);
        self::assertStringNotContainsString('Demo home', $html);
    }
}
