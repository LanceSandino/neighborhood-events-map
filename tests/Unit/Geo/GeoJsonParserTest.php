<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\GeoJsonParser;
use App\Geo\GeometryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use App\Geo\SegmentIntersection;


/**
 * Traceability: ACCEPTANCE_TESTS #16-#17 (server rejects malformed/unreviewed polygons, lng/lat order,
 * self-intersection, out-of-range; accepts Polygon/MultiPolygon/holes/FeatureCollection).
 */
#[CoversClass(GeoJsonParser::class)]
#[CoversClass(SegmentIntersection::class)]
final class GeoJsonParserTest extends TestCase
{
    private const CENTER = ['lat' => 40.035, 'lon' => -74.99];

    private static function ring(float $x, float $y, float $s): array
    {
        return [[$x, $y], [$x + $s, $y], [$x + $s, $y + $s], [$x, $y + $s], [$x, $y]];
    }

    public function testAcceptsPolygonFeatureAndCollection(): void
    {
        $p = new GeoJsonParser();
        self::assertCount(1, $p->parse(['type' => 'Polygon', 'coordinates' => [self::ring(-75.0, 40.03, 0.01)]], self::CENTER));
        self::assertCount(1, $p->parse(['type' => 'Feature', 'properties' => ['a' => 1], 'geometry' => ['type' => 'Polygon', 'coordinates' => [self::ring(-75.0, 40.03, 0.01)]]]));
        $fc = $p->parse(json_encode(['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => ['zone_key' => 'east'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [self::ring(-75.0, 40.03, 0.01)]]],
            ['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[self::ring(-74.98, 40.03, 0.01)], [self::ring(-74.96, 40.03, 0.01)]]]],
        ]]), self::CENTER);
        self::assertCount(2, $fc);
        self::assertSame('east', $fc[0]['properties']['zone_key']);
        self::assertCount(2, $fc[1]['shape']->polygons);
    }

    public function testAcceptsPolygonWithValidHole(): void
    {
        $r = (new GeoJsonParser())->parse(['type' => 'Polygon', 'coordinates' => [self::ring(-75.0, 40.03, 0.01), self::ring(-74.996, 40.034, 0.002)]]);
        self::assertCount(2, $r[0]['shape']->polygons[0]);
    }

    /** @return iterable<string, array{0: mixed, 1: string}> */
    public static function invalidInputs(): iterable
    {
        yield 'not json' => ['{nope', 'not valid JSON'];
        yield 'point' => [['type' => 'Point', 'coordinates' => [0, 0]], 'Unsupported GeoJSON type'];
        yield 'line feature' => [['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[0, 0], [1, 1]]]], 'must be Polygon or MultiPolygon'];
        yield 'unclosed' => [['type' => 'Polygon', 'coordinates' => [[[-75.0, 40.03], [-74.99, 40.03], [-74.99, 40.04], [-75.0, 40.04]]]], 'not closed'];
        yield 'too few' => [['type' => 'Polygon', 'coordinates' => [[[-75.0, 40.03], [-74.99, 40.03], [-75.0, 40.03]]]], 'at least 4'];
        yield 'out of range' => [['type' => 'Polygon', 'coordinates' => [[[-75.0, 140.03], [-74.99, 40.03], [-74.99, 40.04], [-75.0, 140.03]]]], 'out-of-range'];
        yield 'non numeric' => [['type' => 'Polygon', 'coordinates' => [[['a', 1], [2, 2], [3, 1], ['a', 1]]]], 'malformed position'];
        yield 'zero area' => [['type' => 'Polygon', 'coordinates' => [[[-75.0, 40.03], [-74.99, 40.03], [-74.98, 40.03], [-75.0, 40.03]]]], 'zero area'];
        yield 'bowtie' => [['type' => 'Polygon', 'coordinates' => [[[-75.0, 40.03], [-74.98, 40.04], [-74.98, 40.03], [-75.0, 40.045], [-75.0, 40.03]]]], 'self-intersecting'];
        yield 'hole outside' => [['type' => 'Polygon', 'coordinates' => [self::ring(-75.0, 40.03, 0.01), self::ring(-74.9, 40.03, 0.002)]], 'outside the exterior'];
        yield 'hole crossing shell' => [['type' => 'Polygon', 'coordinates' => [self::ring(-75.0, 40.03, 0.01), self::ring(-74.995, 40.035, 0.01)]], 'intersect'];
        yield 'empty collection' => [['type' => 'FeatureCollection', 'features' => []], 'No Polygon'];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidGeometry(mixed $input, string $expected): void
    {
        try {
            (new GeoJsonParser())->parse($input);
            self::fail('Expected GeometryException');
        } catch (GeometryException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testDetectsLatLonOrderSwapAgainstConfiguredCenter(): void
    {
        $swapped = [[40.03, -75.0], [40.04, -75.0], [40.04, -74.99], [40.03, -74.99], [40.03, -75.0]];
        $this->expectException(GeometryException::class);
        $this->expectExceptionMessage('[latitude, longitude]');
        (new GeoJsonParser())->parse(['type' => 'Polygon', 'coordinates' => [$swapped]], self::CENTER);
    }

    public function testRejectsGeometryFarFromConfiguredCenter(): void
    {
        $this->expectExceptionMessage('km from the configured map centre');
        (new GeoJsonParser())->parse(['type' => 'Polygon', 'coordinates' => [self::ring(-100.0, 40.0, 0.01)]], self::CENTER);
    }

    public function testRejectsTooManyVertices(): void
    {
        $ring = [];
        for ($i = 0; $i < 5100; ++$i) {
            $a = 2 * M_PI * $i / 5100;
            $ring[] = [-75.0 + 0.01 * cos($a), 40.03 + 0.01 * sin($a)];
        }
        $ring[] = $ring[0];
        $this->expectExceptionMessage('limit is 5000');
        (new GeoJsonParser())->parse(['type' => 'Polygon', 'coordinates' => [$ring]]);
    }

    public function testOfficialVenturaReservePlatsParse(): void
    {
        $dir = \dirname(__DIR__, 3).'/examples/ventura-reserve/boundaries';
        $center = ['lat' => 28.508, 'lon' => -81.295];
        $p = new GeoJsonParser();
        $zones = $p->parse((string) file_get_contents($dir.'/zones.geojson'), $center);
        self::assertSame(['phase-1', 'phase-2', 'phase-3'], array_map(fn ($f) => $f['properties']['zone_key'], $zones));
        foreach ($zones as $z) {
            self::assertTrue($z['properties']['claimable']);
            self::assertTrue($z['properties']['residential']);
        }
        $boundary = $p->parse((string) file_get_contents($dir.'/boundary.geojson'), $center);
        self::assertFalse($boundary[0]['properties']['approved']);
        self::assertCount(1, $boundary[0]['shape']->polygons, 'the three recorded plats form one contiguous outline');
    }
}
