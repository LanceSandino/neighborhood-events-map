<?php

declare(strict_types=1);

namespace App\Community;

use App\Geo\PolygonSet;
use App\Geo\ZoneResolver;

/**
 * @module Community/CommunityConfig
 * @description Read-only view of the active community: normalized settings plus the active
 *     boundary and zone polygons and the boundary approval state. Answers the questions the
 *     rest of the app asks ("is registration open?", "which zone contains this point?").
 *
 * @input normalized settings (CommunitySchema) and geometry rows
 * @output typed getters
 * @dependencies Geo/PolygonSet, Geo/ZoneResolver
 */
final class CommunityConfig
{
    /**
     * @param array<string,mixed>       $settings
     * @param array<string, PolygonSet> $zoneShapes
     */
    public function __construct(
        public readonly array $settings,
        public readonly ?PolygonSet $boundary,
        public readonly bool $boundaryApproved,
        public readonly ?int $boundaryVersion,
        public readonly array $zoneShapes,
        public readonly bool $configured = true,
    ) {
    }

    public static function unconfigured(): self
    {
        $settings = (new CommunitySchema())->normalize([
            'community' => ['id' => 'unconfigured', 'name' => 'Neighborhood Halloween'],
            'event' => ['date' => (new \DateTimeImmutable('now'))->format('Y').'-10-31'],
            'registration' => ['enabled' => false],
        ]);

        return new self($settings, null, false, null, [], false);
    }

    public function name(): string
    {
        return $this->settings['community']['name'];
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->settings['community']['timezone']);
    }

    public function eventDate(): string
    {
        return $this->settings['event']['date'];
    }

    /**
     * Zones with their status (settings saved before zone status existed count as active).
     *
     * @return list<array{key:string,label:string,claimable:bool,residential:bool,color:?string,description:string,status:string}>
     */
    public function zones(): array
    {
        return array_map(static fn (array $z) => $z + ['status' => 'active'], $this->settings['zones']);
    }

    /** @return array{key:string,label:string,claimable:bool,residential:bool,color:?string,description:string,status:string}|null */
    public function zone(?string $key): ?array
    {
        foreach ($this->zones() as $z) {
            if ($z['key'] === $key) {
                return $z;
            }
        }

        return null;
    }

    /** A zone the organizer has hidden is not public at all (D-20261019-12): no name, no key, no outline. */
    public function isHiddenZone(?string $key): bool
    {
        return null !== $key && 'hidden' === ($this->zone($key)['status'] ?? null);
    }

    /** @return list<string> keys of zones the organizer has hidden from the public */
    public function hiddenZoneKeys(): array
    {
        return array_values(array_map(static fn (array $z) => $z['key'], array_filter($this->zones(), static fn (array $z) => 'hidden' === $z['status'])));
    }

    public function zonesEnabled(): bool
    {
        return [] !== $this->zones() && $this->settings['features']['optional_zones'];
    }

    /** @return array<string, bool> */
    public function features(): array
    {
        return $this->settings['features'];
    }

    public function addressMode(): string
    {
        return $this->settings['registration']['address_mode'];
    }

    /** @return list<string> */
    public function streetAllowlist(): array
    {
        return $this->settings['registration']['street_allowlist'];
    }

    /**
     * Claims require: configured community, active approved boundary, registration enabled.
     *
     * @return array{open:bool, reason:?string}
     */
    public function registrationState(): array
    {
        if (!$this->configured) {
            return ['open' => false, 'reason' => 'This site has not been set up yet.'];
        }
        if (null === $this->boundary) {
            return ['open' => false, 'reason' => 'The organizer has not configured the neighborhood boundary yet.'];
        }
        if (!$this->boundaryApproved) {
            return ['open' => false, 'reason' => 'House listings open after the organizer reviews and approves the neighborhood boundary.'];
        }
        if (!$this->settings['registration']['enabled']) {
            return ['open' => false, 'reason' => 'House listings are closed right now.'];
        }

        return ['open' => true, 'reason' => null];
    }

    public function insideBoundary(float $lat, float $lon): bool
    {
        return null !== $this->boundary && $this->boundary->contains($lat, $lon);
    }

    /** @return list<string> */
    public function zonesAt(float $lat, float $lon): array
    {
        return (new ZoneResolver($this->zoneShapes))->zonesContaining($lat, $lon);
    }

    /** @return array{lat:float,lon:float}|null */
    public function mapCenter(): ?array
    {
        return $this->settings['map']['center'] ?? $this->boundary?->center();
    }
}
