<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * @module Geo/ZoneResolver
 * @description Resolves which configured zone polygon contains a point and estimates pairwise
 *     zone overlaps for organizer warnings. Ambiguous points (inside 2+ zones) are reported,
 *     never silently assigned.
 *
 * @input array<string, PolygonSet> zone polygons keyed by zone_key
 * @output list<string> matching keys; overlap estimates in square metres
 * @dependencies Geo/PolygonSet, Geo/SegmentIntersection
 */
final class ZoneResolver
{
    /** @param array<string, PolygonSet> $zones */
    public function __construct(private readonly array $zones)
    {
    }

    /** @return list<string> */
    public function zonesContaining(float $lat, float $lon): array
    {
        $hits = [];
        foreach ($this->zones as $key => $shape) {
            if ($shape->contains($lat, $lon)) {
                $hits[] = (string) $key;
            }
        }

        return $hits;
    }

    /**
     * Grid-sampled overlap estimate between every pair of zones whose bounding boxes intersect.
     * Hairline digitizing slivers along shared edges fall below the sampling resolution.
     *
     * @return list<array{a:string,b:string,approx_m2:float}>
     */
    public function overlaps(int $grid = 50, float $minimumM2 = 100.0): array
    {
        $keys = array_keys($this->zones);
        $out = [];
        for ($i = 0; $i < \count($keys); ++$i) {
            for ($j = $i + 1; $j < \count($keys); ++$j) {
                $a = $this->zones[$keys[$i]];
                $b = $this->zones[$keys[$j]];
                $area = self::estimateOverlap($a, $b, $grid);
                if ($area >= $minimumM2) {
                    $out[] = ['a' => (string) $keys[$i], 'b' => (string) $keys[$j], 'approx_m2' => round($area, 1)];
                }
            }
        }

        return $out;
    }

    public static function estimateOverlap(PolygonSet $a, PolygonSet $b, int $grid): float
    {
        $ba = $a->bbox();
        $bb = $b->bbox();
        if (null === $ba || null === $bb) {
            return 0.0;
        }
        $minLon = max($ba['minLon'], $bb['minLon']);
        $maxLon = min($ba['maxLon'], $bb['maxLon']);
        $minLat = max($ba['minLat'], $bb['minLat']);
        $maxLat = min($ba['maxLat'], $bb['maxLat']);
        if ($minLon >= $maxLon || $minLat >= $maxLat) {
            return 0.0;
        }
        $stepLon = ($maxLon - $minLon) / $grid;
        $stepLat = ($maxLat - $minLat) / $grid;
        $cellM2 = Haversine::meters($minLat, $minLon, $minLat, $minLon + $stepLon) * Haversine::meters($minLat, $minLon, $minLat + $stepLat, $minLon);
        $hits = 0;
        for ($x = 0; $x < $grid; ++$x) {
            for ($y = 0; $y < $grid; ++$y) {
                $lon = $minLon + ($x + 0.5) * $stepLon;
                $lat = $minLat + ($y + 0.5) * $stepLat;
                if ($a->containsInterior($lat, $lon) && $b->containsInterior($lat, $lon)) {
                    ++$hits;
                }
            }
        }

        return $hits * $cellM2;
    }
}
