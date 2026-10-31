<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventOccurrenceRepository;
use App\Event\EventTypeRegistry;
use App\Event\OccurrenceInput;
use App\Shared\ValidationException;
use Doctrine\DBAL\Connection;
use Symfony\Component\Yaml\Yaml;

/**
 * @module Community/CommunityBootstrapper
 * @description Imports a community.yaml (plus its referenced boundary/zone GeoJSON and logo) into
 *     the database. Idempotent: does nothing when the community is already configured unless
 *     forced. Boundaries are imported UNAPPROVED unless explicitly approved by the operator.
 *     `event.settings` (the shared rules and route modes, a type's own settings such as a sale's days) is
 *     checked like Organizer → Events checks it before anything is imported, and applied only to the install's
 *     first event, the one this import creates; an existing event's settings change only under Organizer → Events.
 *
 * @input path to community.yaml
 * @output summary array
 * @dependencies Community/CommunityRepository, Community/BrandingStore, Community/EventBlockSchema, Event/OccurrenceInput,
 *     Event/EventOccurrenceRepository, Event/EventTypeRegistry, DBAL, symfony/yaml
 */
final class CommunityBootstrapper
{
    public function __construct(
        private readonly CommunityRepository $repo,
        private readonly BrandingStore $branding,
        private readonly EventTypeRegistry $types,
        private readonly EventOccurrenceRepository $occurrences,
        private readonly Connection $db,
    ) {
    }

    /**
     * @return array{status:string, settings?:array<string,mixed>, geometry?:array<string,mixed>, logo?:?string}
     */
    public function bootstrap(string $yamlPath, bool $force = false, bool $approveBoundary = false): array
    {
        if (!$force && $this->repo->isConfigured()) {
            return ['status' => 'already-configured'];
        }
        if (!is_file($yamlPath) || !is_readable($yamlPath)) {
            throw new ConfigException([sprintf('Community file "%s" not found.', $yamlPath)]);
        }
        $dir = \dirname((string) realpath($yamlPath));
        $raw = Yaml::parseFile($yamlPath);
        if (!\is_array($raw)) {
            throw new ConfigException(['Community file must be a YAML mapping.']);
        }

        $firstEvent = !$this->occurrences->any();
        $eventSettings = $this->eventSettings($raw, $firstEvent);
        if (\is_array($raw['event'] ?? null)) {
            unset($raw['event']['settings']);
        }

        $logo = null;
        if (\is_string($raw['branding']['logo'] ?? null) && '' !== $raw['branding']['logo']) {
            $logoPath = $this->resolve($dir, $raw['branding']['logo']);
            $logo = null !== $logoPath ? $this->branding->storeLogo($logoPath) : null;
            $raw['branding']['logo'] = $logo;
        }
        $settings = $this->repo->saveSettings($raw, null);
        $first = null !== $eventSettings ? $this->occurrences->active() : null;
        if (null !== $first) {
            $this->db->transactional(fn (Connection $db) => $this->occurrences->updateFields($db, $first->id, ['name' => $first->name, 'starts_on' => $first->startsOn,
                'ends_on' => $first->endsOn, 'default_start' => $first->defaultStart, 'default_end' => $first->defaultEnd, 'settings' => $eventSettings]));
            $this->repo->reset();
        }

        $geometry = null;
        $map = \is_array($raw['map'] ?? null) ? $raw['map'] : [];
        $boundaryFile = \is_string($map['boundary_file'] ?? null) ? $this->resolve($dir, $map['boundary_file']) : null;
        if (null !== $boundaryFile) {
            $features = $this->features($boundaryFile, false);
            $zoneFiles = $map['zones_file'] ?? $map['zones_files'] ?? [];
            foreach (\is_array($zoneFiles) ? $zoneFiles : [$zoneFiles] as $zf) {
                $path = \is_string($zf) ? $this->resolve($dir, $zf) : null;
                if (null !== $path) {
                    array_push($features, ...$this->features($path, true));
                }
            }
            $geometry = $this->repo->replaceGeometry(['type' => 'FeatureCollection', 'features' => $features], null, $approveBoundary, true);
        }

        return ['status' => 'imported', 'settings' => $settings, 'geometry' => $geometry, 'logo' => $logo];
    }

    /**
     * The first event's settings from the YAML (event.settings), checked against its type and dates before anything is
     * imported; null when this import does not create the install's first event or the event block is not valid (the
     * settings schema then reports it).
     *
     * @param array<mixed> $raw
     *
     * @return array<string,mixed>|null
     *
     * @throws ConfigException for settings the event refuses ("event.settings.<key>: …")
     */
    private function eventSettings(array $raw, bool $firstEvent): ?array
    {
        $ev = \is_array($raw['event'] ?? null) ? $raw['event'] : [];
        $key = \is_string($ev['type'] ?? null) && '' !== trim($ev['type']) ? $ev['type'] : EventTypeRegistry::DEFAULT;
        if (!$firstEvent || null !== $this->types->problem($key)) {
            return null;
        }
        $type = $this->types->get($key);
        $e = [];
        $block = EventBlockSchema::normalize($ev, $type, $e);
        if ([] !== $e) {
            return null;
        }
        [$first, $last] = EventBlockSchema::range($block);
        try {
            return OccurrenceInput::normalize(['starts_on' => $first, 'ends_on' => $last, 'default_start' => $block['starts_at'], 'default_end' => $block['ends_at'],
                'settings' => $ev['settings'] ?? []], $type)['settings'];
        } catch (ValidationException $x) {
            throw new ConfigException(array_values(array_map(static fn (string $field, string $msg) => sprintf('event.%s: %s', $field, $msg), array_keys($x->fields), $x->fields)));
        }
    }

    /** @return list<array<string,mixed>> */
    private function features(string $path, bool $zones): array
    {
        $doc = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        $list = match ($doc['type'] ?? null) {
            'FeatureCollection' => $doc['features'] ?? [],
            'Feature' => [$doc],
            default => [['type' => 'Feature', 'properties' => [], 'geometry' => $doc]],
        };
        $out = [];
        foreach ($list as $f) {
            $props = \is_array($f['properties'] ?? null) ? $f['properties'] : [];
            if ($zones && empty($props['zone_key'])) {
                throw new ConfigException([sprintf('Zone file %s has a feature without properties.zone_key.', basename($path))]);
            }
            // A boundary file may be the combined export format (features tagged kind=zone keep their key).
            if (!$zones && 'zone' !== ($props['kind'] ?? null)) {
                unset($props['zone_key']);
            }
            $out[] = ['type' => 'Feature', 'properties' => $props, 'geometry' => $f['geometry'] ?? null];
        }

        return $out;
    }

    private function resolve(string $dir, string $relative): ?string
    {
        foreach ([$relative, $dir.'/'.$relative] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
