<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * @module Routing/RoutingProviderInterface
 * @description Routing provider adapter. Implementations return provider-reported geometry only; the app never
 *     invents paths. A route is asked for a provider profile (walking: foot-walking; driving: driving-car).
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

    /**
     * @param list<array{lat:float, lon:float}> $points  ordered stops
     * @param string                            $profile the provider's profile, e.g. foot-walking or driving-car
     *
     * @return array{geometry: array<string,mixed>, distance_m: float, duration_s: float}
     */
    public function route(array $points, string $profile): array;
}
