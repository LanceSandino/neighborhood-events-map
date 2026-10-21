<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: ACCEPTANCE #13, #17 (out-of-boundary, non-claimable zone, nonexistent zone, zone mismatch,
 * ambiguous overlap, street allowlist, pin collision, unreviewed boundary, generic no-zone community).
 */
final class EligibilityTest extends AppTestCase
{
    private function attempt(array $address, string $email = 'self@example.test'): array
    {
        return $this->json('POST', '/api/auth/request-link', ['email' => $email, 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => $address, 'listing' => self::listing()]]);
    }

    public function testSelfEntryRules(): void
    {
        $this->configureCommunity();
        $cases = [
            'outside_boundary' => ['house_number' => '5', 'street' => 'Pumpkin Ln', 'lat' => 40.05, 'lon' => -75.0],
            'zone_not_claimable' => ['house_number' => '6', 'street' => 'Pumpkin Ln', 'lat' => 40.018, 'lon' => -75.0],
            'unknown_zone' => ['house_number' => '7', 'street' => 'Pumpkin Ln', 'lat' => 40.005, 'lon' => -75.005, 'zone_key' => 'phase-5'],
            'zone_mismatch' => ['house_number' => '8', 'street' => 'Pumpkin Ln', 'lat' => 40.005, 'lon' => -75.005, 'zone_key' => 'east'],
            'ambiguous_zone' => ['house_number' => '9', 'street' => 'Pumpkin Ln', 'lat' => 40.005, 'lon' => -75.0],
            'street_not_allowed' => ['house_number' => '10', 'street' => 'Elm St', 'lat' => 40.005, 'lon' => -75.005],
            'invalid_address' => ['house_number' => 'ten', 'street' => 'Pumpkin Ln', 'lat' => 40.005, 'lon' => -75.005],
        ];
        foreach ($cases as $reason => $input) {
            $r = $this->attempt($input, "case-$reason@example.test");
            self::assertSame($reason, $r['error']['code'] ?? null, $reason.': '.json_encode($r));
            self::assertSame(422, $this->code());
        }
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claim_intents'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM eligible_addresses'), 'nothing invented');
    }

    public function testSelfEntryHappyPathCreatesReviewableAddressAtThePin(): void
    {
        $this->configureCommunity();
        $this->addAddress('100', 'Lantern Way', 40.006, -74.996, 'east');
        $r = $this->attempt(['house_number' => '100', 'street' => 'Lantern Way', 'lat' => 40.006, 'lon' => -74.99601], 'dup@example.test');
        self::assertSame(202, $this->code(), 'existing registry entry is used instead of the pin');
        $r = $this->attempt(['house_number' => '102', 'street' => 'Lantern Way', 'lat' => 40.00601, 'lon' => -74.99601]);
        self::assertSame('pin_collision', $r['error']['code'], '~1.4 m from another home');
        $token = $this->requestLink('pin@example.test', ['address' => ['house_number' => '104', 'street' => 'lantern way', 'lat' => 40.0065, 'lon' => -74.9965], 'listing' => self::listing()]);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM eligible_addresses'), 'no address row until verified');
        $this->confirm($token);
        $row = $this->db()->fetchAssociative("SELECT * FROM eligible_addresses WHERE normalized_address = '104 LANTERN WAY'");
        self::assertSame('self_entry', $row['source']);
        self::assertSame('east', $row['zone_key']);
        self::assertNull($row['reviewed_at']);
        self::assertSame(40.0065, (float) $row['latitude']);
    }

    public function testUnreviewedBoundaryClosesClaimsButNotBrowsing(): void
    {
        $this->configureCommunity(approve: false);
        $id = $this->addAddress('1', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $c = $this->json('GET', '/api/community');
        self::assertFalse($c['registration']['open']);
        self::assertSame('pending_review', $c['map']['boundary_status']);
        self::assertNull($c['map']['boundary'], 'unapproved outline is not published');
        self::assertNotNull($c['map']['bbox'], 'map can still fit the area');
        $r = $this->attempt($this->byId($id));
        self::assertSame(403, $this->code());
        self::assertSame('closed', $r['error']['code']);
        $this->client->request('GET', '/');
        self::assertSame(200, $this->code());
    }

    public function testClaimClosesIfBoundaryApprovalIsRevokedBeforeConfirmation(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('2', 'Pumpkin Ln', 40.005, -75.006, 'west');
        $token = $this->requestLink('late@example.test', ['address' => $this->byId($id), 'listing' => self::listing()]);
        $this->repo()->revokeBoundaryApproval();
        $this->confirm($token);
        $me = $this->json('GET', '/api/me');
        self::assertSame('closed', $me['claim_outcome']['status']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claims'));
    }

    public function testRegistryOnlyModeRejectsSelfEntryAndLookupIsNarrow(): void
    {
        $this->configureCommunity(['registration' => ['address_mode' => 'registry']]);
        $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.007, 'west');
        $this->addAddress('120', 'Pumpkin Ln', 40.005, -75.008, 'west');
        $r = $this->attempt(['house_number' => '14', 'street' => 'Pumpkin Ln', 'lat' => 40.005, 'lon' => -75.005]);
        self::assertSame('self_entry_disabled', $r['error']['code']);
        self::assertSame([], $this->json('GET', '/api/addresses/lookup?q=Pumpkin')['results'], 'no enumeration without a house number');
        self::assertSame([], $this->json('GET', '/api/addresses/lookup?q=1')['results']);
        $hits = $this->json('GET', '/api/addresses/lookup?q=12 Pumpkin Lane')['results'];
        self::assertSame(['12 Pumpkin Ln'], array_column($hits, 'address'));
        self::assertSame('West side', $hits[0]['zone_label']);
        self::assertSame(['12 Pumpkin Ln'], array_column($this->json('GET', '/api/addresses/lookup?q=12 pu')['results'], 'address'), 'prefix match on the full house number');
        self::assertSame(['120 Pumpkin Ln'], array_column($this->json('GET', '/api/addresses/lookup?q=120 Pumpkin')['results'], 'address'));
    }

    public function testGenericCommunityWithoutZones(): void
    {
        $this->configureCommunity(withZones: false);
        $c = $this->json('GET', '/api/community');
        self::assertSame([], $c['zones']);
        self::assertFalse($c['features']['optional_zones']);
        $token = $this->requestLink('nozone@example.test', ['address' => ['house_number' => '3', 'street' => 'Pumpkin Ln', 'lat' => 40.018, 'lon' => -75.0], 'listing' => self::listing()]);
        $this->confirm($token);
        $homes = $this->json('GET', '/api/homes')['homes'];
        self::assertNull($homes[0]['zone']);
        $html = (string) $this->client->request('GET', '/')->html();
        self::assertStringNotContainsString('id="zone"', $html, 'zone filter hidden');
    }
}
