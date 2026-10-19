<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\ClientIdentity;
use App\Security\UserRepository;
use App\Tests\Support\AppTestCase;

/**
 * Traceability: security hardening (docs/security/hardening-report.md) — sign-in link budgets are
 * spent only by mail that is actually sent; aliases share a budget; organizers are never locked out
 * by the global cap; honeypot submissions do nothing; email endpoints answer no faster than a floor;
 * one used link revokes the others; the registry is not echoed or searchable while listings are closed.
 */
final class AbuseHardeningTest extends AppTestCase
{
    /** @return array<string,int> */
    private function buckets(): array
    {
        $rows = $this->db()->fetchAllKeyValue('SELECT bucket, COUNT(*) FROM throttle_events GROUP BY bucket');

        return array_map('intval', $rows);
    }

    private function linkRequest(string $email, ?array $claim = null, array $extra = []): array
    {
        $body = ['email' => $email, 'pow' => $this->solvePow(null === $claim ? 'login' : 'claim')] + $extra;
        if (null !== $claim) {
            $body['claim'] = $claim;
        }

        return $this->json('POST', '/api/auth/request-link', $body);
    }

    public function testFailedClaimRequestsDoNotSpendTheEmailOrGlobalBudget(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('7', 'Lantern Way', 40.005, -74.995, 'east');
        $this->confirm($this->requestLink('owner@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $before = $this->buckets();

        $this->linkRequest('other@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing()]);
        self::assertSame(409, $this->code());
        $this->linkRequest('other@example.test', ['address' => ['house_number' => '5', 'street' => 'Pumpkin Ln', 'lat' => 40.05, 'lon' => -75.0], 'listing' => self::listing()]);
        self::assertSame(422, $this->code(), 'outside the boundary');

        $after = $this->buckets();
        self::assertSame($before['link_global'], $after['link_global'], 'no mail was sent, so the global budget is untouched');
        self::assertSame($before['link_email'], $after['link_email']);
        self::assertSame($before['link_ip'] + 2, $after['link_ip'], 'every attempt still counts against the client');
        self::assertCount(0, self::getMailerMessages());
    }

    public function testRegistryAddressTextIsNotEchoedForAnAddressId(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('7', 'Lantern Way', 40.005, -74.995, 'east');
        $r = $this->linkRequest('someone@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing()]);
        self::assertSame(202, $this->code());
        self::assertNull($r['address'], 'the client already knows the address it picked; arbitrary ids reveal nothing');
        $r = $this->linkRequest('selfie@example.test', ['address' => ['house_number' => '15', 'street' => 'Pumpkin Lane', 'lat' => 40.004, 'lon' => -75.006], 'listing' => self::listing()]);
        self::assertSame(202, $this->code(), json_encode($r));
        self::assertSame('15 Pumpkin Ln', $r['address'], 'self-entry echoes the normalized form of what the user typed');
    }

    public function testSelfEntryAddressesHaveAPerAddressBudget(): void
    {
        $this->configureCommunity();
        $before = $this->buckets()['link_address'] ?? 0;
        $this->linkRequest('selfie@example.test', ['address' => ['house_number' => '15', 'street' => 'Pumpkin Ln', 'lat' => 40.004, 'lon' => -75.006], 'listing' => self::listing()]);
        self::assertSame(202, $this->code());
        self::assertSame($before + 1, $this->buckets()['link_address'] ?? 0);
    }

    public function testAliasesOfOneMailboxShareOneBudget(): void
    {
        $this->configureCommunity();
        foreach (['nhm.fixture.alias+a@gmail.com', 'nhmfixturealias+b@gmail.com', 'n.h.m.fixture.alias@googlemail.com'] as $alias) {
            $this->linkRequest($alias);
            self::assertSame(202, $this->code(), $alias);
        }
        $r = $this->linkRequest('nhm.fix.ture.alias+zzz@gmail.com');
        self::assertSame(429, $this->code());
        self::assertSame('rate_limited', $r['error']['code']);
    }

    public function testOrganizersAreNotLockedOutByTheGlobalLinkBudget(): void
    {
        $this->configureCommunity();
        static::getContainer()->get(UserRepository::class)->setAdmin('boss@example.test', true);
        $key = static::getContainer()->get(ClientIdentity::class)->hash('throttle:link_global', 'global');
        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        for ($i = 0; $i < 200; ++$i) {
            $this->db()->insert('throttle_events', ['bucket' => 'link_global', 'key_hash' => $key, 'occurred_at' => $now]);
        }
        $this->linkRequest('resident@example.test');
        self::assertSame(429, $this->code(), 'the global cap still protects the mail relay');
        $this->linkRequest('boss@example.test');
        self::assertSame(202, $this->code(), 'an organizer can always get a sign-in link');
    }

    public function testHoneypotSubmissionsLookAcceptedButDoNothing(): void
    {
        $this->configureCommunity();
        $tokens = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM auth_link_tokens');
        $bot = $this->linkRequest('bot@example.test', null, ['website' => 'http://spam.example']);
        self::assertSame(202, $this->code());
        $human = $this->linkRequest('human@example.test', null, ['website' => '']);
        self::assertSame(202, $this->code());
        self::assertSame(array_keys($human), array_keys($bot), 'same response shape');
        self::assertStringStartsWith('If bot@example.test can receive email', $bot['message']);
        self::assertSame($tokens + 1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM auth_link_tokens'), 'only the human got a link');
        self::assertCount(1, self::getMailerMessages());
    }

    public function testEmailEndpointsAnswerNoFasterThanTheFloor(): void
    {
        $this->configureCommunity();
        $floorMs = (int) $_ENV['AUTH_RESPONSE_FLOOR_MS'];
        self::assertGreaterThan(0, $floorMs);
        $pow = $this->solvePow('login');
        $t = hrtime(true);
        $this->json('POST', '/api/auth/request-link', ['email' => 'timed@example.test', 'pow' => $pow]);
        $sent = (hrtime(true) - $t) / 1e6;
        self::assertSame(202, $this->code());
        self::assertGreaterThanOrEqual($floorMs, $sent, 'accepted requests are padded to the floor');

        $pow = $this->solvePow('login');
        $t = hrtime(true);
        $this->json('POST', '/api/auth/request-link', ['email' => 'not-an-email', 'pow' => $pow]);
        $invalid = (hrtime(true) - $t) / 1e6;
        self::assertSame(422, $this->code());
        self::assertGreaterThanOrEqual($floorMs, $invalid, 'validation failures look the same from outside');

        $t = hrtime(true);
        $this->json('POST', '/api/auth/request-link', ['email' => 'nopow@example.test']);
        $rejected = (hrtime(true) - $t) / 1e6;
        self::assertSame(400, $this->code());
        self::assertLessThan($floorMs, $rejected, 'requests without proof-of-work are never held, so the floor cannot tie up workers cheaply');
    }

    public function testUsingOneLinkRevokesTheOtherOutstandingLinks(): void
    {
        $this->configureCommunity();
        $first = $this->requestLink('twice@example.test');
        $second = $this->requestLink('twice@example.test');
        $this->confirm($second);
        self::assertSame(303, $this->code());
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/auth/confirm?t='.$first);
        self::assertSame(410, $this->code(), 'older links stop working once one link is used');
    }

    public function testRegistryLookupIsClosedWhileListingsAreClosed(): void
    {
        $this->configureCommunity([], false);
        $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        self::assertSame([], $this->json('GET', '/api/addresses/lookup?q=12 Pumpkin')['results']);
        $this->repo()->approveBoundary(null);
        self::assertSame(['12 Pumpkin Ln'], array_column($this->json('GET', '/api/addresses/lookup?q=12 Pumpkin')['results'], 'address'));
    }

    private function claimedHome(): int
    {
        $this->configureCommunity();
        $id = $this->addAddress('61', 'Lantern Way', 40.005, -74.994, 'east');
        $this->confirm($this->requestLink('owner@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();

        return $id;
    }

    private function dispute(int $id, string $reason, array $extra = []): array
    {
        return $this->json('POST', '/api/disputes', ['address_id' => $id, 'reporter_email' => 'real@example.test', 'reason' => $reason, 'pow' => $this->solvePow('dispute')] + $extra);
    }

    public function testInvalidReviewRequestsDoNotUseUpAHomesBudget(): void
    {
        $id = $this->claimedHome();
        $before = $this->buckets();
        $this->dispute($id, 'short');
        self::assertSame(422, $this->code());
        $after = $this->buckets();
        self::assertSame($before['dispute_address'] ?? 0, $after['dispute_address'] ?? 0, 'junk requests cannot block reviews for a home');
        self::assertSame($before['dispute_global'] ?? 0, $after['dispute_global'] ?? 0);
        self::assertSame(($before['dispute_ip'] ?? 0) + 1, $after['dispute_ip']);

        $r = $this->dispute($id, str_repeat('This is my house and I can show the deed. ', 6));
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertSame(1, $this->buckets()['dispute_address']);
        self::assertSame(1, $this->buckets()['dispute_global']);
        $mail = self::getMailerMessages();
        self::assertStringContainsString('UNVERIFIED', (string) end($mail)->getTextBody(), 'the organizer is told the reply-to address is unverified');
    }

    public function testReviewRequestsHaveAGlobalDailyCap(): void
    {
        $id = $this->claimedHome();
        $key = static::getContainer()->get(ClientIdentity::class)->hash('throttle:dispute_global', 'global');
        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        for ($i = 0; $i < 60; ++$i) {
            $this->db()->insert('throttle_events', ['bucket' => 'dispute_global', 'key_hash' => $key, 'occurred_at' => $now]);
        }
        $r = $this->dispute($id, str_repeat('This is my house and I can show the deed. ', 6));
        self::assertSame(429, $this->code());
        self::assertSame('rate_limited', $r['error']['code']);
    }

    public function testHoneypotReviewRequestsStoreAndSendNothing(): void
    {
        $id = $this->claimedHome();
        $r = $this->dispute($id, str_repeat('Buy cheap pills at our shop, best prices online. ', 6), ['website' => 'http://spam.example']);
        self::assertSame(201, $this->code());
        self::assertMatchesRegularExpression('~^D-\d{6}$~', $r['ticket']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM disputes'));
        self::assertCount(0, self::getMailerMessages(), 'no notice was emailed for this request');
    }
}
