<?php

declare(strict_types=1);

namespace App\Geo;

/**
 * @module Geo/Haversine
 * @description Great-circle distance in metres between two WGS84 points (no PostGIS needed).
 */
final class Haversine
{
    private const EARTH_RADIUS_M = 6_371_008.8;

    public static function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lon2 - $lon1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }
}
