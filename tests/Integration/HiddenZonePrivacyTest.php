<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: security audit run-1 leads 3 and 16, D-20261019-12 ("hidden" zones are not public at
 * all). A home in a hidden zone was still readable by id (GET /api/homes/{id}, route planning), and claim
 * refusals named the hidden zone, confirmed its key and let pins map its outline.
 */
final class HiddenZonePrivacyTest extends AppTestCase
{
    private function zones(array $status, array $extra = []): array
    {
        return ['zones' => [
            ['key' => 'west', 'label' => 'West side', 'status' => $status['west'] ?? 'active'],
            ['key' => 'east', 'label' => 'East side', 'status' => $status['east'] ?? 'active'],
            ['key' => 'park', 'label' => 'Park', 'claimable' => false, 'residential' => false, 'status' => $status['park'] ?? 'active'],
        ]] + $extra;
    }

    private function claimRequest(array $address): array
    {
        return $this->json('POST', '/api/auth/request-link', ['email' => 'probe@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => $address, 'listing' => self::listing()]]);
    }

    public function testHomeInAHiddenZoneIsNotReadableByIdOrRoutable(): void
    {
        $routing = ['routing' => ['groups' => [['west', 'east']], 'provider_enabled' => true, 'crossings_verified' => true]];
        $this->configureCommunity($this->zones([], $routing));
        $ids = [];
        foreach ([['1', 40.003, -75.008], ['3', 40.004, -75.006]] as [$n, $lat, $lon]) {
            $id = $this->addAddress($n, 'Pumpkin Ln', $lat, $lon, 'west');
            $this->confirm($this->requestLink("h$n@example.test", ['address' => ['address_id' => $id, 'house_number' => $n], 'listing' => self::listing()]));
            $this->client->getCookieJar()->clear();
            $ids[] = $id;
        }
        $this->json('GET', '/api/homes/'.$ids[0]);
        self::assertSame(200, $this->code(), 'listed while the zone is active');

        $this->configureCommunity($this->zones(['west' => 'hidden'], $routing));
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        $r = $this->json('GET', '/api/homes/'.$ids[0]);
        self::assertSame(404, $this->code(), 'not readable by id either');
        self::assertArrayNotHasKey('home', $r);
        $r = $this->json('POST', '/api/routes/plan', ['stops' => $ids]);
        self::assertSame(422, $this->code());
        self::assertStringContainsString('not listed', $r['error']['fields']['stops']);
    }

    public function testWalkingGroupsInThePublicPayloadDoNotNameAHiddenZone(): void
    {
        $this->configureCommunity($this->zones(['west' => 'hidden'], ['routing' => ['groups' => [['west', 'east']], 'group_labels' => ['Main']]]));
        $community = $this->json('GET', '/api/community');
        self::assertSame([['east']], $community['routing']['groups'], 'positions kept, hidden key dropped');
        self::assertSame(['Main'], $community['routing']['group_labels']);
        self::assertStringNotContainsString('"west"', json_encode($community));
    }

    public function testClaimRefusalsNeverNameOrConfirmAHiddenZone(): void
    {
        $this->configureCommunity($this->zones(['west' => 'hidden']));
        $id = $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');

        // Registry row in a hidden zone: refused exactly like an id that does not exist.
        $hidden = $this->claimRequest(['address_id' => $id, 'house_number' => '12']);
        self::assertSame(422, $this->code());
        $missing = $this->claimRequest(['address_id' => $id + 1000, 'house_number' => '12']);
        self::assertSame(['code' => $missing['error']['code'], 'message' => $missing['error']['message']],
            ['code' => $hidden['error']['code'], 'message' => $hidden['error']['message']]);

        // Typed address that matches the hidden row, a pin inside the hidden zone, and a pin there with
        // another zone selected: refused, never naming the zone.
        $probes = [
            ['house_number' => '12', 'street' => 'Pumpkin Ln', 'lat' => 40.0051, 'lon' => -75.0051],
            ['house_number' => '14', 'street' => 'Pumpkin Ln', 'lat' => 40.006, 'lon' => -75.006],
            ['house_number' => '16', 'street' => 'Pumpkin Ln', 'lat' => 40.007, 'lon' => -75.007, 'zone_key' => 'east'],
        ];
        foreach ($probes as $address) {
            $r = $this->claimRequest($address);
            self::assertSame(422, $this->code(), json_encode($r));
            self::assertStringNotContainsString('West side', (string) $r['error']['message'], json_encode($address));
        }

        // A hidden key is unknown, exactly like a key that never existed.
        $east = ['house_number' => '302', 'street' => 'Lantern Way', 'lat' => 40.0065, 'lon' => -74.9965];
        $asHidden = $this->claimRequest($east + ['zone_key' => 'west']);
        $asMissing = $this->claimRequest($east + ['zone_key' => 'north']);
        self::assertSame('unknown_zone', $asMissing['error']['code']);
        self::assertSame($asMissing['error'], $asHidden['error']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claim_intents'));
    }

    public function testPinOutsideTheBoundaryInsideAHiddenAreaIsJustOutside(): void
    {
        // Like an amenity tract cut out of the boundary, but hidden: no name, only "outside the boundary".
        $this->configureCommunity($this->zones(['park' => 'hidden']));
        $ring = [[-75.01, 40.00], [-74.99, 40.00], [-74.99, 40.015], [-75.01, 40.015], [-75.01, 40.00]];
        $features = [['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]]]];
        foreach (['west' => self::WEST, 'east' => self::EAST, 'park' => self::PARK] as $k => $zone) {
            $features[] = ['type' => 'Feature', 'properties' => ['zone_key' => $k], 'geometry' => ['type' => 'Polygon', 'coordinates' => [$zone]]];
        }
        $this->repo()->replaceGeometry(['type' => 'FeatureCollection', 'features' => $features], null, true, true);
        $this->repo()->reset();
        $r = $this->claimRequest(['house_number' => '20', 'street' => 'Pumpkin Ln', 'lat' => 40.018, 'lon' => -75.0]);
        self::assertSame('outside_boundary', $r['error']['code'], json_encode($r));
        self::assertStringNotContainsString('Park', $r['error']['message']);
    }

    public function testReviewRequestForAHomeThatIsNotPublicLooksLikeAnUnknownId(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('12', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $this->confirm($this->requestLink('owner@example.test', ['address' => ['address_id' => $id, 'house_number' => '12'], 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $this->configureCommunity($this->zones(['west' => 'hidden']));

        $dispute = fn (int $addressId) => $this->json('POST', '/api/disputes', ['address_id' => $addressId, 'house_number' => '12',
            'reporter_email' => 'neighbor@example.test', 'reason' => str_repeat('This is my house and I live here. ', 7), 'pow' => $this->solvePow('dispute')]);
        $hidden = $dispute($id);
        self::assertSame(422, $this->code(), json_encode($hidden));
        $unknown = $dispute($id + 1000);
        self::assertSame($unknown['error'], $hidden['error']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM disputes'));
    }
}
