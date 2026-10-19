<?php

declare(strict_types=1);

namespace App\Community;

use App\Address\AddressNormalizer;

/**
 * @module Community/CommunitySchema
 * @description Validates and normalizes a community configuration array (from community.yaml or the
 *     admin settings form) into the canonical settings document stored in community_settings.
 *     Unknown keys are dropped; every value is type-checked; nothing neighborhood-specific is assumed.
 *
 * @input array<string,mixed> raw configuration
 * @output array<string,mixed> normalized settings
 * @dependencies Address/AddressNormalizer, Community/ConfigException
 */
final class CommunitySchema
{
    /** How visitors may reach the homes on Halloween night (shown on the map and the About page). */
    public const ACCESS = ['unspecified', 'public', 'gated_open', 'gated_guests', 'private'];
    /** active: shown and listable; coming_soon: drawn as "coming soon", not listable; hidden: not shown at all. */
    public const ZONE_STATUS = ['active', 'coming_soon', 'hidden'];

    public const DEFAULT_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    public const DEFAULT_TILE_ATTRIBUTION = '© OpenStreetMap contributors';

    public function __construct(private readonly AddressNormalizer $normalizer = new AddressNormalizer())
    {
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return array<string,mixed>
     */
    public function normalize(array $raw): array
    {
        $e = [];
        $c = self::arr($raw['community'] ?? []);
        $ev = self::arr($raw['event'] ?? []);
        $b = self::arr($raw['branding'] ?? []);
        $m = self::arr($raw['map'] ?? []);
        $f = self::arr($raw['features'] ?? []);
        $r = self::arr($raw['registration'] ?? []);
        $ro = self::arr($raw['routing'] ?? []);

        $id = (string) ($c['id'] ?? 'neighborhood');
        if (!preg_match('~^[a-z0-9][a-z0-9-]{1,47}$~', $id)) {
            $e[] = 'community.id must be 2-48 lowercase letters, digits or dashes.';
        }
        $name = self::text($c['name'] ?? '', 60);
        if ('' === $name) {
            $e[] = 'community.name is required.';
        }
        $tz = (string) ($c['timezone'] ?? 'America/New_York');
        if (!\in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $e[] = sprintf('community.timezone "%s" is not a valid IANA time zone.', $tz);
        }

        $date = (string) ($ev['date'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $d || $d->format('Y-m-d') !== $date) {
            $e[] = 'event.date must be YYYY-MM-DD.';
        }
        $start = self::time($ev['starts_at'] ?? '17:00');
        $end = self::time($ev['ends_at'] ?? '21:00');
        if (null === $start || null === $end || $end <= $start) {
            $e[] = 'event.starts_at/ends_at must be HH:MM with the end after the start.';
        }

        $access = (string) ($c['access'] ?? 'unspecified');
        if (!\in_array($access, self::ACCESS, true)) {
            $e[] = 'community.access must be one of: '.implode(', ', self::ACCESS).'.';
        }
        $links = [];
        foreach (self::arr($c['links'] ?? []) as $i => $l) {
            $l = self::arr($l);
            $label = self::text($l['label'] ?? '', 40);
            $url = self::url($l['url'] ?? null);
            if ('' === $label && '' === trim((string) ($l['url'] ?? ''))) {
                continue; // an empty row from the console
            }
            if ('' === $label || null === $url) {
                $e[] = sprintf('community.links[%d] needs a label and an https:// or mailto: address.', $i);
                continue;
            }
            $links[] = ['label' => $label, 'url' => $url];
        }
        if (\count($links) > 8) {
            $e[] = 'community.links allows at most 8 links.';
        }

        $primary = self::color($b['primary_color'] ?? '#F07936', 'branding.primary_color', $e);
        $accent = self::color($b['accent_color'] ?? '#524077', 'branding.accent_color', $e);

        $center = null;
        if (isset($m['center'])) {
            $cc = self::arr($m['center']);
            $lat = $cc['lat'] ?? $cc[0] ?? null;
            $lon = $cc['lon'] ?? $cc[1] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
                $e[] = 'map.center must be {lat, lon}.';
            } else {
                $center = ['lat' => (float) $lat, 'lon' => (float) $lon];
            }
        }
        $tiles = self::arr($m['tiles'] ?? []);
        $tileUrl = (string) ($tiles['url'] ?? self::DEFAULT_TILE_URL);
        if (!preg_match('~^https://(\{s\}\.)?[a-z0-9.-]+(:\d+)?/\S*\{z\}\S*\{x\}\S*\{y\}~i', $tileUrl)) {
            $e[] = 'map.tiles.url must be an https URL template containing {z}, {x} and {y}.';
        }
        $attribution = self::text($tiles['attribution'] ?? self::DEFAULT_TILE_ATTRIBUTION, 200);
        if ('' === $attribution) {
            $e[] = 'map.tiles.attribution is required by tile providers.';
        }

        $zones = [];
        foreach (array_values(self::arr($raw['zones'] ?? [])) as $i => $z) {
            $z = self::arr($z);
            $key = (string) ($z['key'] ?? '');
            if (!preg_match('~^[a-z0-9][a-z0-9-]{0,47}$~', $key)) {
                $e[] = sprintf('zones[%d].key must be lowercase letters, digits or dashes.', $i);
                continue;
            }
            if (isset($zones[$key])) {
                $e[] = sprintf('zones key "%s" is duplicated.', $key);
                continue;
            }
            $label = self::text($z['label'] ?? $key, 40);
            $zones[$key] = [
                'key' => $key,
                'label' => '' !== $label ? $label : $key,
                'claimable' => (bool) ($z['claimable'] ?? true),
                'residential' => (bool) ($z['residential'] ?? ($z['claimable'] ?? true)),
                'color' => isset($z['color']) ? self::color($z['color'], "zones[$i].color", $e) : null,
                'description' => self::text($z['description'] ?? '', 160),
                'status' => (string) ($z['status'] ?? 'active'),
            ];
            if (!\in_array($zones[$key]['status'], self::ZONE_STATUS, true)) {
                $e[] = sprintf('zones[%d].status must be one of: %s.', $i, implode(', ', self::ZONE_STATUS));
            }
        }

        $mode = (string) ($r['address_mode'] ?? 'registry');
        if (!\in_array($mode, ['registry', 'self_entry', 'both'], true)) {
            $e[] = 'registration.address_mode must be registry, self_entry or both.';
        }
        $streets = [];
        foreach (self::arr($r['street_allowlist'] ?? []) as $s) {
            $n = $this->normalizer->normalizeStreet((string) $s);
            if ('' !== $n) {
                $streets[$n] = $n;
            }
        }
        if ('registry' !== $mode && [] === $streets) {
            $e[] = 'registration.street_allowlist is required when self-entry is enabled.';
        }
        $linkMinutes = (int) ($r['email_magic_link_minutes'] ?? 15);
        if ($linkMinutes < 5 || $linkMinutes > 60) {
            $e[] = 'registration.email_magic_link_minutes must be between 5 and 60.';
        }
        $maxHomes = (int) ($r['max_homes_per_user'] ?? 2);
        if ($maxHomes < 1 || $maxHomes > 50) {
            $e[] = 'registration.max_homes_per_user must be between 1 and 50.';
        }
        $idleMinutes = (int) ($r['session_idle_minutes'] ?? 30);
        if ($idleMinutes < 5 || $idleMinutes > 240) {
            $e[] = 'registration.session_idle_minutes must be between 5 and 240.';
        }

        $groups = [];
        foreach (self::arr($ro['groups'] ?? []) as $gi => $g) {
            $keys = array_values(array_unique(array_map('strval', self::arr($g))));
            foreach ($keys as $k) {
                if (!isset($zones[$k])) {
                    $e[] = sprintf('routing.groups[%d] references unknown zone "%s".', $gi, $k);
                }
            }
            if ([] !== $keys) {
                $groups[] = $keys;
            }
        }

        if ([] !== $e) {
            throw new ConfigException($e);
        }

        return [
            'schema_version' => 1,
            'community' => [
                'id' => $id,
                'name' => $name,
                'subtitle' => self::text($c['subtitle'] ?? 'Your neighborhood. Your Halloween.', 80),
                'locality' => self::text($c['locality'] ?? '', 60),
                'region' => self::text($c['region'] ?? '', 40),
                'postal_code' => self::text($c['postal_code'] ?? '', 12),
                'country' => self::text($c['country'] ?? 'US', 2),
                'timezone' => $tz,
                'gate_notice' => self::text($c['gate_notice'] ?? '', 300),
                'contact_url' => self::url($c['contact_url'] ?? null),
                'about' => self::paragraphs($c['about'] ?? '', 1500),
                'access' => $access,
                'links' => $links,
            ],
            'event' => ['date' => $date, 'starts_at' => $start, 'ends_at' => $end],
            'branding' => [
                'logo' => self::text($b['logo'] ?? '', 200) ?: null,
                'brand_emoji' => self::text($b['brand_emoji'] ?? '🎃', 8) ?: '🎃',
                'primary_color' => $primary,
                'accent_color' => $accent,
                'headline' => self::text($b['headline'] ?? 'Little ghosts.', 40),
                'headline_accent' => self::text($b['headline_accent'] ?? 'Big adventures.', 40),
                'intro' => self::text($b['intro'] ?? 'Find the treats, skip the scares, and plan a route that feels right for you and your crew—one sweet stop at a time.', 280),
            ],
            'map' => [
                'center' => $center,
                'zoom' => max(10, min(19, (int) ($m['zoom'] ?? 16))),
                'tiles' => ['url' => $tileUrl, 'attribution' => $attribution, 'max_zoom' => max(10, min(20, (int) ($tiles['max_zoom'] ?? 19)))],
                'crossing_notes' => array_values(array_filter(array_map(fn ($n) => self::text($n, 200), self::arr($m['crossing_notes'] ?? [])))),
            ],
            'zones' => array_values($zones),
            'features' => [
                'candy' => (bool) ($f['candy'] ?? true),
                'nonfood_treats' => (bool) ($f['nonfood_treats'] ?? true),
                'adult_refreshments' => (bool) ($f['adult_refreshments'] ?? true),
                'sensory_filters' => (bool) ($f['sensory_filters'] ?? true),
                'optional_zones' => (bool) ($f['optional_zones'] ?? ([] !== $zones)),
                'walking_routes' => (bool) ($f['walking_routes'] ?? true),
            ],
            'registration' => [
                'enabled' => (bool) ($r['enabled'] ?? true),
                'address_mode' => $mode,
                'street_allowlist' => array_values($streets),
                'email_magic_link_minutes' => $linkMinutes,
                'session_idle_minutes' => $idleMinutes,
                'max_homes_per_user' => $maxHomes,
                // Self-entered addresses wait for an organizer's approval before they appear on the map.
                'review_new_addresses' => (bool) ($r['review_new_addresses'] ?? false),
            ],
            'routing' => [
                'groups' => $groups,
                'crossings_verified' => (bool) ($ro['crossings_verified'] ?? false),
                'provider_enabled' => (bool) ($ro['provider_enabled'] ?? false),
            ],
        ];
    }

    /** @return array<mixed> */
    private static function arr(mixed $v): array
    {
        return \is_array($v) ? $v : [];
    }

    /** Free text that keeps paragraph breaks (other control characters become spaces). */
    private static function paragraphs(mixed $v, int $max): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", (string) (\is_scalar($v) ? $v : ''));
        $s = preg_replace(['~[\x00-\x09\x0B-\x1F\x7F]~u', '~\n{3,}~'], [' ', "\n\n"], $s) ?? '';

        return mb_substr(trim($s), 0, $max);
    }

    private static function text(mixed $v, int $max): string
    {
        $s = trim(preg_replace('~[\x00-\x1F\x7F]~u', ' ', (string) (\is_scalar($v) ? $v : '')) ?? '');

        return mb_substr($s, 0, $max);
    }

    private static function time(mixed $v): ?string
    {
        $s = \is_scalar($v) ? (string) $v : '';
        if (\is_int($v)) { // YAML may parse 17:00 as sexagesimal integer (1020)
            $s = sprintf('%02d:%02d', intdiv($v, 60), $v % 60);
        }

        return preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $s) ? $s : null;
    }

    /** @param list<string> $e */
    private static function color(mixed $v, string $field, array &$e): string
    {
        $s = strtoupper(\is_string($v) ? trim($v) : '');
        if (!preg_match('~^#[0-9A-F]{6}$~', $s)) {
            $e[] = $field.' must be a #RRGGBB colour.';

            return '#000000';
        }

        return $s;
    }

    private static function url(mixed $v): ?string
    {
        $s = \is_string($v) ? trim($v) : '';

        return preg_match('~^(https://|mailto:)\S{3,200}$~i', $s) ? $s : null;
    }
}
