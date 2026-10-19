<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: ACCEPTANCE #3, #6, #7, #9, #2; BLUEPRINT §3 (first verified email wins, GET never consumes,
 * POST consumes once, 15-minute expiry, immediate publication, neutral responses).
 */
final class AuthAndClaimFlowTest extends AppTestCase
{
    public function testVerifiedClaimPublishesImmediatelyAndPublicApiLeaksNothing(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('101', 'Pumpkin Ln', 40.005, -75.005, 'west');

        $token = $this->requestLink('alice@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing(['note' => '<b>Hi</b> & welcome'])]);
        self::assertSame([], $this->json('GET', '/api/homes')['homes'], 'not published before confirmation');

        // GET (link scanners) does not consume or sign in.
        $this->client->request('GET', '/auth/confirm?t='.$token);
        $this->client->request('GET', '/auth/confirm?t='.$token);
        self::assertSame(200, $this->code());
        self::assertStringContainsString('101 Pumpkin Ln', (string) $this->client->getResponse()->getContent());
        $this->json('GET', '/api/me');
        self::assertSame(401, $this->code());

        $this->confirm($token);
        self::assertSame(303, $this->code());
        self::assertSame('/me', $this->client->getResponse()->headers->get('Location'));

        $me = $this->json('GET', '/api/me');
        self::assertSame(200, $this->code());
        self::assertSame('published', $me['claim_outcome']['status']);
        self::assertSame('101 Pumpkin Ln', $me['homes'][0]['address']);

        $this->client->request('GET', '/api/homes');
        $raw = (string) $this->client->getResponse()->getContent();
        $homes = json_decode($raw, true)['homes'];
        self::assertCount(1, $homes);
        self::assertSame('west', $homes[0]['zone']);
        self::assertSame('<b>Hi</b> & welcome', $homes[0]['note'], 'stored raw; escaped on output');
        foreach (['alice', '@', 'user_id', 'email', 'token'] as $leak) {
            self::assertStringNotContainsString($leak, $raw);
        }
        // Server-rendered list escapes the note.
        $this->client->request('GET', '/');
        self::assertStringContainsString('&lt;b&gt;Hi&lt;/b&gt;', (string) $this->client->getResponse()->getContent());

        // Replay of the same link fails.
        $this->client->request('POST', '/auth/confirm', ['t' => $token, '_csrf' => 'x']);
        self::assertSame(410, $this->code());
    }

    public function testLinkExpiresAfterFifteenMinutesAndIsSingleUse(): void
    {
        $this->configureCommunity();
        $token = $this->requestLink('bob@example.test');
        $this->clock->sleep(15 * 60 + 1);
        $this->client->request('GET', '/auth/confirm?t='.$token);
        self::assertSame(410, $this->code());
        self::assertStringContainsString('expired', (string) $this->client->getResponse()->getContent());

        $this->clock->sleep(-(15 * 60 + 1) + 60); // back to 1 minute after issue: a fresh link works once
        $token2 = $this->requestLink('bob@example.test');
        $this->confirm($token2);
        self::assertSame(303, $this->code());
        $this->client->request('GET', '/auth/confirm?t='.$token2);
        self::assertSame(410, $this->code());
        self::assertStringContainsString('already used', (string) $this->client->getResponse()->getContent());
    }

    public function testConfirmPostRequiresCsrfToken(): void
    {
        $this->configureCommunity();
        $token = $this->requestLink('carol@example.test');
        $this->client->request('POST', '/auth/confirm', ['t' => $token, '_csrf' => 'forged']);
        self::assertSame(410, $this->code());
        // The token was not burned by the forged POST.
        $this->confirm($token);
        self::assertSame(303, $this->code());
    }

    public function testRequestLinkResponseIsNeutralForUnknownAndKnownEmails(): void
    {
        $this->configureCommunity();
        $this->signIn('known@example.test');
        $this->client->getCookieJar()->clear();
        $a = $this->json('POST', '/api/auth/request-link', ['email' => 'known@example.test', 'pow' => $this->solvePow('login')]);
        $b = $this->json('POST', '/api/auth/request-link', ['email' => 'nobody@example.test', 'pow' => $this->solvePow('login')]);
        self::assertSame(202, $this->code());
        self::assertSame(array_keys($a), array_keys($b));
        self::assertStringStartsWith('If ', $b['message']);
    }

    public function testAlreadyClaimedAddressShowsConflictWithoutOwnerIdentity(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('7', 'Lantern Way', 40.005, -74.995, 'east');
        $this->confirm($this->requestLink('owner@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();

        $r = $this->json('POST', '/api/auth/request-link', ['email' => 'other@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => ['address_id' => $id], 'listing' => self::listing()]]);
        self::assertSame(409, $this->code());
        self::assertSame('already_claimed', $r['error']['code']);
        self::assertSame('This home is already claimed.', $r['error']['message']);
        self::assertStringNotContainsString('owner@', json_encode($r));
        self::assertSame([true], $this->claimedFlags('7 Lantern Way'));
    }

    public function testSecondClaimantLosingAtConfirmationKeepsFirstOwner(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('9', 'Lantern Way', 40.006, -74.995, 'east');
        // Both start before anyone confirms (no reservation).
        $t1 = $this->requestLink('first@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing(['note' => 'first'])]);
        $t2 = $this->requestLink('second@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing(['note' => 'second'])]);
        $this->confirm($t1);
        $this->client->getCookieJar()->clear();
        $this->confirm($t2);
        self::assertSame(303, $this->code(), 'second user still signs in');
        $me = $this->json('GET', '/api/me');
        self::assertSame('already_claimed', $me['claim_outcome']['status']);
        self::assertSame([], $me['homes']);
        $homes = $this->json('GET', '/api/homes')['homes'];
        self::assertSame('first', $homes[0]['note'], 'never overwritten');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claims'));
    }

    public function testSessionIdRotatesAtSignIn(): void
    {
        $this->configureCommunity();
        $token = $this->requestLink('dana@example.test');
        $crawler = $this->client->request('GET', '/auth/confirm?t='.$token);
        $before = $this->client->getCookieJar()->get(self::SESSION_COOKIE)?->getValue();
        self::assertNotNull($before, 'confirm page starts an anonymous session for CSRF');
        $this->client->submit($crawler->filter('form[action="/auth/confirm"]')->form());
        $after = $this->client->getCookieJar()->get(self::SESSION_COOKIE)?->getValue();
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
    }
}
