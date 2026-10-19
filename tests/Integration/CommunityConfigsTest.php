<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Community\CommunityBootstrapper;
use App\Geo\GeoJsonParser;
use App\Tests\Support\AppTestCase;

/**
 * Traceability: ACCEPTANCE #13, #18, #23 — the same code serves a real-plat example (Ventura Reserve,
 * three official Orange County plats as zones, pending approval, no listings shipped) and a fictional
 * no-zone community with different branding/date, purely through configuration.
 */
final class CommunityConfigsTest extends AppTestCase
{
    private const VENTURA = 'examples/ventura-reserve/community.yaml';

    private function bootstrap(string $file, bool $approve = false): array
    {
        return static::getContainer()->get(CommunityBootstrapper::class)->bootstrap(\dirname(__DIR__, 2).'/'.$file, false, $approve);
    }

    /**
     * A point strictly inside one zone of the shipped plat file (and in no other zone), found by
     * scanning the zone's bounding box, so no coordinates are hard-coded in the test.
     *
     * @return array{0:float,1:float} [lat, lon]
     */
    private static function interiorPoint(string $zoneKey): array
    {
        $shapes = [];
        foreach ((new GeoJsonParser())->parse((string) file_get_contents(\dirname(__DIR__, 2).'/examples/ventura-reserve/boundaries/zones.geojson')) as $f) {
            $shapes[$f['properties']['zone_key']] = $f['shape'];
        }
        $xs = $ys = [];
        foreach ($shapes[$zoneKey]->polygons as $polygon) {
            foreach ($polygon[0] as [$x, $y]) {
                $xs[] = $x;
                $ys[] = $y;
            }
        }
        for ($i = 1; $i < 40; ++$i) {
            for ($j = 1; $j < 40; ++$j) {
                $lon = min($xs) + (max($xs) - min($xs)) * $i / 40;
                $lat = min($ys) + (max($ys) - min($ys)) * $j / 40;
                $others = array_filter($shapes, fn ($s, $k) => $k !== $zoneKey && $s->contains($lat, $lon), \ARRAY_FILTER_USE_BOTH);
                if ($shapes[$zoneKey]->containsInterior($lat, $lon) && [] === $others) {
                    return [$lat, $lon];
                }
            }
        }
        self::fail('no interior point found for '.$zoneKey);
    }

    public function testVenturaReserveRealPlatExample(): void
    {
        $r = $this->bootstrap(self::VENTURA);
        self::assertSame('imported', $r['status']);
        self::assertSame(['phase-1', 'phase-2', 'phase-3'], $r['geometry']['zones']);

        $c = $this->json('GET', '/api/community');
        self::assertSame('Ventura Reserve', $c['community']['name']);
        self::assertSame('Orlando', $c['community']['locality']);
        self::assertSame('pending_review', $c['map']['boundary_status']);
        self::assertFalse($c['registration']['open']);
        self::assertSame(['Phase 1', 'Phase 2', 'Phase 3'], array_column(array_filter($c['zones'], fn ($z) => $z['claimable']), 'label'));
        self::assertSame(3, $c['stats']['zones']);
        self::assertSame([], $c['routing']['groups'], 'no walkable groups are asserted without verified crossings');
        self::assertFalse($c['routing']['crossings_verified']);
        self::assertSame([], $this->json('GET', '/api/homes')['homes'], 'a real-data example never ships listings');
        $html = (string) $this->client->request('GET', '/')->html();
        self::assertStringContainsString('3 phases', $html);
        self::assertStringContainsString('1–3', $html);
        self::assertStringContainsString('SETUP IN REVIEW', $html);

        // After organizer approval the geometry decides eligibility (self-entry is used here only to
        // exercise the plats; the example itself uses the county address registry).
        $this->repo()->approveBoundary(null);
        $settings = $this->repo()->current()->settings;
        $settings['registration']['address_mode'] = 'both';
        $settings['registration']['street_allowlist'] = ['Test St'];
        $this->repo()->saveSettings($settings, null);
        [$lat, $lon] = self::interiorPoint('phase-2');
        $this->json('POST', '/api/auth/request-link', ['email' => 'a@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => ['house_number' => '1', 'street' => 'Test St', 'lat' => $lat, 'lon' => $lon], 'listing' => self::listing()]]);
        self::assertSame(202, $this->code(), 'a point inside a recorded plat is eligible once approved');
        $r = $this->json('POST', '/api/auth/request-link', ['email' => 'b@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => ['house_number' => '2', 'street' => 'Test St', 'lat' => $lat, 'lon' => $lon, 'zone_key' => 'phase-4'], 'listing' => self::listing()]]);
        self::assertSame('unknown_zone', $r['error']['code'], 'only the three recorded phases exist');
        $r = $this->json('POST', '/api/auth/request-link', ['email' => 'c@example.test', 'pow' => $this->solvePow('claim'),
            'claim' => ['address' => ['house_number' => '3', 'street' => 'Test St', 'lat' => $lat + 0.02, 'lon' => $lon], 'listing' => self::listing()]]);
        self::assertSame('outside_boundary', $r['error']['code']);
    }

    public function testFictionalDemoIsAGenericSecondCommunity(): void
    {
        $this->bootstrap('examples/demo/community.yaml', true);
        $c = $this->json('GET', '/api/community');
        self::assertSame('Maple Hollow', $c['community']['name']);
        self::assertSame([], $c['zones']);
        self::assertSame('2026-10-30', $c['event']['date']);
        self::assertSame('America/Chicago', $c['event']['timezone']);
        self::assertTrue($c['registration']['open']);
        $this->client->request('GET', '/theme.css');
        self::assertStringContainsString('--brand:#2E7D6B', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->json('GET', '/api/homes')['homes'], 'no demo homes unless explicitly seeded with APP_DEMO=1');
    }

    public function testBootstrapIsIdempotent(): void
    {
        $this->bootstrap('examples/demo/community.yaml');
        self::assertSame('already-configured', $this->bootstrap(self::VENTURA)['status']);
        self::assertSame('Maple Hollow', $this->json('GET', '/api/community')['community']['name']);
    }

    public function testUnconfiguredSiteIsSafe(): void
    {
        $c = $this->json('GET', '/api/community');
        self::assertFalse($c['configured']);
        self::assertFalse($c['registration']['open']);
        self::assertSame(0, $c['stats']['homes']);
        $this->client->request('GET', '/');
        self::assertSame(200, $this->code());
        $this->client->request('GET', '/healthz');
        self::assertSame('ok', json_decode((string) $this->client->getResponse()->getContent(), true)['status']);
    }
}
