<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * @module Geo/GeoJsonParser
 * @description Parses and strictly validates GeoJSON Polygon/MultiPolygon/Feature/FeatureCollection
 *     input into features of PolygonSets. Rejects malformed or unclosed rings, out-of-range or
 *     non-finite coordinates, zero-area and self-intersecting rings, holes outside their shell,
 *     oversize input and (when an expected centre is known) likely [lat, lon] order mistakes.
 *
 * @input string|array GeoJSON, optional expected centre {lat, lon}
 * @output list<array{properties: array<string,mixed>, shape: PolygonSet}>
 * @dependencies Geo/PolygonSet, Geo/GeometryException, Geo/Haversine
 */
final class GeoJsonParser
{
    public const MAX_BYTES = 2_000_000;
    public const MAX_VERTICES = 20_000;
    public const MAX_RING_VERTICES = 5_000;
    public const MAX_DISTANCE_FROM_CENTER_M = 100_000;

    /**
     * @param array{lat:float,lon:float}|null $expectedCenter
     *
     * @return list<array{properties: array<string,mixed>, shape: PolygonSet}>
     */
    public function parse(string|array $input, ?array $expectedCenter = null): array
    {
        if (\is_string($input)) {
            if (\strlen($input) > self::MAX_BYTES) {
                throw new GeometryException(['GeoJSON is larger than 2 MB.']);
            }
            try {
                $input = json_decode($input, true, 64, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new GeometryException(['File is not valid JSON.']);
            }
            if (!\is_array($input)) {
                throw new GeometryException(['GeoJSON must be an object.']);
            }
        }

        $features = $this->collectFeatures($input);
        if ([] === $features) {
            throw new GeometryException(['No Polygon or MultiPolygon features found.']);
        }

        $errors = [];
        $result = [];
        $total = 0;
        foreach ($features as $i => [$geometry, $properties]) {
            $label = sprintf('Feature %d', $i + 1);
            try {
                $polygons = $this->polygonsFromGeometry($geometry, $label);
                $shape = new PolygonSet($polygons);
                $total += $shape->vertexCount();
                $result[] = ['properties' => $properties, 'shape' => $shape];
            } catch (GeometryException $e) {
                array_push($errors, ...$e->errors);
            }
        }
        if ($total > self::MAX_VERTICES) {
            $errors[] = sprintf('Too many vertices (%d); the limit is %d.', $total, self::MAX_VERTICES);
        }
        if ([] === $errors && null !== $expectedCenter) {
            $errors = $this->checkLocation($result, $expectedCenter);
        }
        if ([] !== $errors) {
            throw new GeometryException($errors);
        }

        return $result;
    }

    /** @return list<array{0: mixed, 1: array<string,mixed>}> */
    private function collectFeatures(array $input): array
    {
        $type = $input['type'] ?? null;

        return match ($type) {
            'FeatureCollection' => array_values(array_map(
                fn ($f) => \is_array($f) && 'Feature' === ($f['type'] ?? null)
                    ? [$f['geometry'] ?? null, \is_array($f['properties'] ?? null) ? $f['properties'] : []]
                    : throw new GeometryException(['FeatureCollection contains a non-Feature item.']),
                \is_array($input['features'] ?? null) ? $input['features'] : throw new GeometryException(['FeatureCollection has no features array.']),
            )),
            'Feature' => [[$input['geometry'] ?? null, \is_array($input['properties'] ?? null) ? $input['properties'] : []]],
            'Polygon', 'MultiPolygon' => [[$input, []]],
            default => throw new GeometryException([sprintf('Unsupported GeoJSON type "%s"; use Polygon, MultiPolygon, Feature or FeatureCollection.', \is_string($type) ? $type : '?')]),
        };
    }

    /** @return list<list<list<array{0:float,1:float}>>> */
    private function polygonsFromGeometry(mixed $geometry, string $label): array
    {
        if (!\is_array($geometry)) {
            throw new GeometryException([$label.' has no geometry.']);
        }
        $coords = $geometry['coordinates'] ?? null;
        if (!\is_array($coords)) {
            throw new GeometryException([$label.' has no coordinates.']);
        }

        return match ($geometry['type'] ?? null) {
            'Polygon' => [$this->polygon($coords, $label)],
            'MultiPolygon' => array_values(array_map(
                fn ($p, $k) => $this->polygon(\is_array($p) ? $p : [], sprintf('%s part %d', $label, $k + 1)),
                $coords,
                array_keys($coords),
            )),
            default => throw new GeometryException([sprintf('%s geometry must be Polygon or MultiPolygon.', $label)]),
        };
    }

    /** @return list<list<array{0:float,1:float}>> */
    private function polygon(array $rings, string $label): array
    {
        if ([] === $rings) {
            throw new GeometryException([$label.' has no rings.']);
        }
        $errors = [];
        $parsed = [];
        foreach (array_values($rings) as $r => $ring) {
            $name = 0 === $r ? $label.' exterior ring' : sprintf('%s hole %d', $label, $r);
            try {
                $parsed[] = $this->ring(\is_array($ring) ? $ring : [], $name);
            } catch (GeometryException $e) {
                array_push($errors, ...$e->errors);
            }
        }
        if ([] !== $errors) {
            throw new GeometryException($errors);
        }
        // Holes must sit inside the shell and no two rings may cross.
        for ($a = 0; $a < \count($parsed); ++$a) {
            if ($a > 0 && !PolygonSet::polygonContains([$parsed[0]], $parsed[$a][0][0], $parsed[$a][0][1])) {
                $errors[] = sprintf('%s hole %d lies outside the exterior ring.', $label, $a);
            }
            for ($b = $a + 1; $b < \count($parsed); ++$b) {
                if (SegmentIntersection::ringsCross($parsed[$a], $parsed[$b])) {
                    $errors[] = sprintf('%s rings %d and %d intersect.', $label, $a, $b);
                }
            }
        }
        if ([] !== $errors) {
            throw new GeometryException($errors);
        }

        return $parsed;
    }

    /** @return list<array{0:float,1:float}> */
    private function ring(array $positions, string $name): array
    {
        $n = \count($positions);
        if ($n < 4) {
            throw new GeometryException([$name.' needs at least 4 positions (a closed triangle).']);
        }
        if ($n > self::MAX_RING_VERTICES) {
            throw new GeometryException([sprintf('%s has %d vertices; the limit is %d.', $name, $n, self::MAX_RING_VERTICES)]);
        }
        $ring = [];
        foreach (array_values($positions) as $p) {
            if (!\is_array($p) || \count($p) < 2 || \count($p) > 3 || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null)) {
                throw new GeometryException([$name.' contains a malformed position.']);
            }
            $lon = (float) $p[0];
            $lat = (float) $p[1];
            if (!is_finite($lon) || !is_finite($lat) || $lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                throw new GeometryException([sprintf('%s has out-of-range coordinate [%s, %s]; GeoJSON order is [longitude, latitude].', $name, $p[0], $p[1])]);
            }
            $ring[] = [$lon, $lat];
        }
        if ($ring[0] !== $ring[$n - 1]) {
            throw new GeometryException([$name.' is not closed (first and last positions differ).']);
        }
        if (abs(self::signedArea($ring)) < 1e-12) {
            throw new GeometryException([$name.' has zero area.']);
        }
        if (SegmentIntersection::ringSelfIntersects($ring)) {
            throw new GeometryException([$name.' is self-intersecting.']);
        }

        return $ring;
    }

