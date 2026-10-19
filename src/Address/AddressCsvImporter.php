<?php

declare(strict_types=1);

namespace App\Address;

use App\Community\CommunityConfig;

/**
 * @module Address/AddressCsvImporter
 * @description Imports a reviewed address inventory CSV (header row required):
 *     address | house_number+street[+unit], latitude, longitude, [zone_key].
 *     Coordinates are mandatory (never geocoded or invented); rows outside the approved boundary,
 *     with unknown zones or with zone/pin mismatches are reported and skipped.
 *
 * @input CSV text, CommunityConfig
 * @output array{imported:int, updated:int, unchanged:int, skipped:list<array{line:int, reason:string}>}
 * @dependencies Address/AddressRegistry, Address/AddressNormalizer
 */
final class AddressCsvImporter
{
    public const MAX_ROWS = 5000;

    public function __construct(private readonly AddressRegistry $registry, private readonly AddressNormalizer $normalizer)
    {
    }

    /** @return array{imported:int, updated:int, unchanged:int, skipped:list<array{line:int, reason:string}>} */
    public function import(string $csv, CommunityConfig $community, bool $updateExisting = false, bool $dryRun = false): array
    {
        $report = ['imported' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => []];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $header = fgetcsv($fh, escape: '');
        if (!\is_array($header)) {
            throw new \InvalidArgumentException('The CSV is empty.');
        }
        $cols = array_map(static fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header);
        $idx = array_flip($cols);
        foreach (['latitude', 'longitude'] as $req) {
            if (!isset($idx[$req])) {
                throw new \InvalidArgumentException(sprintf('Missing required column "%s". Columns: address (or house_number, street, unit), latitude, longitude, zone_key.', $req));
            }
        }
        if (!isset($idx['address']) && !isset($idx['house_number'], $idx['street'])) {
            throw new \InvalidArgumentException('Provide an "address" column or "house_number" and "street" columns.');
        }
        $line = 1;
        $seen = []; // normalized addresses already handled in this file (keeps dry runs accurate)
        while (false !== ($row = fgetcsv($fh, escape: ''))) {
            ++$line;
            if ($line > self::MAX_ROWS + 1) {
                $report['skipped'][] = ['line' => $line, 'reason' => 'Row limit reached ('.self::MAX_ROWS.').'];
                break;
            }
            if ([null] === $row || [] === array_filter($row, static fn ($v) => '' !== trim((string) $v))) {
                continue;
            }
            $get = static fn (string $c) => isset($idx[$c]) ? trim((string) ($row[$idx[$c]] ?? '')) : '';
            try {
                $parts = isset($idx['address']) && '' !== $get('address')
                    ? $this->normalizer->parse($get('address'))
                    : $this->normalizer->fromParts($get('house_number'), $get('street'), '' !== $get('unit') ? $get('unit') : null);
            } catch (InvalidAddressException $e) {
                $report['skipped'][] = ['line' => $line, 'reason' => $e->getMessage()];
                continue;
            }
            $lat = $get('latitude');
            $lon = $get('longitude');
            if (!is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180) {
                $report['skipped'][] = ['line' => $line, 'reason' => 'Latitude/longitude missing or invalid (coordinates are required; nothing is geocoded).'];
                continue;
            }
            $lat = (float) $lat;
            $lon = (float) $lon;
            if (!$community->insideBoundary($lat, $lon)) {
                $report['skipped'][] = ['line' => $line, 'reason' => 'Point is outside the neighborhood boundary (check latitude/longitude order).'];
                continue;
            }
            $zone = '' !== $get('zone_key') ? strtolower($get('zone_key')) : null;
            $hits = $community->zonesAt($lat, $lon);
            if (null !== $zone && null === $community->zone($zone)) {
                $report['skipped'][] = ['line' => $line, 'reason' => sprintf('Unknown zone "%s".', $zone)];
                continue;
            }
            if (null !== $zone && [] !== $hits && !\in_array($zone, $hits, true)) {
                $report['skipped'][] = ['line' => $line, 'reason' => sprintf('Zone "%s" does not contain this point (map says %s).', $zone, implode(', ', $hits))];
                continue;
            }
            if (null === $zone && 1 === \count($hits)) {
                $zone = $hits[0];
            }
            if (isset($seen[$parts['normalized']])) {
                $report['skipped'][] = ['line' => $line, 'reason' => sprintf('Duplicate of line %d in this file.', $seen[$parts['normalized']])];
                continue;
            }
            $seen[$parts['normalized']] = $line;
            $existing = $this->registry->findByNormalized($parts['normalized']);
            if (null !== $existing) {
                $same = abs((float) $existing['latitude'] - $lat) < 1e-9 && abs((float) $existing['longitude'] - $lon) < 1e-9 && $existing['zone_key'] === $zone;
                if ($same || !$updateExisting) {
                    ++$report['unchanged'];
                    continue;
                }
                if (!$dryRun) {
                    $this->registry->update((int) $existing['id'], ['latitude' => $lat, 'longitude' => $lon, 'zone_key' => $zone], true);
                }
                ++$report['updated'];
                continue;
            }
            if (!$dryRun) {
                $this->registry->insert($parts, $zone, $lat, $lon, 'import', true, true);
            }
            ++$report['imported'];
        }
        fclose($fh);

        return $report;
    }
}
