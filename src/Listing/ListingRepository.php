<?php

declare(strict_types=1);

namespace App\Listing;

use App\Event\EventType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Listing/ListingRepository
 * @description Listing persistence plus the PUBLIC projection used by the family map. The public
 *     queries return only opted-in, unmoderated, in-boundary listings for the current event date and
 *     never select owner/user columns. Type-specific fields are read from `details` and projected by the
 *     event type; writes store `details` and keep the legacy Halloween columns in step (LegacyListingColumns).
 *     Resident-scoped reads and writes live in ResidentHomes.
 *
 * @dependencies DBAL, clock, Event/EventType, Listing/LegacyListingColumns
 */
final class ListingRepository
{
    private const PUBLIC_COLUMNS = 'a.id, a.display_address, a.zone_key, a.latitude, a.longitude,
        l.start_local, l.end_local, l.details, l.note, l.status, l.updated_at';
    /** moderation_reason of a listing whose new (self-entered) address waits for an organizer's approval */
    public const PENDING_REVIEW = 'pending_review';

    private const PUBLIC_WHERE = 'l.published AND NOT l.moderation_hidden AND a.enabled AND a.inside_boundary AND l.event_date = :date';

    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
    }

    /** @param array<string,mixed> $draft a normalized ListingDraft */
    public function insert(Connection $db, int $addressId, string $eventDate, array $draft): void
    {
        $now = $this->now();
        $db->executeStatement(
            'INSERT INTO listings (address_id, event_date, start_local, end_local, details, '.LegacyListingColumns::COLUMN_LIST.', note, status, published, published_at, updated_at, moderation_hidden)
             VALUES (:id, :date, :start, :end, CAST(:details AS JSONB), '.LegacyListingColumns::PARAMETER_LIST.', :note, :status, :pub, :now, :now,
                (SELECT moderation_hold FROM eligible_addresses WHERE id = :id))
             ON CONFLICT (address_id) DO UPDATE SET event_date = EXCLUDED.event_date, start_local = EXCLUDED.start_local, end_local = EXCLUDED.end_local,
                details = EXCLUDED.details, '.LegacyListingColumns::upsertAssignments().', note = EXCLUDED.note,
                status = EXCLUDED.status, published = EXCLUDED.published, moderation_hidden = listings.moderation_hidden OR EXCLUDED.moderation_hidden,
                published_at = EXCLUDED.published_at,
                updated_at = EXCLUDED.updated_at',
            self::bindings($addressId, $eventDate, $draft) + ['now' => $now],
            self::bindingTypes(),
        );
    }

    /**
     * @param list<string> $hiddenZones zones the organizer has hidden: their homes are not public either
     *
     * @return list<array<string,mixed>>
     */
    public function publicHomes(string $eventDate, array $hiddenZones, EventType $type): array
    {
        $homes = array_map(static fn (array $r) => self::publicShape($r, $type), $this->db->fetchAllAssociative(
            'SELECT '.self::PUBLIC_COLUMNS.' FROM listings l JOIN eligible_addresses a ON a.id = l.address_id
             WHERE '.self::PUBLIC_WHERE.' ORDER BY a.street, a.house_number',
            ['date' => $eventDate],
        ));

        return array_values(array_filter($homes, static fn (array $h) => !\in_array($h['zone'], $hiddenZones, true)));
    }

    /**
     * One public home by id: the same rules as publicHomes(), hidden zones included.
     *
     * @param list<string> $hiddenZones zones the organizer has hidden: their homes are not public either
     *
     * @return array<string,mixed>|null
     */
    public function publicHome(int $addressId, string $eventDate, array $hiddenZones, EventType $type): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT '.self::PUBLIC_COLUMNS.' FROM listings l JOIN eligible_addresses a ON a.id = l.address_id
             WHERE '.self::PUBLIC_WHERE.' AND a.id = :id',
            ['date' => $eventDate, 'id' => $addressId],
        );
        if (false === $row || \in_array($row['zone_key'], $hiddenZones, true)) {
            return null;
        }

        return self::publicShape($row, $type);
    }

    /**
     * Hides/unhides a listing. The decision is also kept on the address (moderation_hold), so a new
     * listing for the same address — after release and re-claim — starts hidden until unhidden.
     */
    public function setModeration(int $addressId, bool $hidden, ?string $reason): bool
    {
        return $this->db->transactional(function (Connection $db) use ($addressId, $hidden, $reason): bool {
            $n = $db->executeStatement(
                'UPDATE listings SET moderation_hidden = :h, moderation_reason = :r, updated_at = :now WHERE address_id = :id',
                ['h' => $hidden, 'r' => $hidden ? $reason : null, 'now' => $this->now(), 'id' => $addressId],
                ['h' => 'boolean'],
            );
            if (1 === $n) {
                // Unhiding is also the approval of a self-entered address that was waiting for review.
                $db->executeStatement('UPDATE eligible_addresses SET moderation_hold = :h, reviewed_at = CASE WHEN :h THEN reviewed_at ELSE COALESCE(reviewed_at, :now) END WHERE id = :id',
                    ['h' => $hidden, 'now' => $this->now(), 'id' => $addressId], ['h' => 'boolean']);
            }

            return 1 === $n;
        });
    }

    /** Hides every listing from a past event date (season archive). */
    public function archiveBefore(string $eventDate): int
    {
        return $this->db->executeStatement('UPDATE listings SET published = FALSE, updated_at = :now WHERE event_date < :d AND published', ['now' => $this->now(), 'd' => $eventDate]);
    }

    /**
     * A public home: the core fields and the type's public details as its flat fields, read from `details`.
     * The keys are exactly those of 0.9 (D-20261031-04); event_type and a `details` object join them with
     * event-scoped listings.
     *
     * @param array<string,mixed> $r
     *
     * @return array<string,mixed>
     */
    public static function publicShape(array $r, EventType $type): array
    {
        $details = $type->publicDetails(self::storedDetails($r['details'] ?? null));

        return [
            'id' => (int) $r['id'],
            'address' => (string) $r['display_address'],
            'zone' => $r['zone_key'],
            'lat' => round((float) $r['latitude'], 7),
            'lon' => round((float) $r['longitude'], 7),
            'start' => substr((string) $r['start_local'], 0, 5),
            'end' => substr((string) $r['end_local'], 0, 5),
        ] + $type->legacyFields($details) + [
            'note' => (string) $r['note'],
            'status' => (string) $r['status'],
            'updated_at' => (new \DateTimeImmutable((string) $r['updated_at']))->format(\DATE_ATOM),
        ];
    }

    /**
     * A stored listing row as a draft (the owner's view): core fields, the details (defaults filled in, only
     * the type's keys) and the type's legacy fields.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function toDraft(array $row, EventType $type): array
    {
        $defaults = $type->defaultDetails();
        $details = array_replace($defaults, array_intersect_key(self::storedDetails($row['details'] ?? null), $defaults));

        return ['start' => substr((string) $row['start_local'], 0, 5), 'end' => substr((string) $row['end_local'], 0, 5)]
            + $type->legacyFields($details)
            + ['note' => (string) $row['note'], 'status' => (string) $row['status'], 'published' => (bool) $row['published'], 'details' => $details];
    }

    /**
     * Named SQL parameters shared by every listing write.
     *
     * @param array<string,mixed> $d normalized draft
     *
     * @return array<string,mixed>
     */
    public static function bindings(int $addressId, string $eventDate, array $d): array
    {
        $details = \is_array($d['details'] ?? null) ? $d['details'] : throw new \LogicException('A listing draft carries its details (see ListingDraft).');

        return ['id' => $addressId, 'date' => $eventDate, 'start' => $d['start'], 'end' => $d['end'],
            'details' => json_encode($details, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)]
            + LegacyListingColumns::bindings($details)
            + ['note' => $d['note'], 'status' => $d['status'], 'pub' => $d['published']];
    }

    /** @return array<string,string> */
    public static function bindingTypes(): array
    {
        return LegacyListingColumns::bindingTypes() + ['pub' => 'boolean'];
    }

    /** @return array<string,mixed> the decoded details column (DBAL returns JSONB as text) */
    private static function storedDetails(mixed $value): array
    {
        $decoded = \is_string($value) ? json_decode($value, true, 16, \JSON_THROW_ON_ERROR) : $value;

        return \is_array($decoded) ? $decoded : [];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.uP');
    }
}
