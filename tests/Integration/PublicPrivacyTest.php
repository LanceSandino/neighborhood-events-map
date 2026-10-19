<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: ACCEPTANCE #2, #12, #13 — public endpoints never expose unclaimed inventory, hidden or
 * out-of-season listings, emails or tokens; security headers/CSP; no fake pins.
 */
final class PublicPrivacyTest extends AppTestCase
{
    public function testOnlyOptedInCurrentSeasonListingsArePublic(): void
    {
        $this->configureCommunity();
        $a = $this->addAddress('1', 'Pumpkin Ln', 40.004, -75.004, 'west');
        $b = $this->addAddress('3', 'Pumpkin Ln', 40.004, -75.005, 'west');
        $this->addAddress('5', 'Pumpkin Ln', 40.004, -75.006, 'west'); // never claimed
        $this->confirm($this->requestLink('a@example.test', ['address' => ['address_id' => $a], 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $this->confirm($this->requestLink('b@example.test', ['address' => ['address_id' => $b], 'listing' => self::listing()]));
        $this->db()->executeStatement("UPDATE listings SET event_date = '2025-10-31' WHERE address_id = ?", [$b]);

        $this->client->request('GET', '/api/homes');
        $raw = (string) $this->client->getResponse()->getContent();
        $homes = json_decode($raw, true)['homes'];
        self::assertSame(['1 Pumpkin Ln'], array_column($homes, 'address'));
        foreach (['@', '5 Pumpkin', 'user', 'token', 'claimed_at', 'normalized'] as $needle) {
            self::assertStringNotContainsString($needle, $raw);
        }
        self::assertSame(['id', 'address', 'zone', 'lat', 'lon', 'start', 'end', 'candy', 'nonfood', 'adult_drinks', 'low_noise', 'jump_scares',
            'flashing_lights', 'loud_audio', 'scare_level', 'stroller_friendly', 'note', 'status', 'updated_at'], array_keys($homes[0]));
        $this->json('GET', '/api/homes/'.$b);
        self::assertSame(404, $this->code(), 'last season is not public');
        $c = (string) json_encode($this->json('GET', '/api/community'));
        self::assertStringNotContainsString('@example.test', $c);
        self::assertStringNotContainsString('organizer', strtolower($c));
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
