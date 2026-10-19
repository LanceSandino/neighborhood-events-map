<?php

declare(strict_types=1);

namespace App\Community;

use App\Routing\RoutingProviderInterface;
use App\Shared\RuntimeConfig;

/**
 * @module Community/CommunityPresenter
 * @description Builds the PUBLIC community payload (branding, event, map extent, enabled zones,
 *     registration state, stats). Never includes addresses, emails, admin identity or session data.
 *     The boundary outline is published only after organizer approval.
 */
final class CommunityPresenter
{
    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly RoutingProviderInterface $routing,
        private readonly string $appVersion,
        private readonly BrandingStore $branding,
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
        $approved = $c->boundaryApproved && null !== $c->boundary;
        // Hidden zones are left out of the public payload entirely; "coming soon" zones are drawn but not listable.
        $zones = $c->zonesEnabled() ? array_values(array_map(static fn (array $z) => [
            'key' => $z['key'], 'label' => $z['label'], 'claimable' => $z['claimable'] && 'active' === $z['status'], 'residential' => $z['residential'],
            'color' => $z['color'], 'description' => $z['description'], 'status' => $z['status'],
            'geometry' => $approved && isset($c->zoneShapes[$z['key']]) ? $c->zoneShapes[$z['key']]->toGeometry() : null,
        ], array_filter($c->zones(), static fn (array $z) => 'hidden' !== $z['status']))) : [];
        $state = $c->registrationState();
        $lowSensory = \count(array_filter($homes, static fn (array $h) => $h['low_noise'] && !$h['flashing_lights'] && !$h['jump_scares'] && !$h['loud_audio']));

        return [
            'configured' => $c->configured,
            // Defaults keep settings saved by older versions valid (fields added later are optional).
            'community' => array_intersect_key($s['community'] + ['about' => '', 'access' => 'unspecified', 'links' => [], 'contact_url' => null, 'gate_notice' => ''],
                array_flip(['id', 'name', 'subtitle', 'locality', 'region', 'postal_code', 'country', 'gate_notice', 'contact_url', 'about', 'access', 'links']))
                + ['reminders' => ($s['community']['reminders'] ?? []) ?: CommunitySchema::DEFAULT_REMINDERS],
            'event' => $s['event'] + ['timezone' => $s['community']['timezone']],
            'branding' => [
                'logo_url' => $this->branding->url($s['branding']['logo']),
            ] + array_intersect_key($s['branding'], array_flip(['brand_emoji', 'primary_color', 'accent_color', 'headline', 'headline_accent', 'intro', 'show_locality'])),
            'map' => [
                'center' => $c->mapCenter(),
                'zoom' => $s['map']['zoom'],
                'tiles' => $s['map']['tiles'],
                'bbox' => $c->boundary?->bbox(),
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
            'routing' => ['groups' => $s['routing']['groups'], 'crossings_verified' => $s['routing']['crossings_verified'],
                'provider' => $s['routing']['provider_enabled'] && $s['routing']['crossings_verified'] && $this->routing->available()],
            'stats' => [
                'homes' => \count($homes),
                'candy' => \count(array_filter($homes, static fn (array $h) => $h['candy'])),
                'low_sensory' => $lowSensory,
                'zones' => \count(array_filter($zones, static fn (array $z) => $z['residential'] && 'active' === $z['status'])),
            ],
            'demo' => $this->config->demoMode,
            'version' => $this->appVersion,
        ];
    }
}
