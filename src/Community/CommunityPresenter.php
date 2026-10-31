<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventTypeRegistry;
use App\Routing\RoutingProviderInterface;
use App\Shared\RuntimeConfig;

/**
 * @module Community/CommunityPresenter
 * @description Builds the PUBLIC community payload (branding, event, map extent, enabled zones,
 *     registration state, stats). Never includes addresses, emails, admin identity or session data.
 *     The boundary outline is published only after organizer approval. The event carries its type key; the
 *     type supplies the default reminders and its own stats counts.
 *
 * @dependencies Community/CommunityConfig, Community/BrandingStore, Event/EventTypeRegistry, Routing/RoutingProviderInterface, Shared/RuntimeConfig
 */
final class CommunityPresenter
{
    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly RoutingProviderInterface $routing,
        private readonly string $appVersion,
        private readonly BrandingStore $branding,
        private readonly EventTypeRegistry $types,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $homes public homes (for stats)
     *
     * @return array<string,mixed>
     */
    public function publicPayload(CommunityConfig $c, array $homes): array
    {
        $s = $c->settings;
        $type = $this->types->for($c);
        $approved = $c->boundaryApproved && null !== $c->boundary;
        // Hidden zones are left out of the public payload entirely; "coming soon" zones are drawn but not listable.
        $zones = $c->zonesEnabled() ? array_values(array_map(static fn (array $z) => [
            'key' => $z['key'], 'label' => $z['label'], 'claimable' => $z['claimable'] && 'active' === $z['status'], 'residential' => $z['residential'],
            'color' => $z['color'], 'description' => $z['description'], 'status' => $z['status'], 'label_at' => $z['label_at'] ?? null,
            'geometry' => $approved && isset($c->zoneShapes[$z['key']]) ? $c->zoneShapes[$z['key']]->toGeometry() : null,
        ], array_filter($c->zones(), static fn (array $z) => 'hidden' !== $z['status']))) : [];
        $state = $c->registrationState();

        return [
            'configured' => $c->configured,
            // Defaults keep settings saved by older versions valid (fields added later are optional).
            'community' => array_intersect_key($s['community'] + ['about' => '', 'access' => 'unspecified', 'links' => [], 'contact_url' => null, 'gate_notice' => ''],
                array_flip(['id', 'name', 'subtitle', 'locality', 'region', 'postal_code', 'country', 'gate_notice', 'contact_url', 'about', 'access', 'links']))
                + ['reminders' => ($s['community']['reminders'] ?? []) ?: $type->copy()['reminders']],
            'event' => $s['event'] + ['type' => $type->key(), 'timezone' => $s['community']['timezone']],
            'branding' => [
                'logo_url' => $this->branding->url($s['branding']['logo']),
            ] + array_intersect_key($s['branding'], array_flip(['brand_emoji', 'primary_color', 'accent_color', 'headline', 'headline_accent', 'intro', 'show_locality'])),
            // Nothing of a boundary that is not approved yet is published, not even its extent: until then the
            // map opens on the configured center (or the boundary's middle) and zoom.
            'map' => [
                'center' => $c->mapCenter(),
                'zoom' => $s['map']['zoom'],
                'tiles' => $s['map']['tiles'],
                'bbox' => $approved ? $c->boundary->bbox() : null,
                'boundary' => $approved ? $c->boundary->toGeometry() : null,
                'boundary_status' => null === $c->boundary ? 'none' : ($approved ? 'approved' : 'pending_review'),
                'crossing_notes' => $s['map']['crossing_notes'],
            ],
            'zones' => $zones,
            'features' => $s['features'],
            'registration' => $state + [
                'address_mode' => $s['registration']['address_mode'],
                'street_allowlist' => 'registry' !== $s['registration']['address_mode'] ? $s['registration']['street_allowlist'] : [],
                'review_new_addresses' => (bool) ($s['registration']['review_new_addresses'] ?? false),
            ],
            // Walking groups keep their positions (labels follow them) but never name a hidden zone.
            'routing' => ['groups' => array_map(static fn (array $g) => array_values(array_diff($g, $c->hiddenZoneKeys())), $s['routing']['groups']), 'group_labels' => $s['routing']['group_labels'] ?? [], 'crossings_verified' => $s['routing']['crossings_verified'],
                'provider' => $s['routing']['provider_enabled'] && $s['routing']['crossings_verified'] && $this->routing->available()],
            'stats' => ['homes' => \count($homes)] + $type->stats($homes) + [
                'zones' => \count(array_filter($zones, static fn (array $z) => $z['residential'] && 'active' === $z['status'])),
            ],
            'demo' => $this->config->demoMode,
            'version' => $this->appVersion,
        ];
    }
}
