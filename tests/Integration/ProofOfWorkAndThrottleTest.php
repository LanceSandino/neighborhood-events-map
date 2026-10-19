<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Security\ProofOfWork;
use App\Tests\Support\AppTestCase;

/**
 * Traceability: ACCEPTANCE #4, #5; BLUEPRINT §5 (PoW replay, challenge stealing, expiry, purpose binding,
 * failure lockout; persistent per-email/per-IP/global throttles with Retry-After).
 */
final class ProofOfWorkAndThrottleTest extends AppTestCase
{
    private function send(array $pow, string $email = 'pow@example.test', string $ip = '127.0.0.1'): array
    {
        $this->client->setServerParameter('REMOTE_ADDR', $ip);

        return $this->json('POST', '/api/auth/request-link', ['email' => $email, 'pow' => $pow]);
    }

    private function sendFrom(string $ip, string $email): array
    {
        $this->client->setServerParameter('REMOTE_ADDR', $ip);

        return $this->send($this->solvePow('login'), $email, $ip);
    }

    public function testRequestLinkWithoutPowIsRejected(): void
    {
        $this->configureCommunity();
        $r = $this->json('POST', '/api/auth/request-link', ['email' => 'a@example.test']);
        self::assertSame(400, $this->code());
        self::assertSame('pow_missing', $r['error']['code']);
        self::assertCount(0, self::getMailerMessages());
    }

    public function testSolvedChallengeWorksOnceThenReplayIsRejected(): void
    {
        $this->configureCommunity();
        $pow = $this->solvePow('login');
        $this->send($pow);
        self::assertSame(202, $this->code());
        $r = $this->send($pow);
        self::assertSame('pow_replayed', $r['error']['code']);
    }

    public function testWrongNonceExpiredWrongPurposeAndMalformed(): void
    {
        $this->configureCommunity();
        $c = $this->json('GET', '/api/pow?purpose=login')['challenge'];
        self::assertSame(8, $c['difficulty']);
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $c['salt']);
        $bad = '0';
        while (ProofOfWork::meetsDifficulty(ProofOfWork::digest('login', $c['id'], $c['salt'], $bad), $c['difficulty'])) {
            $bad = (string) ((int) $bad + 1);
        }
        self::assertSame('pow_invalid', $this->send(['id' => $c['id'], 'nonce' => $bad])['error']['code']);
        self::assertSame('pow_malformed', $this->send(['id' => $c['id'], 'nonce' => '12ab'])['error']['code']);
        self::assertSame('pow_malformed', $this->send(['id' => $c['id'], 'nonce' => str_repeat('1', 17)])['error']['code']);
        self::assertSame('pow_missing', $this->send(['id' => 'not-a-uuid', 'nonce' => '1'])['error']['code']);

        $claimPow = $this->solvePow('claim');
        self::assertSame('pow_wrong_purpose', $this->send($claimPow)['error']['code'], 'claim challenge used for login');

        $late = $this->solvePow('login');
        $this->clock->sleep(121);
        self::assertSame('pow_expired', $this->send($late)['error']['code']);
    }

    public function testChallengeStolenByAnotherClientIsRejected(): void
    {
        $this->configureCommunity();
        $pow = $this->solvePow('login'); // issued to 127.0.0.1
        self::assertSame('pow_client_mismatch', $this->send($pow, 'thief@example.test', '203.0.113.9')['error']['code']);
    }

    public function testFailedAttemptsLockTheChallenge(): void
    {
        $this->configureCommunity();
        $c = $this->json('GET', '/api/pow?purpose=dispute')['challenge'];
        $bad = 0;
        while (ProofOfWork::meetsDifficulty(ProofOfWork::digest('dispute', $c['id'], $c['salt'], (string) $bad), $c['difficulty'])) {
            ++$bad;
        }
        for ($i = 0; $i < ProofOfWork::MAX_FAILED_ATTEMPTS; ++$i) {
            self::assertSame('pow_invalid', $this->json('POST', '/api/disputes', ['pow' => ['id' => $c['id'], 'nonce' => (string) $bad]])['error']['code']);
        }
        $r = $this->json('POST', '/api/disputes', ['pow' => ['id' => $c['id'], 'nonce' => '1']]);
        self::assertSame('pow_locked', $r['error']['code']);
        self::assertSame(5, (int) $this->db()->fetchOne('SELECT failed_attempts FROM pow_challenges WHERE id = ?', [$c['id']]));
    }

    public function testPerEmailLimitReturns429WithRetryAfterAndSendsNoMail(): void
    {
        $this->configureCommunity();
        for ($i = 0; $i < 3; ++$i) {
            $this->send($this->solvePow('login'), 'flood@example.test');
            self::assertSame(202, $this->code());
        }
        $r = $this->send($this->solvePow('login'), 'flood@example.test');
        self::assertSame(429, $this->code());
        self::assertSame('rate_limited', $r['error']['code']);
        self::assertSame((string) $r['error']['retry_after'], $this->client->getResponse()->headers->get('Retry-After'));
        self::assertGreaterThan(890, $r['error']['retry_after']);
        self::assertCount(0, self::getMailerMessages());
        // A different email from the same IP is still allowed (until the per-IP cap).
        $this->send($this->solvePow('login'), 'neighbor@example.test');
        self::assertSame(202, $this->code());
        // The window slides: after 15 minutes the email may request again.
        $this->clock->sleep(901);
        $this->send($this->solvePow('login'), 'flood@example.test');
        self::assertSame(202, $this->code());
    }

    public function testPerIpLimitIsPersistentAcrossKernels(): void
    {
        $this->configureCommunity();
        for ($i = 0; $i < 10; ++$i) {
            $this->sendFrom('198.51.100.7', "user{$i}@example.test");
            self::assertSame(202, $this->code(), "request $i");
            $this->client->getKernel()->shutdown(); // fresh container: limits live in PostgreSQL, not memory
        }
        $this->sendFrom('198.51.100.7', 'user99@example.test');
        self::assertSame(429, $this->code());
        self::assertSame(10, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM throttle_events WHERE bucket = 'link_ip'"));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM throttle_events WHERE key_hash LIKE '198.51%'"), 'keys are hashed');
    }

    public function testPowIssuanceIsThrottledAndDifficultyAdapts(): void
    {
        $this->configureCommunity();
        $first = $this->json('GET', '/api/pow?purpose=login')['challenge']['difficulty'];
        for ($i = 0; $i < 30; ++$i) {
            $last = $this->json('GET', '/api/pow?purpose=login');
        }
        self::assertSame($first + 2, $last['challenge']['difficulty']);
        for ($i = 0; $i < 10; ++$i) {
            $this->json('GET', '/api/pow?purpose=login');
        }
        self::assertSame(429, $this->code());
        $this->json('GET', '/api/pow?purpose=dispute');
        self::assertSame(429, $this->code(), 'throttle is per client, not per purpose');
        $this->json('GET', '/api/pow?purpose=admin');
        self::assertSame(400, $this->code(), 'unknown purpose rejected');
    }
}
