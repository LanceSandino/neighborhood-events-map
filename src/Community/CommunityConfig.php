<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventOccurrence;
use App\Event\EventTypeRegistry;
use App\Geo\PolygonSet;
use App\Geo\ZoneResolver;

/**
 * @module Community/CommunityConfig
 * @description Read-only view of the active community: normalized settings plus the active
 *     boundary and zone polygons and the boundary approval state. Answers the questions the
 *     rest of the app asks ("is registration open?", "which zone contains this point?"). The active event
 *     occurrence (Event/EventOccurrence) comes with it: its settings `event` block is a view of that occurrence;
 *     without an active event of a type this release offers, nothing is listed and registration is closed.
 *
 * @input normalized settings (CommunitySchema) and geometry rows
 * @output typed getters
 * @dependencies Geo/PolygonSet, Geo/ZoneResolver, Event/EventTypeRegistry (default type key), Event/EventOccurrence
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
        public readonly ?EventOccurrence $event = null,
    ) {
    }

    public static function unconfigured(): self
    {
        $settings = (new CommunitySchema())->normalize([
            'community' => ['id' => 'unconfigured', 'name' => 'Neighborhood'],
            'event' => ['name' => 'events map', 'date' => (new \DateTimeImmutable('now'))->format('Y').'-10-31'],
            'registration' => ['enabled' => false],
        ]);

        return new self($settings, null, false, null, [], false);
    }

    public function name(): string
    {
        return $this->settings['community']['name'];
    }

    /** The key of the community's event type (Event/EventTypeRegistry); settings saved before event.type existed are Halloween. */
    public function eventTypeKey(): string
    {
        return (string) ($this->settings['event']['type'] ?? EventTypeRegistry::DEFAULT);
    }

    /** The community's event as shown in titles and emails ("Halloween", "Holiday Lights"…). */
    public function eventName(): string
    {
        return $this->settings['event']['name'] ?? CommunitySchema::DEFAULT_EVENT_NAME;
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->settings['community']['timezone']);
    }

    /** The first day of the active event (the settings' event block mirrors it). */
    public function eventDate(): string
    {
        return $this->settings['event']['date'];
    }

    /** The community-local date of a moment (default: now), e.g. for day-scoped statuses. */
    public function localDate(\DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->timezone())->format('Y-m-d');
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
        if (null === $this->event) {
            return ['open' => false, 'reason' => 'There is no event on the map right now. House listings open when the organizer starts the next one.'];
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
