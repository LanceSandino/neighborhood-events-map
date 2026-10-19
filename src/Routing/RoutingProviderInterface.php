<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * @module Routing/RoutingProviderInterface
 * @description Pedestrian routing adapter. Implementations return provider-reported geometry only;
 *     the app never invents paths.
 */
interface RoutingProviderInterface
{
    public function name(): string;

    public function available(): bool;

    /**
     * @param list<array{lat:float, lon:float}> $points ordered stops
     *
     * @return array{geometry: array<string,mixed>, distance_m: float, duration_s: float}
     */
    public function walkingRoute(array $points): array;
}
