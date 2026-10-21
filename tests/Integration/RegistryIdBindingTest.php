<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use Symfony\Component\Mime\Email;

/**
 * Traceability: security audit run-1 leads 11 and 12. Registry ids are sequential, and a claim by id
 * mailed (anonymous) or returned (dashboard) the address text, so walking ids harvested the unclaimed
 * address inventory. A request by id must now also name the house number the visitor looked up; any
 * mismatch is refused exactly like an id that does not exist.
 */
final class RegistryIdBindingTest extends AppTestCase
{
    public function testAnonymousClaimByIdMustNameTheHouseNumber(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $claim = fn (array $address) => $this->json('POST', '/api/auth/request-link', ['email' => 'walker@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => $address, 'listing' => self::listing()]]);

        $bare = $claim(['address_id' => $id]);
        self::assertSame(422, $this->code(), json_encode($bare));
        self::assertSame('not_eligible', $bare['error']['code']);
        $wrong = $claim(['address_id' => $id, 'house_number' => '14']);
        $missing = $claim(['address_id' => $id + 1000, 'house_number' => '12']);
        self::assertSame($missing['error'], $bare['error']);
        self::assertSame($missing['error'], $wrong['error']);
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claim_intents'));

        $claim(['address_id' => $id, 'house_number' => '12']);
        self::assertSame(202, $this->code());
        $mail = self::getMailerMessage();
        \assert($mail instanceof Email);
        self::assertStringContainsString('12 Pumpkin Ln', (string) $mail->getTextBody());
    }

    public function testDashboardClaimByIdMustNameTheHouseNumber(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $this->signIn('resident@example.test');
        $csrf = $this->csrf();

        $r = $this->json('POST', '/api/me/homes', ['address' => ['address_id' => $id], 'listing' => self::listing()], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code());
        self::assertStringNotContainsString('Pumpkin', json_encode($r) ?: '', 'the address text is never returned for a bare id');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claims'));

        $r = $this->json('POST', '/api/me/homes', ['address' => ['address_id' => $id, 'house_number' => '12'], 'listing' => self::listing()], ['X-CSRF-Token' => $csrf]);
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertSame('12 Pumpkin Ln', $r['address']);
    }

    public function testReviewRequestByIdMustNameTheHouseNumber(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $this->confirm($this->requestLink('owner@example.test', ['address' => ['address_id' => $id, 'house_number' => '12'], 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $reason = str_repeat('This is my house and I live here. ', 7);
        $dispute = fn (array $target) => $this->json('POST', '/api/disputes', $target + ['reporter_email' => 'neighbor@example.test',
            'reason' => $reason, 'pow' => $this->solvePow('dispute')]);

        $bare = $dispute(['address_id' => $id]);
        self::assertSame(422, $this->code());
        $unknown = $dispute(['address_id' => $id + 1000, 'house_number' => '12']);
        self::assertSame($unknown['error'], $bare['error']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM disputes'));

        $dispute(['address_id' => $id, 'house_number' => '12']);
        self::assertSame(201, $this->code());
    }
}
