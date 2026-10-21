<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Traceability: security audit run-1 leads 1, 2 and 15 (percent-encoded paths). Symfony routes and
 * authorizes on the decoded path while the idle-session, CSRF and logout guards compared the raw one,
 * so `/api/m%65` skipped the idle timeout and `/_internal_%6Cogout` signed a visitor out cross-site.
 * No route of this app needs a percent-encoded path, so such paths are refused before anything runs.
 */
final class RequestPathHardeningTest extends AppTestCase
{
    public function testEncodedProtectedPathCannotOutliveTheIdleTimeout(): void
    {
        $this->configureCommunity();
        $this->signIn('idle-encoded@example.test');
        $this->clock->sleep(31 * 60);

        $this->json('GET', '/api/m%65');
        self::assertSame(404, $this->code(), 'an encoded path never reaches the signed-in API');
        $this->json('GET', '/api/me/hom%65s');
        self::assertSame(404, $this->code());

        $r = $this->json('GET', '/api/me');
        self::assertSame(401, $this->code(), 'the idle session still ends');
        self::assertSame('session_expired', $r['error']['code']);
    }

    public function testEncodedMutationCannotSkipTheCsrfToken(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $this->confirm($this->requestLink('csrf-encoded@example.test', ['address' => ['address_id' => $id, 'house_number' => '12'], 'listing' => self::listing()]));
        self::assertSame(303, $this->code());

        // Same-origin request (passes the Origin check) but without the session CSRF token.
        $this->json('PATCH', '/api/m%65/homes/'.$id, ['note' => 'Changed without a token']);
        self::assertSame(404, $this->code());
        self::assertSame('Porch light on.', $this->db()->fetchOne('SELECT note FROM listings WHERE address_id = ?', [$id]));
    }

    public function testEncodedLogoutPathDoesNotSignAnyoneOut(): void
    {
        $this->configureCommunity();
        $this->signIn('logout-encoded@example.test');
        foreach (['/_internal_%6Cogout', '/_internal%5Flogout', '/%5Finternal_logout'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(404, $this->code(), $path);
        }
        $this->json('GET', '/api/me');
        self::assertSame(200, $this->code(), 'still signed in');
    }

    public function testPercentEncodedPathsAreRefusedButQueryStringsStillWork(): void
    {
        $this->configureCommunity();
        $this->json('GET', '/%61pi/community');
        self::assertSame(404, $this->code());
        $this->client->request('GET', '/%61dmin');
        self::assertSame(404, $this->code());
        $this->json('GET', '/api/community');
        self::assertSame(200, $this->code());
        $this->json('GET', '/api/addresses/lookup?q=12%20Pum');
        self::assertSame(200, $this->code(), 'encoding in the query string is normal');
    }

    /** @return iterable<string, array{string, string}> */
    public static function otherEncodedPaths(): iterable
    {
        yield 'sign-in confirm (answered 500 before)' => ['POST', '/auth/%63onfirm'];
        yield 'organizer moderation verb' => ['POST', '/api/admin/listings/1/%68ide'];
        yield 'encoded slash' => ['GET', '/api%2Fme'];
        yield 'escaped digit in an id' => ['GET', '/api/homes/%31'];
        yield 'resident page' => ['GET', '/%6De'];
    }

    #[DataProvider('otherEncodedPaths')]
    public function testOtherEncodedPathsAreRefusedBeforeAnyHandler(string $method, string $path): void
    {
        $this->configureCommunity();
        $r = $this->json($method, $path, 'GET' === $method ? null : []);
        self::assertSame(404, $this->code(), $path);
        self::assertSame('not_found', $r['error']['code'] ?? null, $path);
    }
}
