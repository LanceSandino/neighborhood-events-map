<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: SESSION_AND_AUTH_LIFECYCLE acceptance 1-8; ACCEPTANCE #21 (frozen clock).
 */
final class SessionIdleTest extends AppTestCase
{
    public function testIdleWindowIsServerEnforcedAndResetOnlyByInteractiveRequests(): void
    {
        $this->configureCommunity();
        $this->signIn('idle@example.test');

        $this->clock->sleep(29 * 60 + 59);
        $this->json('GET', '/api/me');
        self::assertSame(200, $this->code(), '29:59 still valid and resets the clock');

        $this->clock->sleep(29 * 60);
        // Passive status probe and background-flagged requests do NOT extend the window.
        $s = $this->json('GET', '/api/me/session');
        self::assertSame(200, $this->code());
        self::assertSame(60, $s['session']['expires_in']);
        $this->json('GET', '/api/me/homes', null, ['X-NHM-Background' => '1']);
        self::assertSame(200, $this->code());
        // Public map polling never touches the session.
        $this->json('GET', '/api/homes');
        $this->json('GET', '/api/community');

        $this->clock->sleep(60);
        $r = $this->json('GET', '/api/me');
        self::assertSame(401, $this->code(), '30:00 after the last interactive request');
        self::assertSame('session_expired', $r['error']['code']);

        // Session was destroyed server-side: nothing works afterwards, public browsing still does.
        $this->json('GET', '/api/me');
        self::assertSame(401, $this->code());
        self::assertSame('unauthenticated', json_decode((string) $this->client->getResponse()->getContent(), true)['error']['code']);
        $this->json('GET', '/api/homes');
        self::assertSame(200, $this->code());
    }

    public function testSessionsEndAfterTheAbsoluteLifetimeEvenWhenActive(): void
    {
        $this->configureCommunity();
        $this->signIn('marathon@example.test');
        for ($elapsed = 0; $elapsed + 25 * 60 < 12 * 3600; $elapsed += 25 * 60) {
            $this->clock->sleep(25 * 60);
            $this->json('GET', '/api/me');
            self::assertSame(200, $this->code(), sprintf('active at %d min', ($elapsed + 25 * 60) / 60));
        }
        $this->clock->sleep(25 * 60);
        $r = $this->json('GET', '/api/me');
        self::assertSame(401, $this->code(), 'twelve hours after sign-in the session ends however active it was');
        self::assertSame('session_expired', $r['error']['code']);
    }

    public function testExpiredPageRedirectsToSignInWithExpiredNotice(): void
    {
        $this->configureCommunity();
        $this->signIn('page@example.test');
        $this->clock->sleep(31 * 60);
        $this->client->request('GET', '/me');
        self::assertSame(302, $this->code());
        self::assertSame('/login?expired=1&next=%2Fme', $this->client->getResponse()->headers->get('Location'));
        $this->client->request('GET', '/login?expired=1');
        self::assertStringContainsString('Session expired', (string) $this->client->getResponse()->getContent());
    }

    public function testExpiredAdminActionIsBlockedWithoutWrites(): void
    {
        $this->configureCommunity();
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('boss@example.test', TRUE, now())");
        $this->signIn('boss@example.test');
        $csrf = $this->csrf();
        $this->clock->sleep(30 * 60);
        $before = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audit_events');
        $this->json('POST', '/api/admin/registration', ['enabled' => false], ['X-CSRF-Token' => $csrf]);
        self::assertSame(401, $this->code());
        self::assertTrue($this->repo()->current()->settings['registration']['enabled'], 'no write happened');
        self::assertSame($before + 1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM audit_events'), 'only the expiry itself is audited');
    }

    public function testCommunityConfiguredIdleMinutesAreRespected(): void
    {
        $this->configureCommunity(['registration' => ['session_idle_minutes' => 20]]);
        $this->signIn('twenty@example.test');
        $this->clock->sleep(19 * 60 + 59);
        $this->json('GET', '/api/me');
        self::assertSame(200, $this->code());
        $this->clock->sleep(20 * 60);
        $this->json('GET', '/api/me');
        self::assertSame(401, $this->code());
    }

    public function testLogoutRevokesTheServerSessionSoAStolenCookieFails(): void
    {
        $this->configureCommunity();
        $this->signIn('logout@example.test');
        $csrf = $this->csrf();
        $stolen = $this->client->getCookieJar()->get(self::SESSION_COOKIE);
        $this->json('POST', '/api/auth/logout', null, ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        $this->client->getCookieJar()->set($stolen);
        $this->json('GET', '/api/me');
        self::assertSame(401, $this->code());
    }

    public function testLogoutRequiresPostAndCsrf(): void
    {
        $this->configureCommunity();
        $this->signIn('csrf@example.test');
        $this->json('GET', '/api/auth/logout');
        self::assertSame(405, $this->code());
        $this->json('POST', '/api/auth/logout');
        self::assertSame(403, $this->code());
        $this->json('GET', '/api/me');
        self::assertSame(200, $this->code(), 'still signed in');
    }

    public function testNewLinkAfterExpiryCreatesAFreshSession(): void
    {
        $this->configureCommunity();
        $this->signIn('fresh@example.test');
        $old = $this->client->getCookieJar()->get(self::SESSION_COOKIE)->getValue();
        $this->clock->sleep(45 * 60);
        $this->json('GET', '/api/me');
        self::assertSame(401, $this->code());
        $this->signIn('fresh@example.test');
        self::assertNotSame($old, $this->client->getCookieJar()->get(self::SESSION_COOKIE)->getValue());
        $this->json('GET', '/api/me');
        self::assertSame(200, $this->code());
    }
}
