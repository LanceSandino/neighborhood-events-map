<?php

declare(strict_types=1);

namespace App\Address;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Address/AddressRegistry
 * @description Private inventory of eligible addresses (never exported publicly). Supports narrow
 *     autocomplete (house number + street prefix), proximity checks, imports and admin edits.
 *
 * @dependencies DBAL, Geo/Haversine
 */
final class AddressRegistry
{
    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM eligible_addresses WHERE id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByNormalized(string $normalized): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM eligible_addresses WHERE normalized_address = ?', [$normalized]);

        return false === $row ? null : $row;
    }

    /**
     * Narrow lookup: the query must start with a house number and at least two street letters,
     * so the endpoint cannot be used to enumerate the inventory.
     *
     * @return list<array{id:int, address:string, zone_key:?string, claimed:bool}>
     */
    public function lookup(string $normalizedPrefix, int $limit = 8): array
    {
        if (!preg_match('~^\d{1,6}[A-Z]?\s+[A-Z0-9]{2,}~', $normalizedPrefix)) {
            return [];
        }
        $like = addcslashes($normalizedPrefix, '%_\\').'%';
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id, a.display_address, a.zone_key, (c.address_id IS NOT NULL) AS claimed
             FROM eligible_addresses a LEFT JOIN claims c ON c.address_id = a.id
             WHERE a.enabled AND a.inside_boundary AND a.normalized_address LIKE :q
             ORDER BY a.normalized_address LIMIT '.max(1, min(10, $limit)),
            ['q' => $like],
        );

        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'address' => (string) $r['display_address'],
            'zone_key' => $r['zone_key'], 'claimed' => (bool) $r['claimed']], $rows);
    }

    public function isClaimed(int $addressId): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM claims WHERE address_id = ?', [$addressId]);
    }

    /** Nearest other address within $meters (bbox prefilter, haversine refine). */
    public function nearbyOther(float $lat, float $lon, float $meters, string $excludeNormalized): ?array
    {
        $dLat = $meters / 111_000;
        $dLon = $meters / max(1e-6, 111_000 * cos(deg2rad($lat)));
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, display_address, normalized_address, latitude, longitude FROM eligible_addresses
             WHERE latitude BETWEEN :a AND :b AND longitude BETWEEN :c AND :d AND normalized_address <> :n',
            ['a' => $lat - $dLat, 'b' => $lat + $dLat, 'c' => $lon - $dLon, 'd' => $lon + $dLon, 'n' => $excludeNormalized],
        );
        foreach ($rows as $r) {
            if (\App\Geo\Haversine::meters($lat, $lon, (float) $r['latitude'], (float) $r['longitude']) <= $meters) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Inserts (or returns the existing id of) an address. Concurrency-safe on normalized_address.
     *
     * @param array{normalized:string, display:string, house_number:string, street:string, unit:?string} $parts
     */
    public function insert(array $parts, ?string $zoneKey, float $lat, float $lon, string $source, bool $insideBoundary, bool $reviewed): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $id = $this->db->fetchOne(
            'INSERT INTO eligible_addresses (display_address, normalized_address, house_number, street, unit, zone_key, latitude, longitude,
                 source, inside_boundary, reviewed_at, created_at, updated_at)
             VALUES (:display, :norm, :house, :street, :unit, :zone, :lat, :lon, :source, :inside, :reviewed, :now, :now)
             ON CONFLICT (normalized_address) DO NOTHING RETURNING id',
            ['display' => $parts['display'], 'norm' => $parts['normalized'], 'house' => $parts['house_number'], 'street' => $parts['street'],
                'unit' => $parts['unit'], 'zone' => $zoneKey, 'lat' => $lat, 'lon' => $lon, 'source' => $source,
                'inside' => $insideBoundary, 'reviewed' => $reviewed ? $now : null, 'now' => $now],
            ['inside' => 'boolean'],
        );
        if (false === $id) {
            $id = $this->db->fetchOne('SELECT id FROM eligible_addresses WHERE normalized_address = ?', [$parts['normalized']]);
        }

        return (int) $id;
    }

    /**
     * Moves an unreviewed self-entered address to the pin of the resident who has just claimed it (an earlier
     * claimant's pin is never inherited). Reviewed and imported addresses are never changed here.
     */
    public function repinSelfEntry(int $id, ?string $zoneKey, float $lat, float $lon): void
    {
        $this->db->executeStatement(
            "UPDATE eligible_addresses SET latitude = :lat, longitude = :lon, zone_key = :zone, inside_boundary = TRUE, updated_at = :now
             WHERE id = :id AND source = 'self_entry' AND reviewed_at IS NULL",
            ['lat' => $lat, 'lon' => $lon, 'zone' => $zoneKey, 'id' => $id, 'now' => $this->clock->now()->format('Y-m-d H:i:s.uP')],
        );
    }

    /** @param array<string,mixed> $changes subset of display_address, zone_key, latitude, longitude, enabled */
    public function update(int $id, array $changes, bool $insideBoundary): void
    {
        $allowed = array_intersect_key($changes, array_flip(['display_address', 'zone_key', 'latitude', 'longitude', 'enabled']));
        $allowed['inside_boundary'] = $insideBoundary;
        $allowed['updated_at'] = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $allowed['reviewed_at'] = $allowed['updated_at'];
        $this->db->update('eligible_addresses', $allowed, ['id' => $id], ['enabled' => 'boolean', 'inside_boundary' => 'boolean']);
    }

    /** @return list<array<string,mixed>> admin-only listing */
    public function search(string $q, int $limit = 100, int $offset = 0): array
    {
        $like = '%'.addcslashes(strtoupper(trim($q)), '%_\\').'%';

        return $this->db->fetchAllAssociative(
            'SELECT a.*, c.user_id, u.email_normalized AS owner_email, c.claimed_at, l.status, l.published, l.moderation_hidden, l.moderation_reason, l.event_date
             FROM eligible_addresses a
             LEFT JOIN claims c ON c.address_id = a.id
             LEFT JOIN users u ON u.id = c.user_id
             LEFT JOIN listings l ON l.address_id = a.id
             WHERE a.normalized_address LIKE :q OR COALESCE(u.email_normalized, \'\') ILIKE :q2
             ORDER BY a.street, a.house_number LIMIT :lim OFFSET :off',
            ['q' => $like, 'q2' => '%'.addcslashes(strtolower(trim($q)), '%_\\').'%', 'lim' => $limit, 'off' => $offset],
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER, 'off' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
    }

    public function count(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM eligible_addresses');
    }
}
