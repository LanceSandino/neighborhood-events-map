<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\PolygonSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;


/**
 * Traceability: GEOGRAPHY_AND_BOUNDARIES (Polygon, MultiPolygon, holes, point-on-edge).
 */
#[CoversClass(PolygonSet::class)]
final class PolygonSetTest extends TestCase
{
    private static function square(float $x, float $y, float $s): array
    {
        return [[$x, $y], [$x + $s, $y], [$x + $s, $y + $s], [$x, $y + $s], [$x, $y]];
    }

    public function testPointInsideAndOutsideSimplePolygon(): void
    {
        $p = new PolygonSet([[self::square(-75.00, 40.00, 0.01)]]);
        self::assertTrue($p->contains(40.005, -74.995));
        self::assertFalse($p->contains(40.015, -74.995));
        self::assertFalse($p->contains(40.005, -74.98));
    }

    public function testPointOnEdgeAndVertexCountsAsInside(): void
    {
        $p = new PolygonSet([[self::square(-75.00, 40.00, 0.01)]]);
        self::assertTrue($p->contains(40.00, -74.995), 'bottom edge');
        self::assertTrue($p->contains(40.005, -75.00), 'left edge');
        self::assertTrue($p->contains(40.01, -74.99), 'top-right vertex');
        self::assertTrue($p->onBoundary(40.00, -74.995));
        self::assertFalse($p->onBoundary(40.005, -74.995));
    }

    public function testHoleExcludesInteriorButHoleEdgeIsInside(): void
    {
        $p = new PolygonSet([[self::square(-75.00, 40.00, 0.01), self::square(-74.996, 40.004, 0.002)]]);
        self::assertFalse($p->contains(40.005, -74.995), 'inside hole');
        self::assertTrue($p->contains(40.004, -74.995), 'on hole edge');
        self::assertTrue($p->contains(40.002, -74.998), 'between hole and shell');
        self::assertFalse($p->containsInterior(40.005, -74.995));
    }

    public function testMultiPolygonTwoDisjointAreas(): void
    {
        $p = new PolygonSet([[self::square(-75.00, 40.00, 0.01)], [self::square(-74.90, 39.90, 0.01)]]);
        self::assertTrue($p->contains(39.905, -74.895));
        self::assertTrue($p->contains(40.005, -74.995));
        self::assertFalse($p->contains(39.95, -74.95), 'gap between areas is outside (no bridging)');
        self::assertSame('MultiPolygon', $p->toGeometry()['type']);
        self::assertSame(['minLon' => -75.00, 'minLat' => 39.90, 'maxLon' => -74.89, 'maxLat' => 40.01], array_map(fn ($v) => round($v, 6), $p->bbox()));
        self::assertEqualsWithDelta(39.955, $p->center()['lat'], 1e-9);
    }

    public function testEmptySet(): void
    {
        $p = new PolygonSet([]);
        self::assertTrue($p->isEmpty());
        self::assertNull($p->bbox());
        self::assertFalse($p->contains(0, 0));
    }
}
