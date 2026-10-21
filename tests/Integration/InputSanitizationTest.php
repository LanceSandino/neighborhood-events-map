<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Address\AddressNormalizer;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Mime\Email;

/**
 * Traceability: input sanitization review (2026-10-21, owner request "verify we sanitize all input;
 * addresses don't need special characters"). Every address entry point refuses special characters;
 * every free-text field loses control and invisible (text-reordering) characters before it is stored.
 */
final class InputSanitizationTest extends AppTestCase
{
    private const RLO = "\u{202E}";
    private const ZWSP = "\u{200B}";

    private string $csrf = '';

    private function signInOrganizer(): void
    {
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, now())");
        $this->signIn('org@example.test');
        $this->csrf = $this->csrf();
    }

    public function testSelfEntryAddressWithSpecialCharactersIsRefused(): void
    {
        $this->configureCommunity();
        foreach ([['12', 'Pumpkin Ln<script>'], ['1<2', 'Pumpkin Ln'], ['12', "Pumpkin\u{0301} Ln"]] as [$house, $street]) {
            $r = $this->json('POST', '/api/auth/request-link', ['email' => 'pin@example.test', 'pow' => $this->solvePow('claim'),
                'claim' => ['address' => ['house_number' => $house, 'street' => $street, 'lat' => 40.005, 'lon' => -75.005], 'listing' => self::listing()]]);
            self::assertSame(422, $this->code(), json_encode($r));
            self::assertSame('invalid_address', $r['error']['code']);
            self::assertSame(AddressNormalizer::PLAIN_HINT, $r['error']['message']);
        }
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claim_intents'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM eligible_addresses'));
    }

    public function testLookupWithSpecialCharactersFindsNothing(): void
    {
        $this->configureCommunity();
        $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        self::assertCount(1, $this->json('GET', '/api/addresses/lookup?q='.rawurlencode('12 Pum'))['results']);
        self::assertSame([], $this->json('GET', '/api/addresses/lookup?q='.rawurlencode('12 Pum<'))['results']);
        self::assertSame(200, $this->code());
    }

    public function testListingNoteAndReviewReasonLoseInvisibleCharacters(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $this->confirm($this->requestLink('owner@example.test', ['address' => $this->byId($id), 'listing' => self::listing()]));
        $this->json('PATCH', "/api/me/homes/$id", ['note' => 'Porch'.self::ZWSP.' light '.self::RLO."on\x07."], ['X-CSRF-Token' => $this->csrf()]);
        self::assertSame(200, $this->code());
        self::assertSame('Porch light on .', $this->db()->fetchOne('SELECT note FROM listings WHERE address_id = ?', [$id]));
        $this->client->getCookieJar()->clear();

        $reason = str_repeat('This is my house and I live here. ', 7).self::RLO.'gnp.exe';
        $this->json('POST', '/api/disputes', $this->byId($id) + ['reporter_email' => 'neighbor@example.test', 'reason' => $reason, 'pow' => $this->solvePow('dispute')]);
        self::assertSame(201, $this->code());
        $stored = (string) $this->db()->fetchOne('SELECT reason FROM disputes');
        self::assertStringNotContainsString(self::RLO, $stored);
        self::assertStringEndsWith('here. gnp.exe', $stored);
        $mail = self::getMailerMessages();
        $notice = end($mail);
        \assert($notice instanceof Email);
        self::assertStringNotContainsString(self::RLO, (string) $notice->getTextBody(), 'the organizer email cannot be reordered either');
    }

    public function testOrganizerAddressEntriesArePlainAndDisplayEditsKeepTheSameAddress(): void
    {
        $this->configureCommunity();
        $this->signInOrganizer();
        $this->json('POST', '/api/admin/addresses', ['address' => '77 Lantern Way<b>', 'lat' => 40.005, 'lon' => -74.995, 'zone_key' => 'east'], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code());
        $id = $this->json('POST', '/api/admin/addresses', ['address' => '77 Mcintosh Way', 'lat' => 40.005, 'lon' => -74.995, 'zone_key' => 'east'], ['X-CSRF-Token' => $this->csrf])['id'];

        foreach (['<b>77 McIntosh Way</b>', '79 McIntosh Way', 'McIntosh Way 77', "77 McIntosh\u{200B} Way"] as $display) {
            $r = $this->json('PATCH', "/api/admin/addresses/$id", ['display_address' => $display], ['X-CSRF-Token' => $this->csrf]);
            self::assertSame(422, $this->code(), $display.' '.json_encode($r));
        }
        $this->json('PATCH', "/api/admin/addresses/$id", ['display_address' => '77  McIntosh   Way'], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(200, $this->code(), 'a spelling (capitals, spaces) of the same address is fine');
        self::assertSame('77 McIntosh Way', $this->db()->fetchOne('SELECT display_address FROM eligible_addresses WHERE id = ?', [$id]));
    }

    public function testCommunitySettingsRefuseSpecialCharactersInStreetsAndTileUrls(): void
    {
        $this->configureCommunity();
        $this->signInOrganizer();
        $settings = $this->json('GET', '/api/admin/community')['settings'];

        $streets = $settings;
        $streets['registration']['street_allowlist'] = ['Pumpkin Ln', 'Lantern <Way>'];
        $r = $this->json('PUT', '/api/admin/community', ['settings' => $streets], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code());
        self::assertStringContainsString('street_allowlist', json_encode($r) ?: '');

        $tiles = $settings;
        $tiles['map']['tiles']['url'] = 'https://tiles.example.org/{z}/{x}/{y}.png"onerror="alert(1)';
        $this->json('PUT', '/api/admin/community', ['settings' => $tiles], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code(), 'the tile URL template is anchored and has no quotes');

        $named = $settings;
        $named['community']['name'] = 'Test'.self::RLO.'ville'.self::ZWSP;
        $this->json('PUT', '/api/admin/community', ['settings' => $named], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(200, $this->code());
        self::assertSame('Testville', $this->json('GET', '/api/community')['community']['name']);
    }

    public function testOrganizerReasonsAreCleanedAndATransferNamesARealReviewRequest(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $this->confirm($this->requestLink('owner@example.test', ['address' => $this->byId($id), 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $this->signInOrganizer();

        $this->json('POST', "/api/admin/listings/$id/hide", ['reason' => 'Offensive'.self::RLO." note\n"], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(200, $this->code());
        self::assertSame('Offensive note', $this->db()->fetchOne('SELECT moderation_reason FROM listings WHERE address_id = ?', [$id]));

        $this->json('POST', "/api/admin/claims/$id/transfer", ['email' => 'new@example.test', 'confirm' => true, 'dispute_id' => 999], ['X-CSRF-Token' => $this->csrf]);
        self::assertSame(422, $this->code(), 'dispute_id must name a review request for this home');
        self::assertSame('owner@example.test', $this->db()->fetchOne('SELECT u.email_normalized FROM claims c JOIN users u ON u.id = c.user_id WHERE c.address_id = ?', [$id]));
    }
}
