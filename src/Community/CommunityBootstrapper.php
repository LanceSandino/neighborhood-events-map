<?php

declare(strict_types=1);

namespace App\Community;

use Symfony\Component\Yaml\Yaml;

/**
 * @module Community/CommunityBootstrapper
 * @description Imports a community.yaml (plus its referenced boundary/zone GeoJSON and logo) into
 *     the database. Idempotent: does nothing when the community is already configured unless
 *     forced. Boundaries are imported UNAPPROVED unless explicitly approved by the operator.
 *
 * @input path to community.yaml
 * @output summary array
 * @dependencies Community/CommunityRepository, Community/BrandingStore, symfony/yaml
 */
final class CommunityBootstrapper
{
    public function __construct(
        private readonly CommunityRepository $repo,
        private readonly BrandingStore $branding,
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

        $logo = null;
        if (\is_string($raw['branding']['logo'] ?? null) && '' !== $raw['branding']['logo']) {
            $logoPath = $this->resolve($dir, $raw['branding']['logo']);
            $logo = null !== $logoPath ? $this->branding->storeLogo($logoPath) : null;
            $raw['branding']['logo'] = $logo;
        }
        $settings = $this->repo->saveSettings($raw, null);

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
