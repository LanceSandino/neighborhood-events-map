<?php

declare(strict_types=1);

namespace App\Listing;

use App\Community\CommunityConfig;
use App\Event\EventOccurrence;
use App\Event\EventType;
use App\Event\Schedule\DisplayStatus;
use App\Event\Schedule\ScheduleWindows;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Listing/ListingRepository
 * @description Event-scoped listing persistence (event_listings + event_listing_windows) plus the PUBLIC projection
 *     used by the family map. Public queries return only the ACTIVE occurrence's opted-in, unmoderated, in-boundary
 *     listings and never select owner/user columns; drafts and archived events are never public. A public home keeps
 *     the 0.9 keys (the Halloween details flat) and adds event_id, event_type, details (the type's strict
 *     projection), windows and display_status (D-20261031-09); a type with day-scoped statuses also names status_date
 *     (D-20261031-17). Every write is mirrored to the legacy `listings` row
 *     while the bridge applies (Listing/LegacyListingBridge). Resident-scoped reads and writes live in ResidentHomes.
 *
 * @dependencies DBAL, clock, Community/CommunityConfig, Event/*, Listing/LegacyListingBridge
 */
final class ListingRepository
{
    /** The windows of a listing as JSON [{date, start, end}] sorted by date (HH:MM times). */
    public const WINDOWS_JSON = "(SELECT COALESCE(json_agg(json_build_object('date', w.local_date, 'start', to_char(w.start_local, 'HH24:MI'), 'end', to_char(w.end_local, 'HH24:MI'))
        ORDER BY w.local_date), '[]'::json) FROM event_listing_windows w WHERE w.listing_id = el.id)";
    private const PUBLIC_COLUMNS = 'a.id, a.display_address, a.zone_key, a.latitude, a.longitude, el.details, el.note, el.status, el.status_date, el.updated_at, '.self::WINDOWS_JSON.' AS windows';
    /** moderation_reason of a listing whose new (self-entered) address waits for an organizer's approval */
    public const PENDING_REVIEW = 'pending_review';

    private const PUBLIC_WHERE = 'el.event_id = :event AND el.published AND NOT el.moderation_hidden AND a.enabled AND a.inside_boundary';

    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock, private readonly LegacyListingBridge $bridge)
    {
    }

    /**
     * Creates (or, for a listing that exists, replaces) an address's listing for an event, with its windows. A new
     * listing of an address under an organizer's hold starts hidden; an existing hide is kept.
     *
     * @param array<string,mixed>                              $draft   a normalized ListingDraft
     * @param list<array{date:string,start:string,end:string}> $windows
     *
     * @return int the listing id
     */
    public function insert(Connection $db, EventOccurrence $event, int $addressId, array $draft, array $windows, ?string $statusDate = null): int
    {
        return $db->transactional(function (Connection $db) use ($event, $addressId, $draft, $windows, $statusDate): int {
            $now = $this->now();
            $id = (int) $db->fetchOne(
                'INSERT INTO event_listings (event_id, address_id, details, status, status_date, note, published, moderation_hidden, published_at, updated_at)
                 VALUES (:event, :id, CAST(:details AS JSONB), :status, :sdate, :note, :pub, (SELECT moderation_hold FROM eligible_addresses WHERE id = :id), :now, :now)
                 ON CONFLICT (event_id, address_id) DO UPDATE SET details = EXCLUDED.details, status = EXCLUDED.status, status_date = EXCLUDED.status_date,
                    note = EXCLUDED.note, published = EXCLUDED.published, moderation_hidden = event_listings.moderation_hidden OR EXCLUDED.moderation_hidden,
                    published_at = EXCLUDED.published_at, updated_at = EXCLUDED.updated_at
                 RETURNING id',
                self::bindings($addressId, $draft) + ['event' => $event->id, 'sdate' => $statusDate, 'now' => $now],
                ['pub' => 'boolean'],
            );
            self::replaceWindows($db, $id, $windows);
            $this->bridge->mirror($db, $event->id, $addressId);

            return $id;
        });
    }

    /**
     * The active event's public homes.
     *
     * @return list<array<string,mixed>>
     */
    public function publicHomes(CommunityConfig $c, EventType $type): array
    {
        if (null === $c->event) {
            return [];
        }
        $hidden = $c->hiddenZoneKeys();
        $rows = array_filter($this->db->fetchAllAssociative(
            'SELECT '.self::PUBLIC_COLUMNS.' FROM event_listings el JOIN eligible_addresses a ON a.id = el.address_id
             WHERE '.self::PUBLIC_WHERE.' ORDER BY a.street, a.house_number',
            ['event' => $c->event->id],
        ), static fn (array $r) => !\in_array($r['zone_key'], $hidden, true));

        return array_values(array_map(fn (array $r) => self::publicShape($r, $type, $c->event, $this->clock->now(), $c->timezone()), $rows));
    }

    /**
     * One public home by address id: the same rules as publicHomes(), hidden zones included. The address id
     * resolves only within the active event.
     *
     * @return array<string,mixed>|null
     */
    public function publicHome(int $addressId, CommunityConfig $c, EventType $type): ?array
    {
        if (null === $c->event) {
            return null;
        }
        $row = $this->db->fetchAssociative(
            'SELECT '.self::PUBLIC_COLUMNS.' FROM event_listings el JOIN eligible_addresses a ON a.id = el.address_id
             WHERE '.self::PUBLIC_WHERE.' AND a.id = :id',
            ['event' => $c->event->id, 'id' => $addressId],
        );
        if (false === $row || $c->isHiddenZone($row['zone_key'])) {
            return null;
        }

        return self::publicShape($row, $type, $c->event, $this->clock->now(), $c->timezone());
    }

    /**
     * Hides/unhides an address's listing for the event. The decision is also kept on the address (moderation_hold),
     * so a new listing for the same address — a later event, or after release and re-claim — starts hidden.
     */
    public function setModeration(int $eventId, int $addressId, bool $hidden, ?string $reason): bool
    {
        return $this->db->transactional(function (Connection $db) use ($eventId, $addressId, $hidden, $reason): bool {
            $n = $db->executeStatement(
                'UPDATE event_listings SET moderation_hidden = :h, moderation_reason = :r, updated_at = :now WHERE event_id = :event AND address_id = :id',
                ['h' => $hidden, 'r' => $hidden ? $reason : null, 'now' => $this->now(), 'event' => $eventId, 'id' => $addressId],
                ['h' => 'boolean'],
            );
            if (1 === $n) {
                // Unhiding is also the approval of a self-entered address that was waiting for review.
                $db->executeStatement('UPDATE eligible_addresses SET moderation_hold = :h, reviewed_at = CASE WHEN :h THEN reviewed_at ELSE COALESCE(reviewed_at, :now) END WHERE id = :id',
                    ['h' => $hidden, 'now' => $this->now(), 'id' => $addressId], ['h' => 'boolean']);
                $this->bridge->mirror($db, $eventId, $addressId);
            }

            return 1 === $n;
        });
    }

    /** A new address's listing that started hidden waits for the organizer's review. */
    public function markPendingReview(Connection $db, int $eventId, int $addressId): bool
    {
        $n = $db->executeStatement('UPDATE event_listings SET moderation_reason = ? WHERE event_id = ? AND address_id = ? AND moderation_hidden',
            [self::PENDING_REVIEW, $eventId, $addressId]);
        if (1 === $n) {
            $this->bridge->mirror($db, $eventId, $addressId);
        }

        return 1 === $n;
    }

    /** @return array{public:int, hidden:int, pending_review:int} counts of the event's listings for the organizer */
    public function counts(?int $eventId): array
    {
        $r = null === $eventId ? false : $this->db->fetchAssociative(
            'SELECT COUNT(*) FILTER (WHERE el.published AND NOT el.moderation_hidden AND a.enabled AND a.inside_boundary) AS public,
                    COUNT(*) FILTER (WHERE el.moderation_hidden AND el.moderation_reason IS DISTINCT FROM :p) AS hidden,
                    COUNT(*) FILTER (WHERE el.moderation_hidden AND el.moderation_reason = :p) AS pending_review
             FROM event_listings el JOIN eligible_addresses a ON a.id = el.address_id WHERE el.event_id = :event',
            ['p' => self::PENDING_REVIEW, 'event' => $eventId],
        );

        return ['public' => (int) ($r['public'] ?? 0), 'hidden' => (int) ($r['hidden'] ?? 0), 'pending_review' => (int) ($r['pending_review'] ?? 0)];
    }

    /** @return list<array{id:int|string, address:string}> new addresses waiting for review in the event (oldest first) */
    public function pendingAddresses(?int $eventId, int $limit = 20): array
    {
        return null === $eventId ? [] : $this->db->fetchAllAssociative(
            'SELECT a.id, a.display_address AS address FROM event_listings el JOIN eligible_addresses a ON a.id = el.address_id
             WHERE el.event_id = :event AND el.moderation_hidden AND el.moderation_reason = :p ORDER BY a.created_at LIMIT '.max(1, $limit),
            ['event' => $eventId, 'p' => self::PENDING_REVIEW],
        );
    }

    /**
     * A public home: the 0.9 keys (core fields, the type's legacy fields flat, the hours of today's window or the next
     * one), then the event-scoped keys. `status` is the effective status (a day-scoped one only on its own day).
     *
     * @param array<string,mixed> $r
     *
     * @return array<string,mixed>
     */
    public static function publicShape(array $r, EventType $type, EventOccurrence $event, \DateTimeImmutable $now, \DateTimeZone $tz): array
    {
        $details = $type->publicDetails(self::storedDetails($r['details'] ?? null));
        $windows = self::windows($r['windows'] ?? null);
        $today = $now->setTimezone($tz)->format('Y-m-d');
        $when = ScheduleWindows::currentOrNext($windows, $today) ?? ['start' => $event->defaultStart, 'end' => $event->defaultEnd];
        $rules = $type->displayRules();
        $statusDate = isset($r['status_date']) ? (string) $r['status_date'] : null;
        // A day-scoped status (lights "off tonight") is named with its date while that date is today or later, so a
        // browser derives the right status on its own clock; once the day is over it reads "active", no date shown.
        $scoped = array_filter($rules['statuses'], static fn (array $s) => $s['day_scoped']);
        $current = isset($scoped[(string) $r['status']]) && null !== $statusDate && $statusDate >= $today;

        return [
            'id' => (int) $r['id'],
            'address' => (string) $r['display_address'],
            'zone' => $r['zone_key'],
            'lat' => round((float) $r['latitude'], 7),
            'lon' => round((float) $r['longitude'], 7),
            'start' => $when['start'],
            'end' => $when['end'],
        ] + $type->legacyFields($details) + [
            'note' => (string) $r['note'],
            'status' => $current ? (string) $r['status'] : DisplayStatus::effective($rules, (string) $r['status'], $statusDate, $today),
            'updated_at' => (new \DateTimeImmutable((string) $r['updated_at']))->format(\DATE_ATOM),
            'event_id' => $event->id,
            'event_type' => $type->key(),
            'details' => $details,
            'windows' => $windows,
            'display_status' => DisplayStatus::derive($rules, (string) $r['status'], $statusDate, $windows, $now, $tz),
        ] + ([] === $scoped ? [] : ['status_date' => $current ? $statusDate : null]);
    }

    /**
     * A stored listing row (with its windows) as a draft (the owner's view): core fields, the details (defaults
     * filled in, only the type's keys) and the type's legacy fields.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function toDraft(array $row, EventType $type): array
    {
        $defaults = $type->defaultDetails();
        $details = array_replace($defaults, array_intersect_key(self::storedDetails($row['details'] ?? null), $defaults));
        $windows = self::windows($row['windows'] ?? null);
        $first = $windows[0] ?? ['start' => '17:00', 'end' => '21:00'];

        return ['start' => $first['start'], 'end' => $first['end']]
            + $type->legacyFields($details)
            + ['note' => (string) $row['note'], 'status' => (string) $row['status'], 'published' => (bool) $row['published'], 'details' => $details, 'windows' => $windows];
    }

    /**
     * Named SQL parameters of a listing's own fields.
     *
     * @param array<string,mixed> $d normalized draft
     *
     * @return array<string,mixed>
     */
    public static function bindings(int $addressId, array $d): array
    {
        $details = \is_array($d['details'] ?? null) ? $d['details'] : throw new \LogicException('A listing draft carries its details (see ListingDraft).');

        return ['id' => $addressId, 'details' => json_encode((object) $details, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'note' => $d['note'], 'status' => $d['status'], 'pub' => $d['published']];
    }

    /** @param list<array{date:string,start:string,end:string}> $windows */
    public static function replaceWindows(Connection $db, int $listingId, array $windows): void
    {
        $db->executeStatement('DELETE FROM event_listing_windows WHERE listing_id = ?', [$listingId]);
        foreach ($windows as $w) {
            $db->executeStatement('INSERT INTO event_listing_windows (listing_id, local_date, start_local, end_local) VALUES (?, ?, ?, ?)', [$listingId, $w['date'], $w['start'], $w['end']]);
        }
    }

    /** @return list<array{date:string,start:string,end:string}> */
    public static function windows(mixed $value): array
    {
        $decoded = \is_string($value) ? json_decode($value, true, 8, \JSON_THROW_ON_ERROR) : $value;

        return \is_array($decoded) ? array_values(array_map(static fn (array $w) => ['date' => (string) $w['date'], 'start' => (string) $w['start'], 'end' => (string) $w['end']], $decoded)) : [];
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
