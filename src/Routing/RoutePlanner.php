<?php

declare(strict_types=1);

namespace App\Routing;

use App\Community\CommunityConfig;
use App\Listing\ListingRepository;
use App\Shared\ValidationException;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Routing/RoutePlanner
 * @description Optional provider-reported walking path for ONE trip: requires an organizer-enabled
 *     provider, verified crossings, 2-20 public stops, all inside a single configured walkable group.
 *     Responses are cached (24 h). Never connects separate groups; never fabricates geometry.
 */
final class RoutePlanner
{
    public const MAX_STOPS = 20;

    public function __construct(
        private readonly RoutingProviderInterface $provider,
        private readonly ListingRepository $listings,
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {
    }

    public function enabled(CommunityConfig $c): bool
    {
        return $c->settings['routing']['provider_enabled'] && $c->settings['routing']['crossings_verified'] && $this->provider->available();
    }

    /**
     * @param list<mixed> $stopIds ordered public home ids
     *
     * @return array<string,mixed>
     */
    public function plan(array $stopIds, CommunityConfig $c): array
    {
        if (!$this->enabled($c)) {
            throw new RoutingUnavailableException('Walking paths are not available here. Use the suggested stop order and real sidewalks.');
        }
        $ids = array_values(array_unique(array_map('intval', $stopIds)));
        if (\count($ids) < 2 || \count($ids) > self::MAX_STOPS) {
            throw new ValidationException(['stops' => sprintf('Choose between 2 and %d stops.', self::MAX_STOPS)]);
        }
        $stops = [];
        foreach ($ids as $id) {
            $home = $this->listings->publicHome($id, $c->eventDate()) ?? throw new ValidationException(['stops' => 'One of those homes is not listed.']);
            $stops[] = $home;
        }
        if ($c->zonesEnabled()) {
            $groupOf = static function (?string $zone) use ($c): ?int {
                foreach ($c->settings['routing']['groups'] as $i => $g) {
                    if (\in_array($zone, $g, true)) {
                        return $i;
                    }
                }

                return null;
            };
            $groups = array_unique(array_map(static fn (array $h) => $groupOf($h['zone']), $stops), \SORT_REGULAR);
            if (1 !== \count($groups) || null === reset($groups)) {
                throw new ValidationException(['stops' => 'Those stops are in separate areas. Plan each area as its own trip.']);
            }
        }
        $points = array_map(static fn (array $h) => ['lat' => $h['lat'], 'lon' => $h['lon']], $stops);
        $fingerprint = hash('sha256', $this->provider->name().'|foot-walking|'.json_encode($points));
        $now = $this->clock->now();
        $cached = $this->db->fetchOne('SELECT response FROM route_cache WHERE fingerprint = ? AND expires_at > ?', [$fingerprint, $now->format('Y-m-d H:i:s.uP')]);
        if (false !== $cached) {
            $route = json_decode((string) $cached, true, 64, \JSON_THROW_ON_ERROR);
        } else {
            $route = $this->provider->walkingRoute($points);
            $this->db->executeStatement(
                'INSERT INTO route_cache (fingerprint, provider, profile, response, expires_at) VALUES (:f, :p, :pr, :r, :e)
                 ON CONFLICT (fingerprint) DO UPDATE SET response = EXCLUDED.response, expires_at = EXCLUDED.expires_at',
                ['f' => $fingerprint, 'p' => $this->provider->name(), 'pr' => 'foot-walking', 'r' => json_encode($route, \JSON_THROW_ON_ERROR), 'e' => $now->modify('+24 hours')->format('Y-m-d H:i:s.uP')],
            );
        }

        return $route + ['provider' => $this->provider->name(), 'provider_reported' => true, 'stops' => $ids,
            'notice' => 'Walking path reported by '.$this->provider->name().'. Check sidewalks, crossings and gate rules yourself.'];
    }
}
