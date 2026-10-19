<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * @module Geo/PolygonSet
 * @description Immutable set of polygons (each a list of [lon, lat] rings; ring 0 = exterior,
 *     others = holes) with point containment and bounding-box helpers. Coordinates follow
 *     GeoJSON/RFC 7946 order: [longitude, latitude].
 *
 * @input list<list<list<array{0:float,1:float}>>> polygons
 * @output contains(lat, lon), bbox(), center()
 * @dependencies none
 */
final class PolygonSet
{
    /** Degree tolerance for "on the edge" checks (~1 cm). */
    public const EDGE_EPSILON = 1e-7;

    /** @var list<array{0:float,1:float,2:float,3:float}> per-polygon [minLon, minLat, maxLon, maxLat] */
    private array $boxes = [];

    /** @param list<list<list<array{0:float,1:float}>>> $polygons */
    public function __construct(public readonly array $polygons)
    {
        foreach ($polygons as $i => $rings) {
            $minX = $minY = \INF;
            $maxX = $maxY = -\INF;
            foreach ($rings[0] ?? [] as [$x, $y]) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
            $this->boxes[$i] = [$minX, $minY, $maxX, $maxY];
        }
    }

    public function isEmpty(): bool
    {
        return [] === $this->polygons;
    }

    public function vertexCount(): int
    {
        $n = 0;
        foreach ($this->polygons as $rings) {
            foreach ($rings as $ring) {
                $n += \count($ring);
            }
        }

        return $n;
    }

    /**
     * Edge-inclusive containment: a point on an exterior or hole boundary counts as inside.
     */
    public function contains(float $lat, float $lon): bool
    {
        $e = self::EDGE_EPSILON;
        foreach ($this->polygons as $i => $rings) {
            [$minX, $minY, $maxX, $maxY] = $this->boxes[$i];
            if ($lon < $minX - $e || $lon > $maxX + $e || $lat < $minY - $e || $lat > $maxY + $e) {
                continue;
            }
            if (self::polygonContains($rings, $lon, $lat)) {
                return true;
            }
        }

        return false;
    }

    /** Interior test without edge tolerance (even-odd ray cast); used for area sampling only. */
    public function containsInterior(float $lat, float $lon): bool
    {
        foreach ($this->polygons as $i => $rings) {
            [$minX, $minY, $maxX, $maxY] = $this->boxes[$i];
            if ($lon < $minX || $lon > $maxX || $lat < $minY || $lat > $maxY) {
                continue;
            }
            $inside = false;
            foreach ($rings as $ring) {
                $n = \count($ring);
                for ($k = 0, $j = $n - 1; $k < $n; $j = $k++) {
                    if (($ring[$k][1] > $lat) !== ($ring[$j][1] > $lat)
                        && $lon < ($ring[$j][0] - $ring[$k][0]) * ($lat - $ring[$k][1]) / ($ring[$j][1] - $ring[$k][1]) + $ring[$k][0]) {
                        $inside = !$inside;
                    }
                }
            }
            if ($inside) {
                return true;
            }
        }

        return false;
    }

    public function onBoundary(float $lat, float $lon): bool
    {
        foreach ($this->polygons as $rings) {
            foreach ($rings as $ring) {
                if (self::onRing($ring, $lon, $lat)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<list<array{0:float,1:float}>> $rings */
    public static function polygonContains(array $rings, float $x, float $y): bool
    {
        foreach ($rings as $ring) {
            if (self::onRing($ring, $x, $y)) {
                return true;
            }
        }
        $inside = false;
        foreach ($rings as $ring) {
            $n = \count($ring);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                [$xi, $yi] = $ring[$i];
                [$xj, $yj] = $ring[$j];
                if (($yi > $y) !== ($yj > $y)) {
                    $xCross = ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi;
                    if ($x < $xCross) {
                        $inside = !$inside;
                    }
                }
            }
        }

        return $inside;
    }

    /** @param list<array{0:float,1:float}> $ring */
    private static function onRing(array $ring, float $x, float $y): bool
    {
        $n = \count($ring);
        for ($i = 0; $i < $n - 1; ++$i) {
            if (self::onSegment($ring[$i], $ring[$i + 1], $x, $y)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{0:float,1:float} $a
     * @param array{0:float,1:float} $b
     */
    private static function onSegment(array $a, array $b, float $x, float $y): bool
    {
        $minX = min($a[0], $b[0]) - self::EDGE_EPSILON;
        $maxX = max($a[0], $b[0]) + self::EDGE_EPSILON;
        $minY = min($a[1], $b[1]) - self::EDGE_EPSILON;
        $maxY = max($a[1], $b[1]) + self::EDGE_EPSILON;
        if ($x < $minX || $x > $maxX || $y < $minY || $y > $maxY) {
            return false;
        }
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len < 1e-15) {
            return abs($x - $a[0]) <= self::EDGE_EPSILON && abs($y - $a[1]) <= self::EDGE_EPSILON;
        }
        $cross = abs(($x - $a[0]) * $dy - ($y - $a[1]) * $dx) / $len;

        return $cross <= self::EDGE_EPSILON;
    }

    /** @return array{minLon:float,minLat:float,maxLon:float,maxLat:float}|null */
    public function bbox(): ?array
    {
        $box = null;
        foreach ($this->polygons as $rings) {
            foreach ($rings[0] ?? [] as [$lon, $lat]) {
                if (null === $box) {
                    $box = ['minLon' => $lon, 'minLat' => $lat, 'maxLon' => $lon, 'maxLat' => $lat];
                    continue;
                }
                $box['minLon'] = min($box['minLon'], $lon);
                $box['maxLon'] = max($box['maxLon'], $lon);
                $box['minLat'] = min($box['minLat'], $lat);
                $box['maxLat'] = max($box['maxLat'], $lat);
            }
        }

        return $box;
    }

    /** @return array{lat:float,lon:float}|null */
    public function center(): ?array
    {
        $b = $this->bbox();

        return null === $b ? null : ['lat' => ($b['minLat'] + $b['maxLat']) / 2, 'lon' => ($b['minLon'] + $b['maxLon']) / 2];
    }

    public function merge(self $other): self
    {
        return new self([...$this->polygons, ...$other->polygons]);
    }

    /** GeoJSON geometry object (Polygon or MultiPolygon). */
    public function toGeometry(): array
    {
        if (1 === \count($this->polygons)) {
            return ['type' => 'Polygon', 'coordinates' => $this->polygons[0]];
        }

        return ['type' => 'MultiPolygon', 'coordinates' => $this->polygons];
    }
}
