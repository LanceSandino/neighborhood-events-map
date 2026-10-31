<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventTypeRegistry;
use App\Geo\GeoJsonParser;
use App\Geo\GeometryException;
use App\Geo\PolygonSet;
use App\Geo\ZoneResolver;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Community/CommunityRepository
 * @description Persists the single community's settings and versioned boundary/zone geometry
 *     (PostgreSQL JSONB), tracks boundary approval, and imports/exports the canonical
 *     GeoJSON FeatureCollection (features tagged kind=boundary|zone). Every geometry change
 *     creates a new version, resets approval and recomputes address boundary membership.
 *
 * @input DBAL connection, clock, parser, schema
 * @output CommunityConfig snapshots, export documents, change summaries
 * @dependencies Community/CommunitySchema, Community/CommunityConfig, Geo/*, Event/EventTypeRegistry (default type key)
 */
final class CommunityRepository
{
    private ?CommunityConfig $cached = null;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly GeoJsonParser $parser,
        private readonly CommunitySchema $schema,
    ) {
    }

    public function current(): CommunityConfig
    {
        return $this->cached ??= $this->load();
    }

    public function reset(): void
    {
        $this->cached = null;
    }

    public function isConfigured(): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM community_settings WHERE id = 1');
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return array<string,mixed> normalized settings
     */
    public function saveSettings(array $raw, ?int $actorId): array
    {
        $settings = $this->schema->normalize($raw);
        $now = $this->now();
        $this->db->executeStatement(
            'INSERT INTO community_settings (id, deployment_id, settings, created_at, updated_at, updated_by)
             VALUES (1, :dep, :s, :now, :now, :actor)
             ON CONFLICT (id) DO UPDATE SET settings = EXCLUDED.settings, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by',
            ['dep' => $settings['community']['id'], 's' => json_encode($settings, \JSON_THROW_ON_ERROR), 'now' => $now, 'actor' => $actorId],
        );
        $this->reset();

        return $settings;
    }

    /** @return array<string,mixed>|null */
    public function settings(): ?array
    {
        $json = $this->db->fetchOne('SELECT settings FROM community_settings WHERE id = 1');

        return false === $json ? null : json_decode((string) $json, true, 64, \JSON_THROW_ON_ERROR);
    }

    /**
     * Validates and stores a canonical FeatureCollection. Features whose properties carry
     * kind=zone or a zone_key become zones (merged per key); all others form the boundary.
     *
     * @param array<string,mixed>|string $document
     *
     * @return array{version:int, boundary_features:int, zones:list<string>, warnings:list<string>, outside_claims:int}
     */
    public function replaceGeometry(array|string $document, ?int $actorId, bool $approve = false, bool $confirmOutsideClaims = false): array
    {
        $current = $this->current();
        $features = $this->parser->parse($document, $current->settings['map']['center'] ?? $current->boundary?->center());

        $boundaryFeatures = [];
        $zoneFeatures = [];
        foreach ($features as $f) {
            $props = $f['properties'];
            $zoneKey = isset($props['zone_key']) && \is_string($props['zone_key']) && '' !== $props['zone_key'] ? $props['zone_key'] : null;
            if ('zone' === ($props['kind'] ?? null) || null !== $zoneKey) {
                if (null === $zoneKey || !preg_match('~^[a-z0-9][a-z0-9-]{0,47}$~', $zoneKey)) {
                    throw new GeometryException(['Every zone feature needs a zone_key of lowercase letters, digits or dashes.']);
                }
                $zoneFeatures[$zoneKey][] = $f;
            } else {
                $boundaryFeatures[] = $f;
            }
        }
        if ([] === $boundaryFeatures) {
            throw new GeometryException(['The file has no neighborhood boundary feature (features without a zone_key).']);
        }
        $knownZones = array_column($current->zones(), null, 'key');
        foreach (array_keys($zoneFeatures) as $key) {
            if (!isset($knownZones[$key])) {
                throw new GeometryException([sprintf('Zone "%s" is not configured. Add it under Zones before importing its geometry.', $key)]);
            }
        }

        $boundaryShape = new PolygonSet([]);
        foreach ($boundaryFeatures as $f) {
            $boundaryShape = $boundaryShape->merge($f['shape']);
        }
        $outside = $this->claimedOutside($boundaryShape);
        if ($outside > 0 && !$confirmOutsideClaims) {
            throw new BoundaryChangeException($outside);
        }

        $zoneShapes = [];
        foreach ($zoneFeatures as $key => $list) {
            $set = new PolygonSet([]);
            foreach ($list as $f) {
                $set = $set->merge($f['shape']);
            }
            $zoneShapes[$key] = $set;
        }
        $warnings = [];
        foreach ((new ZoneResolver($zoneShapes))->overlaps() as $o) {
            $warnings[] = sprintf('Zones "%s" and "%s" overlap by about %s m²; addresses there need an organizer-assigned zone.', $o['a'], $o['b'], number_format($o['approx_m2']));
        }

        $now = $this->now();
        $version = 1 + (int) $this->db->fetchOne('SELECT COALESCE(MAX(version), 0) FROM community_geometries');
        $this->db->transactional(function (Connection $db) use ($boundaryFeatures, $zoneFeatures, $knownZones, $version, $now, $actorId, $approve): void {
            $db->executeStatement('UPDATE community_geometries SET is_active = FALSE WHERE is_active');
            $db->insert('community_geometries', [
                'kind' => 'boundary',
                'zone_key' => null,
                'label' => 'Neighborhood boundary',
                'geometry' => json_encode(self::featureCollection($boundaryFeatures), \JSON_THROW_ON_ERROR),
                'properties' => '{}',
                'version' => $version,
                'is_active' => true,
                'approved_at' => $approve ? $now : null,
                'approved_by' => $approve ? $actorId : null,
                'updated_by' => $actorId,
                'created_at' => $now,
            ], ['is_active' => 'boolean']);
            foreach ($zoneFeatures as $key => $list) {
                $db->insert('community_geometries', [
                    'kind' => 'zone',
                    'zone_key' => $key,
                    'label' => $knownZones[$key]['label'],
                    'geometry' => json_encode(self::featureCollection($list), \JSON_THROW_ON_ERROR),
                    'properties' => '{}',
                    'version' => $version,
                    'is_active' => true,
                    'updated_by' => $actorId,
                    'created_at' => $now,
                ], ['is_active' => 'boolean']);
            }
        });
        $this->reset();
        $this->recomputeAddressMembership();

        return ['version' => $version, 'boundary_features' => \count($boundaryFeatures), 'zones' => array_keys($zoneFeatures), 'warnings' => $warnings, 'outside_claims' => $outside];
    }

    public function approveBoundary(?int $actorId): bool
    {
        $n = $this->db->executeStatement(
            "UPDATE community_geometries SET approved_at = :now, approved_by = :actor WHERE is_active AND kind = 'boundary'",
            ['now' => $this->now(), 'actor' => $actorId],
        );
        $this->reset();

        return $n > 0;
    }

    public function revokeBoundaryApproval(): void
    {
        $this->db->executeStatement("UPDATE community_geometries SET approved_at = NULL, approved_by = NULL WHERE is_active AND kind = 'boundary'");
        $this->reset();
    }

    /** Canonical export: boundary features then zone features, each tagged with kind (and zone_key). */
    public function exportGeometry(): array
    {
        $features = [];
        foreach ($this->db->fetchAllAssociative('SELECT kind, zone_key, geometry FROM community_geometries WHERE is_active ORDER BY kind, zone_key') as $row) {
            $fc = json_decode((string) $row['geometry'], true, 512, \JSON_THROW_ON_ERROR);
            foreach ($fc['features'] as $f) {
                $f['properties'] = array_merge($f['properties'] ?? [], 'zone' === $row['kind']
                    ? ['kind' => 'zone', 'zone_key' => $row['zone_key']]
                    : ['kind' => 'boundary']);
                ksort($f['properties']);
                $features[] = $f;
            }
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /** @return list<array<string,mixed>> */
    public function geometryVersions(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT version, MIN(created_at) AS created_at, BOOL_OR(is_active) AS active,
                    MAX(approved_at) FILTER (WHERE kind = 'boundary') AS approved_at,
                    COUNT(*) FILTER (WHERE kind = 'zone') AS zones
             FROM community_geometries GROUP BY version ORDER BY version DESC LIMIT 20",
        );
    }

    public function claimedOutside(PolygonSet $boundary): int
    {
        $n = 0;
        foreach ($this->db->fetchAllAssociative('SELECT a.latitude, a.longitude FROM claims c JOIN eligible_addresses a ON a.id = c.address_id') as $r) {
            if (!$boundary->contains((float) $r['latitude'], (float) $r['longitude'])) {
                ++$n;
            }
        }

        return $n;
    }

    /** Flags every address inside/outside the active boundary; public listings outside are suppressed. */
    public function recomputeAddressMembership(): int
    {
        $boundary = $this->current()->boundary;
        $changed = 0;
        foreach ($this->db->fetchAllAssociative('SELECT id, latitude, longitude, inside_boundary FROM eligible_addresses') as $r) {
            $inside = null !== $boundary && $boundary->contains((float) $r['latitude'], (float) $r['longitude']);
            if ($inside !== (bool) $r['inside_boundary']) {
                $changed += $this->db->executeStatement('UPDATE eligible_addresses SET inside_boundary = :i WHERE id = :id', ['i' => $inside, 'id' => $r['id']], ['i' => 'boolean']);
            }
        }

        return $changed;
    }

    private function load(): CommunityConfig
    {
        $settings = $this->settings();
        if (null === $settings) {
            return CommunityConfig::unconfigured();
        }
        $boundary = null;
        $approved = false;
        $version = null;
        $zones = [];
        foreach ($this->db->fetchAllAssociative('SELECT kind, zone_key, geometry, version, approved_at FROM community_geometries WHERE is_active') as $row) {
            $shape = self::trustedShape(json_decode((string) $row['geometry'], true, 512, \JSON_THROW_ON_ERROR));
            if ('boundary' === $row['kind']) {
                $boundary = $shape;
                $approved = null !== $row['approved_at'];
                $version = (int) $row['version'];
            } else {
                $zones[(string) $row['zone_key']] = $shape;
            }
        }

        // Settings saved before event.name or event.type existed keep the event they were made for (Halloween).
        $settings['event'] = (array) ($settings['event'] ?? []) + ['name' => CommunitySchema::DEFAULT_EVENT_NAME, 'type' => EventTypeRegistry::DEFAULT];

        return new CommunityConfig($settings, $boundary, $approved, $version, $zones);
    }

    /** Stored geometry was validated on write; convert without re-running O(n²) checks. */
    private static function trustedShape(array $fc): PolygonSet
    {
        $polygons = [];
        foreach ($fc['features'] ?? [] as $f) {
            $g = $f['geometry'] ?? [];
            $parts = 'MultiPolygon' === ($g['type'] ?? null) ? $g['coordinates'] : [$g['coordinates'] ?? []];
            foreach ($parts as $rings) {
                $polygons[] = array_map(
                    static fn (array $ring) => array_map(static fn (array $p) => [(float) $p[0], (float) $p[1]], $ring),
                    $rings,
                );
            }
        }

        return new PolygonSet($polygons);
    }

    /**
     * @param list<array{properties: array<string,mixed>, shape: PolygonSet}> $features
     */
    private static function featureCollection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => array_map(static function (array $f): array {
            $props = $f['properties'];
            unset($props['kind']);
            ksort($props);

            return ['type' => 'Feature', 'properties' => $props, 'geometry' => $f['shape']->toGeometry()];
        }, $features)];
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
