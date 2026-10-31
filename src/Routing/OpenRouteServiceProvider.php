<?php

declare(strict_types=1);

namespace App\Routing;

use App\Shared\RuntimeConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @module Routing/OpenRouteServiceProvider
 * @description openrouteservice directions, "foot-walking" (and "driving-car" for an event type and organizer
 *     that allow driving), server-side; the API key never reaches browsers. Active only when
 *     ROUTING_PROVIDER=openrouteservice and ROUTING_API_KEY are set.
 */
final class OpenRouteServiceProvider implements RoutingProviderInterface
{
    public function __construct(private readonly HttpClientInterface $http, private readonly RuntimeConfig $config)
    {
    }

    public function name(): string
    {
        return 'openrouteservice';
    }

    public function available(): bool
    {
        return 'openrouteservice' === $this->config->routingProvider && '' !== trim($this->config->routingApiKey);
    }

    public function walkingRoute(array $points): array
    {
        return $this->route($points, 'foot-walking');
    }

    public function route(array $points, string $profile): array
    {
        if (!\in_array($profile, ['foot-walking', 'driving-car'], true)) {
            throw new RoutingUnavailableException('That route profile is not available.');
        }
        if (!$this->available()) {
            throw new RoutingUnavailableException('No routing provider is configured.');
        }
        try {
            $response = $this->http->request('POST', rtrim($this->config->routingBaseUrl, '/').'/v2/directions/'.$profile.'/geojson', [
                'headers' => ['Authorization' => $this->config->routingApiKey, 'Accept' => 'application/geo+json'],
                'json' => ['coordinates' => array_map(static fn (array $p) => [$p['lon'], $p['lat']], $points), 'instructions' => false],
                'timeout' => 5, // an outside provider must never hold a PHP worker for long
            ]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new RoutingUnavailableException('The routing provider did not answer.', 0, $e);
        }
        $feature = $data['features'][0] ?? null;
        $geometry = $feature['geometry'] ?? null;
        $summary = $feature['properties']['summary'] ?? null;
        if (!\is_array($geometry) || 'LineString' !== ($geometry['type'] ?? null) || !\is_array($summary)) {
            throw new RoutingUnavailableException('The routing provider returned no usable route.');
        }

        return ['geometry' => $geometry, 'distance_m' => (float) ($summary['distance'] ?? 0), 'duration_s' => (float) ($summary['duration'] ?? 0)];
    }
}
