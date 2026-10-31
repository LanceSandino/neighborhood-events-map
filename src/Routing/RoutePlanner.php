<?php

declare(strict_types=1);

namespace App\Routing;

use App\Community\CommunityConfig;
use App\Event\EventTypeRegistry;
use App\Listing\ListingRepository;
use App\Security\Throttle;
use App\Shared\ValidationException;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Routing/RoutePlanner
 * @description Optional provider-reported path for ONE trip, by profile: walking (the default) requires an
 *     organizer-enabled provider, verified crossings, 2-20 public stops of the active event, all inside a single
 *     configured walkable group; driving is offered only when the event type allows it, the active event's settings
 *     enable it (route_modes) and a provider is configured. Responses are cached per provider, profile and stops
 *     (24 h); provider calls share one global budget. Never connects separate walking groups; never fabricates geometry.
 *
 * @dependencies Routing/RoutingProviderInterface, Listing/ListingRepository, Event/EventTypeRegistry, Security/Throttle, DBAL, clock
 */
final class RoutePlanner
{
    public const MAX_STOPS = 20;
    /** Route profiles and the provider profile each asks for. */
    public const PROFILES = ['walking' => 'foot-walking', 'driving' => 'driving-car'];

    public function __construct(
        private readonly RoutingProviderInterface $provider,
        private readonly ListingRepository $listings,
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly Throttle $throttle,
        private readonly EventTypeRegistry $types,
    ) {
    }

    public function enabled(CommunityConfig $c, string $profile = 'walking'): bool
    {
        $configured = $c->settings['routing']['provider_enabled'] && $this->provider->available();
        if ('walking' === $profile) {
            return $configured && $c->settings['routing']['crossings_verified'];
        }
        if (!isset(self::PROFILES[$profile]) || null === $c->event) {
            return false;
        }

        return $configured && \in_array($profile, $this->types->for($c)->routeProfiles(), true)
            && \in_array($profile, (array) ($c->event->settings['route_modes'] ?? ['walking']), true);
    }

    /**
     * @param list<mixed> $stopIds ordered public home ids
     *
     * @return array<string,mixed>
     */
    public function plan(array $stopIds, CommunityConfig $c, string $profile = 'walking'): array
    {
        if (!isset(self::PROFILES[$profile])) {
            throw new ValidationException(['profile' => 'Choose walking or driving.']);
        }
        if (!$this->enabled($c, $profile)) {
            throw new RoutingUnavailableException('walking' === $profile
                ? 'Walking paths are not available here. Use the suggested stop order and real sidewalks.'
                : 'Driving directions are not available for this event. Use the suggested stop order.');
        }
        $ids = array_values(array_unique(array_map('intval', $stopIds)));
        if (\count($ids) < 2 || \count($ids) > self::MAX_STOPS) {
            throw new ValidationException(['stops' => sprintf('Choose between 2 and %d stops.', self::MAX_STOPS)]);
        }
        $stops = [];
        foreach ($ids as $id) {
            $home = $this->listings->publicHome($id, $c, $this->types->for($c)) ?? throw new ValidationException(['stops' => 'One of those homes is not listed.']);
            $stops[] = $home;
        }
        if ('walking' === $profile && $c->zonesEnabled()) {
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
        $providerProfile = self::PROFILES[$profile];
        $fingerprint = hash('sha256', $this->provider->name().'|'.$providerProfile.'|'.json_encode($points));
        $now = $this->clock->now();
        $cached = $this->db->fetchOne('SELECT response FROM route_cache WHERE fingerprint = ? AND expires_at > ?', [$fingerprint, $now->format('Y-m-d H:i:s.uP')]);
        if (false !== $cached) {
            $route = json_decode((string) $cached, true, 64, \JSON_THROW_ON_ERROR);
        } else {
            // Provider calls share one global budget (RL_ROUTE_GLOBAL), so many clients cannot spend the API quota.
            $this->throttle->hit('route_global', 'global');
            $route = $this->provider->route($points, $providerProfile);
            $this->db->executeStatement(
                'INSERT INTO route_cache (fingerprint, provider, profile, response, expires_at) VALUES (:f, :p, :pr, :r, :e)
                 ON CONFLICT (fingerprint) DO UPDATE SET response = EXCLUDED.response, expires_at = EXCLUDED.expires_at',
                ['f' => $fingerprint, 'p' => $this->provider->name(), 'pr' => $providerProfile, 'r' => json_encode($route, \JSON_THROW_ON_ERROR), 'e' => $now->modify('+24 hours')->format('Y-m-d H:i:s.uP')],
            );
        }

        return $route + ['provider' => $this->provider->name(), 'provider_reported' => true, 'stops' => $ids, 'profile' => $profile,
            'notice' => 'walking' === $profile
                ? 'Walking path reported by '.$this->provider->name().'. Check sidewalks, crossings and gate rules yourself.'
                : 'Driving route reported by '.$this->provider->name().'. Follow traffic rules, park legally and never block driveways.'];
    }
}
