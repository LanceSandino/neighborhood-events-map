<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\FixtureRoutingProvider;

/**
 * Traceability: ACCEPTANCE #15, BLUEPRINT §9 hard rule — no provider path unless the organizer verified
 * crossings; never across separate groups; provider responses cached.
 */
final class RoutePlanTest extends AppTestCase
{
    private function homes(): array
    {
        $ids = [];
        foreach ([['1', 40.003, -75.008, 'west'], ['3', 40.004, -75.006, 'west'], ['5', 40.004, -74.995, 'east']] as [$n, $lat, $lon, $z]) {
            $id = $this->addAddress($n, 'Pumpkin Ln', $lat, $lon, $z);
            $this->confirm($this->requestLink("r$n@example.test", ['address' => ['address_id' => $id], 'listing' => self::listing()]));
            $this->client->getCookieJar()->clear();
            $ids[] = $id;
        }

        return $ids;
    }

    public function testDisabledByDefaultAndWithoutVerifiedCrossings(): void
    {
        $this->configureCommunity(['routing' => ['groups' => [['west'], ['east']], 'provider_enabled' => true]]);
        [$a, $b] = $this->homes();
        $r = $this->json('POST', '/api/routes/plan', ['stops' => [$a, $b]]);
        self::assertSame(409, $this->code());
        self::assertSame('routing_unavailable', $r['error']['code']);
        self::assertFalse($this->json('GET', '/api/community')['routing']['provider']);
    }

    public function testOneGroupOnlyAndCached(): void
    {
        FixtureRoutingProvider::$calls = 0;
        $this->configureCommunity(['routing' => ['groups' => [['west'], ['east']], 'provider_enabled' => true, 'crossings_verified' => true]]);
        [$a, $b, $c] = $this->homes();
        self::assertTrue($this->json('GET', '/api/community')['routing']['provider']);
        $r = $this->json('POST', '/api/routes/plan', ['stops' => [$a, $c]]);
        self::assertSame(422, $this->code());
        self::assertStringContainsString('separate areas', $r['error']['fields']['stops']);
        self::assertSame(0, FixtureRoutingProvider::$calls, 'provider never asked to connect separate groups');

        $r = $this->json('POST', '/api/routes/plan', ['stops' => [$a, $b]]);
        self::assertSame(200, $this->code());
        self::assertTrue($r['route']['provider_reported']);
        self::assertSame('LineString', $r['route']['geometry']['type']);
        $this->json('POST', '/api/routes/plan', ['stops' => [$a, $b]]);
        self::assertSame(1, FixtureRoutingProvider::$calls, 'second request served from route_cache');
        $this->json('POST', '/api/routes/plan', ['stops' => [$a]]);
        self::assertSame(422, $this->code());
    }
}
