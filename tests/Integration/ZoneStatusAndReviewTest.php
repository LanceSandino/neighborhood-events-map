<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: zone status (active / coming soon / hidden) and organizer approval of self-entered
 * addresses (registration.review_new_addresses). A "coming soon" zone is drawn but not listable; a
 * hidden zone is not public at all; a new address waits for an organizer before it appears.
 */
final class ZoneStatusAndReviewTest extends AppTestCase
{
    private function zones(array $status): array
    {
        return ['zones' => [
            ['key' => 'west', 'label' => 'West side', 'status' => $status['west'] ?? 'active'],
            ['key' => 'east', 'label' => 'East side', 'status' => $status['east'] ?? 'active'],
            ['key' => 'park', 'label' => 'Park', 'claimable' => false, 'residential' => false],
        ]];
    }

    public function testComingSoonZoneIsDrawnButHomesThereCannotBeListed(): void
    {
        $this->configureCommunity($this->zones(['east' => 'coming_soon']));
        $this->addAddress('300', 'Lantern Way', 40.006, -74.996, 'east');

        $c = $this->json('GET', '/api/community');
        $east = array_values(array_filter($c['zones'], static fn (array $z) => 'east' === $z['key']))[0];
        self::assertSame('coming_soon', $east['status']);
        self::assertFalse($east['claimable']);
        self::assertNotNull($east['geometry'], 'still drawn on the map');
        self::assertSame(1, $c['stats']['zones'], 'only active residential zones are counted');

        $html = (string) $this->client->request('GET', '/')->html();
        self::assertStringContainsString('<option value="west">West side</option>', $html);
        self::assertStringNotContainsString('<option value="east">', $html, 'not offered in the zone filter or the self-entry zone list');

        $lookup = $this->json('GET', '/api/addresses/lookup?q=300%20Lan');
        self::assertSame('East side · coming soon', $lookup['results'][0]['zone_label']);

        foreach ([['address_id' => $lookup['results'][0]['id']], ['house_number' => '302', 'street' => 'Lantern Way', 'lat' => 40.0065, 'lon' => -74.9965]] as $address) {
            $r = $this->json('POST', '/api/auth/request-link', ['email' => 'soon@example.test', 'pow' => $this->solvePow('claim'),
                'claim' => ['address' => $address, 'listing' => self::listing()]]);
            self::assertSame(422, $this->code());
            self::assertSame('zone_not_open', $r['error']['code'], json_encode($r));
        }
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claim_intents'));
    }

