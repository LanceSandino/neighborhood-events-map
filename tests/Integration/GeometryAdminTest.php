<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;

/**
 * Traceability: ACCEPTANCE #16, #17; GEOGRAPHY_AND_BOUNDARIES acceptance (two disconnected areas,
 * exact export/import round trip, reload after restart, approval gate, boundary change confirmation).
 */
final class GeometryAdminTest extends AppTestCase
{
    private function adminCsrf(): string
    {
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, now())");
        $this->signIn('org@example.test');

        return $this->csrf();
    }

    private static function sq(float $x, float $y, float $s): array
    {
        return [[[$x, $y], [$x + $s, $y], [$x + $s, $y + $s], [$x, $y + $s], [$x, $y]]];
    }

    public function testTwoDisconnectedAreasRoundTripExactlyAndRequireApproval(): void
    {
        $this->configureCommunity(withZones: false, approve: true);
        $csrf = $this->adminCsrf();
        $fc = ['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => ['name' => 'North'], 'geometry' => ['type' => 'Polygon', 'coordinates' => self::sq(-75.01, 40.00, 0.005)]],
            ['type' => 'Feature', 'properties' => ['name' => 'South'], 'geometry' => ['type' => 'Polygon', 'coordinates' => self::sq(-74.995, 40.012, 0.004)]],
        ]];
        $r = $this->json('PUT', '/api/admin/geometries', ['geojson' => $fc], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code(), json_encode($r));
        self::assertFalse($r['approved'], 'every change needs re-approval');
        self::assertFalse($this->json('GET', '/api/community')['registration']['open']);

        $this->client->request('GET', '/api/admin/geometries/export');
        $export1 = (string) $this->client->getResponse()->getContent();
        self::assertSame('application/geo+json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertCount(2, json_decode($export1, true)['features']);

        // Simulate container restart, re-import the export, export again: identical.
        $this->client->getKernel()->shutdown();
        $this->json('PUT', '/api/admin/geometries', ['geojson' => json_decode($export1, true)], ['X-CSRF-Token' => $csrf]);
        $this->client->request('GET', '/api/admin/geometries/export');
        self::assertSame($export1, (string) $this->client->getResponse()->getContent());

        $this->json('POST', '/api/admin/geometries/approve', ['confirm' => false], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code());
        $a = $this->json('POST', '/api/admin/geometries/approve', ['confirm' => true], ['X-CSRF-Token' => $csrf]);
        self::assertTrue($a['approved']);
        $pub = $this->json('GET', '/api/community');
        self::assertTrue($pub['registration']['open']);
        self::assertSame('MultiPolygon', $pub['map']['boundary']['type']);
        self::assertEqualsWithDelta(-75.01, $pub['map']['bbox']['minLon'], 1e-9);
    }

    public function testInvalidGeometryRejectedWithProblems(): void
    {
        $this->configureCommunity();
        $csrf = $this->adminCsrf();
        $bow = ['type' => 'Polygon', 'coordinates' => [[[-75.01, 40.0], [-74.99, 40.01], [-74.99, 40.0], [-75.01, 40.015], [-75.01, 40.0]]]];
        $r = $this->json('PUT', '/api/admin/geometries', ['geojson' => $bow], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code());
        self::assertSame('invalid_geometry', $r['error']['code']);
        $swapped = ['type' => 'Polygon', 'coordinates' => [[[40.0, -75.01], [40.0, -74.99], [40.01, -74.99], [40.01, -75.01], [40.0, -75.01]]]];
        $r = $this->json('PUT', '/api/admin/geometries', ['geojson' => $swapped], ['X-CSRF-Token' => $csrf]);
        self::assertStringContainsString('[latitude, longitude]', implode(' ', $r['error']['problems']));
        $unknownZone = ['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Polygon', 'coordinates' => self::sq(-75.01, 40.0, 0.01)]],
            ['type' => 'Feature', 'properties' => ['zone_key' => 'phase-5'], 'geometry' => ['type' => 'Polygon', 'coordinates' => self::sq(-75.01, 40.0, 0.005)]],
        ]];
        $r = $this->json('PUT', '/api/admin/geometries', ['geojson' => $unknownZone], ['X-CSRF-Token' => $csrf]);
        self::assertStringContainsString('"phase-5" is not configured', implode(' ', $r['error']['problems']));
        self::assertTrue($this->repo()->current()->boundaryApproved, 'failed imports change nothing');
    }

    public function testShrinkingBoundaryWithClaimsOutsideNeedsConfirmationAndHidesThoseListings(): void
    {
        $this->configureCommunity(withZones: false);
        $id = $this->addAddress('71', 'Lantern Way', 40.018, -74.992);
        $this->confirm($this->requestLink('edge@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $csrf = $this->adminCsrf();
        $small = ['type' => 'Polygon', 'coordinates' => self::sq(-75.01, 40.0, 0.01)];
        $r = $this->json('PUT', '/api/admin/geometries', ['geojson' => $small], ['X-CSRF-Token' => $csrf]);
        self::assertSame(409, $this->code());
        self::assertSame(1, $r['error']['outside_claims']);
        $this->json('PUT', '/api/admin/geometries', ['geojson' => $small, 'confirm_outside_claims' => true], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claims'), 'ownership preserved');
        self::assertSame([], $this->json('GET', '/api/homes')['homes'], 'listing suppressed pending correction');
    }

    public function testGeometryVersionsAreKeptAsHistory(): void
    {
        $this->configureCommunity();
        $csrf = $this->adminCsrf();
        $this->json('PUT', '/api/admin/geometries', ['geojson' => ['type' => 'Polygon', 'coordinates' => self::sq(-75.01, 40.0, 0.02)]], ['X-CSRF-Token' => $csrf]);
        $o = $this->json('GET', '/api/admin/overview');
        self::assertSame(2, $o['versions'][0]['version']);
        self::assertSame(2, \count($o['versions']));
        self::assertSame(5, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM community_geometries'), '4 rows from v1 + 1 row v2');
    }
}
