<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\GeoJsonParser;
use App\Geo\Haversine;
use App\Geo\PolygonSet;
use App\Geo\ZoneResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;


/**
 * Traceability: GEOGRAPHY_AND_BOUNDARIES (overlapping zones surface a configuration error, never a guess).
 */
#[CoversClass(ZoneResolver::class)]
#[CoversClass(Haversine::class)]
final class ZoneResolverTest extends TestCase
{
    private static function sq(float $x, float $y, float $s): PolygonSet
    {
        return new PolygonSet([[[[$x, $y], [$x + $s, $y], [$x + $s, $y + $s], [$x, $y + $s], [$x, $y]]]]);
    }

    public function testAdjacentZonesShareEdgeWithoutOverlapWarning(): void
    {
        $r = new ZoneResolver(['east' => self::sq(-75.00, 40.00, 0.01), 'west' => self::sq(-74.99, 40.00, 0.01)]);
        self::assertSame([], $r->overlaps());
        self::assertSame(['east'], $r->zonesContaining(40.005, -74.995));
        self::assertSame(['east', 'west'], $r->zonesContaining(40.005, -74.99), 'shared edge point is ambiguous');
        self::assertSame([], $r->zonesContaining(40.1, -74.9));
    }

    public function testOverlapIsMeasured(): void
    {
        $r = new ZoneResolver(['a' => self::sq(-75.00, 40.00, 0.01), 'b' => self::sq(-74.995, 40.00, 0.01)]);
        $o = $r->overlaps();
        self::assertCount(1, $o);
        $expected = Haversine::meters(40.00, -75.00, 40.00, -74.995) * Haversine::meters(40.00, -75.00, 40.01, -75.00);
        self::assertEqualsWithDelta($expected, $o[0]['approx_m2'], $expected * 0.05);
    }

    public function testOfficialVenturaReservePlatsDoNotOverlap(): void
    {
        $zones = [];
        foreach ((new GeoJsonParser())->parse((string) file_get_contents(\dirname(__DIR__, 3).'/examples/ventura-reserve/boundaries/zones.geojson')) as $f) {
            $zones[$f['properties']['zone_key']] = $f['shape'];
        }
        self::assertSame(['phase-1', 'phase-2', 'phase-3'], array_keys($zones));
        // Shapely (scripts/fetch_arcgis_boundaries.py, manifest.json) found no pairwise overlaps; the
        // grid estimate must agree, so adjacent plats sharing an edge never raise a false warning.
        self::assertSame([], (new ZoneResolver($zones))->overlaps());
    }

    public function testHaversineKnownDistance(): void
    {
        self::assertEqualsWithDelta(111_195, Haversine::meters(0, 0, 1, 0), 10);
        self::assertSame(0.0, Haversine::meters(40.0, -75.0, 40.0, -75.0));
    }
}