    /** @param list<array{0:float,1:float}> $ring */
    public static function signedArea(array $ring): float
    {
        $sum = 0.0;
        $n = \count($ring);
        for ($i = 0; $i < $n - 1; ++$i) {
            $sum += $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
        }

        return $sum / 2;
    }

    /**
     * @param list<array{properties: array<string,mixed>, shape: PolygonSet}> $features
     * @param array{lat:float,lon:float}                                      $center
     *
     * @return list<string>
     */
    private function checkLocation(array $features, array $center): array
    {
        $all = new PolygonSet([]);
        foreach ($features as $f) {
            $all = $all->merge($f['shape']);
        }
        $c = $all->center();
        if (null === $c) {
            return [];
        }
        $distance = Haversine::meters($c['lat'], $c['lon'], $center['lat'], $center['lon']);
        if ($distance <= self::MAX_DISTANCE_FROM_CENTER_M) {
            return [];
        }
        $swapped = Haversine::meters($c['lon'], $c['lat'], $center['lat'], $center['lon']);
        if ($swapped <= self::MAX_DISTANCE_FROM_CENTER_M) {
            return ['Coordinates look like [latitude, longitude]; GeoJSON requires [longitude, latitude].'];
        }

        return [sprintf('Geometry is %.0f km from the configured map centre; check the file and coordinate order.', $distance / 1000)];
    }
}