    public function testHiddenZoneIsNotPublicAndItsAddressesAreNotFound(): void
    {
        $this->configureCommunity($this->zones(['west' => 'hidden']));
        $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $keys = array_column($this->json('GET', '/api/community')['zones'], 'key');
        self::assertSame(['east', 'park'], $keys);
        self::assertSame([], $this->json('GET', '/api/addresses/lookup?q=12%20Pum')['results']);
        $r = $this->json('POST', '/api/auth/request-link', ['email' => 'hidden@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => ['house_number' => '14', 'street' => 'Pumpkin Ln', 'lat' => 40.006, 'lon' => -75.006], 'listing' => self::listing()]]);
        self::assertSame('zone_not_open', $r['error']['code'] ?? null, json_encode($r));
    }

    public function testPinOnANonResidentialAreaOutsideTheBoundarySaysWhy(): void
    {
        // Like an amenities tract cut out of the residential boundary: the park is a zone but not inside the boundary.
        $this->configureCommunity();
        $ring = [[-75.01, 40.00], [-74.99, 40.00], [-74.99, 40.015], [-75.01, 40.015], [-75.01, 40.00]];
        $features = [['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]]]];
        foreach (['west' => self::WEST, 'east' => self::EAST, 'park' => self::PARK] as $k => $zone) {
            $features[] = ['type' => 'Feature', 'properties' => ['zone_key' => $k], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$zone]]];
        }
        $this->repo()->replaceGeometry(['type' => 'FeatureCollection', 'features' => $features], null, true, true);
        $this->repo()->reset();
        $claim = fn (float $lat) => $this->json('POST', '/api/auth/request-link', ['email' => 'pin@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => ['house_number' => '20', 'street' => 'Pumpkin Ln', 'lat' => $lat, 'lon' => -75.0], 'listing' => self::listing()]]);
        $r = $claim(40.018);
        self::assertSame('zone_not_claimable', $r['error']['code'], json_encode($r));
        self::assertStringContainsString('Park is not a residential area', $r['error']['message']);
        self::assertSame('outside_boundary', $claim(40.05)['error']['code'], 'nowhere near any zone');
    }

    public function testInvalidZoneStatusIsRejected(): void
    {
        $this->expectException(\App\Community\ConfigException::class);
        $this->expectExceptionMessage('zones[0].status must be one of: active, coming_soon, hidden.');
        $this->configureCommunity(['zones' => [['key' => 'west', 'label' => 'West', 'status' => 'soonish']]]);
    }

    public function testSelfEnteredHomeWaitsForOrganizerApprovalWhenReviewIsOn(): void
    {
        $this->configureCommunity(['registration' => ['review_new_addresses' => true]]);
        self::assertTrue($this->json('GET', '/api/community')['registration']['review_new_addresses']);
        $this->addAddress('200', 'Lantern Way', 40.004, -74.996, 'east');

        // A registry address is published straight away; review only applies to new addresses.
        $this->confirm($this->requestLink('known@example.test', ['address' => ['address_id' => (int) $this->db()->fetchOne("SELECT id FROM eligible_addresses WHERE house_number = '200'")], 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $this->confirm($this->requestLink('new@example.test', ['address' => ['house_number' => '104', 'street' => 'Lantern Way', 'lat' => 40.0065, 'lon' => -74.9965], 'listing' => self::listing()]));
        $me = $this->json('GET', '/api/me');
        self::assertSame('pending_review', $me['claim_outcome']['status']);
        self::assertTrue($me['homes'][0]['listing']['pending_review']);

        $public = array_column($this->json('GET', '/api/homes')['homes'], 'address');
        self::assertContains('200 Lantern Way', $public);
        self::assertNotContains('104 Lantern Way', $public, 'not on the public map until approved');
        $id = (int) $this->db()->fetchOne("SELECT id FROM eligible_addresses WHERE normalized_address = '104 LANTERN WAY'");
        self::assertTrue((bool) $this->db()->fetchOne('SELECT moderation_hold FROM eligible_addresses WHERE id = ?', [$id]));

        $this->client->getCookieJar()->clear();
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, now())");
        $this->signIn('org@example.test');
        $csrf = $this->csrf();
        $o = $this->json('GET', '/api/admin/overview');
        self::assertSame(1, $o['counts']['pending_review']);
        self::assertSame(0, $o['counts']['hidden_listings'], 'waiting homes are not counted as hidden by the organizer');
        self::assertSame([['id' => $id, 'address' => '104 Lantern Way']], $o['pending_addresses']);
        $row = array_values(array_filter($this->json('GET', '/api/admin/addresses?q=104')['addresses'], static fn (array $a) => $a['id'] === $id))[0];
        self::assertTrue($row['listing']['pending_review']);

        $this->json('POST', "/api/admin/listings/$id/unhide", [], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        self::assertContains('104 Lantern Way', array_column($this->json('GET', '/api/homes')['homes'], 'address'), 'approved → on the map');
        self::assertNotNull($this->db()->fetchOne('SELECT reviewed_at FROM eligible_addresses WHERE id = ?', [$id]), 'approval marks the address reviewed');
        self::assertSame(0, $this->json('GET', '/api/admin/overview')['counts']['pending_review']);
    }

    public function testWithoutReviewASelfEnteredHomeIsPublishedAtOnce(): void
    {
        $this->configureCommunity();
        $this->confirm($this->requestLink('direct@example.test', ['address' => ['house_number' => '104', 'street' => 'Lantern Way', 'lat' => 40.0065, 'lon' => -74.9965], 'listing' => self::listing()]));
        self::assertSame('published', $this->json('GET', '/api/me')['claim_outcome']['status']);
        self::assertContains('104 Lantern Way', array_column($this->json('GET', '/api/homes')['homes'], 'address'));
    }
}
