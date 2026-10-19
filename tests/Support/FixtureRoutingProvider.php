<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Routing\RoutingProviderInterface;

/**
 * @module tests/Support/FixtureRoutingProvider
 * @description Test double: a "configured" provider that echoes the stops as a LineString and counts calls.
 */
final class FixtureRoutingProvider implements RoutingProviderInterface
{
    public static int $calls = 0;
    public static bool $available = true;

    public function name(): string
    {
        return 'fixture';
    }

    public function available(): bool
    {
        return self::$available;
    }

    public function walkingRoute(array $points): array
    {
        ++self::$calls;

        return ['geometry' => ['type' => 'LineString', 'coordinates' => array_map(fn ($p) => [$p['lon'], $p['lat']], $points)], 'distance_m' => 123.0, 'duration_s' => 99.0];
    }
}
