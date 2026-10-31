<?php

declare(strict_types=1);

namespace App\Listing;

use App\Event\Type\HalloweenType;
use Doctrine\DBAL\Connection;

/**
 * @module Listing/LegacyListingBridge
 * @description Rollback safety for event-scoped listings (D-20261031-06). The event tables are the source of truth;
 *     while the ACTIVE occurrence is a one-day Halloween event, every write to its listings is mirrored into the
 *     legacy `listings` row of the address (event_date = the event's day, the window's hours, details and their
 *     Halloween columns, status, note, publication and moderation), exactly the row 0.10 would have written, so the
 *     0.9/0.10 images still show the right homes after an image-only rollback. Mirror writes set nhm.bridge_writer
 *     for their transaction, which the reverse trigger (listings_bridge_to_events) skips. Archiving an event
 *     unpublishes its day's legacy rows and purging its listings deletes them. Listings of other types live only in
 *     the event tables (the previous release does not know them).
 *
 * @dependencies DBAL, Listing/LegacyListingColumns, Event/Type/HalloweenType
 */
final class LegacyListingBridge
{
    /** Upserts the legacy row of each bridged listing of the active one-day Halloween event (all, or one address). */
    public function mirror(Connection $db, int $eventId, ?int $addressId = null): int
    {
        self::claimWrites($db);
        $columns = implode(', ', array_map(static fn (string $c) => 'scare_level' === $c ? "el.details->>'scare_level'" : "(el.details->>'$c')::boolean", LegacyListingColumns::COLUMNS));

        return $db->executeStatement(
            'INSERT INTO listings (address_id, event_date, start_local, end_local, details, '.LegacyListingColumns::COLUMN_LIST.', note, status, published,
                moderation_hidden, moderation_reason, published_at, updated_at)
             SELECT el.address_id, w.local_date, w.start_local, w.end_local, el.details, '.$columns.', el.note, el.status, el.published,
                el.moderation_hidden, el.moderation_reason, el.published_at, el.updated_at
             FROM event_listings el
             JOIN event_occurrences o ON o.id = el.event_id
             JOIN event_listing_windows w ON w.listing_id = el.id AND w.local_date = o.starts_on
             WHERE el.event_id = :event AND o.state = \'active\' AND o.type = :type AND o.starts_on = o.ends_on
               AND (CAST(:address AS BIGINT) IS NULL OR el.address_id = CAST(:address AS BIGINT))
             ON CONFLICT (address_id) DO UPDATE SET event_date = EXCLUDED.event_date, start_local = EXCLUDED.start_local, end_local = EXCLUDED.end_local,
                details = EXCLUDED.details, '.LegacyListingColumns::upsertAssignments().', note = EXCLUDED.note, status = EXCLUDED.status,
                published = EXCLUDED.published, moderation_hidden = EXCLUDED.moderation_hidden, moderation_reason = EXCLUDED.moderation_reason,
                published_at = EXCLUDED.published_at, updated_at = EXCLUDED.updated_at',
            ['event' => $eventId, 'type' => HalloweenType::KEY, 'address' => $addressId],
        );
    }

    /** An archived event's day leaves the previous release's map too. */
    public function unpublishDay(Connection $db, string $day): int
    {
        self::claimWrites($db);

        return $db->executeStatement('UPDATE listings SET published = FALSE WHERE event_date = ? AND published', [$day]);
    }

    /** The season archive: legacy rows from days before the active event leave the previous release's map. */
    public function unpublishBefore(Connection $db, string $day): int
    {
        self::claimWrites($db);

        return $db->executeStatement('UPDATE listings SET published = FALSE WHERE event_date < ? AND published', [$day]);
    }

    /** Retention: an archived event's purged listings leave the legacy table as well. */
    public function deleteDay(Connection $db, string $day): int
    {
        self::claimWrites($db);

        return $db->executeStatement('DELETE FROM listings WHERE event_date = ?', [$day]);
    }

    /** Marks this transaction's legacy writes as the app's own, so the reverse trigger does not copy them back. */
    private static function claimWrites(Connection $db): void
    {
        if (!$db->isTransactionActive()) {
            throw new \LogicException('Legacy mirror writes run inside the transaction of the event-table write they mirror.');
        }
        $db->executeQuery("SELECT set_config('nhm.bridge_writer', 'events', true)");
    }
}
