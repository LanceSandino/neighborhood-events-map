<?php

declare(strict_types=1);

namespace App\Event;

use Doctrine\DBAL\Connection;

/**
 * @module Event/EventListingExport
 * @description The organizer's private CSV of one event's listings (Organizer → Events → Export): address, zone,
 *     status, publication and moderation, the listing's days and hours, its note and details. Owner emails are not
 *     in it (the claims export has ownership). Cells that a spreadsheet would run as a formula are quoted.
 *
 * @dependencies DBAL, Event/EventOccurrence
 */
final class EventListingExport
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function csv(EventOccurrence $event): string
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT a.id, a.display_address, a.zone_key, el.status, el.published, el.moderation_hidden, el.moderation_reason,
                    (SELECT string_agg(to_char(w.local_date, 'YYYY-MM-DD') || ' ' || to_char(w.start_local, 'HH24:MI') || '-' || to_char(w.end_local, 'HH24:MI'), '; ' ORDER BY w.local_date)
                     FROM event_listing_windows w WHERE w.listing_id = el.id) AS days,
                    el.note, el.details, el.updated_at
             FROM event_listings el JOIN eligible_addresses a ON a.id = el.address_id
             WHERE el.event_id = ? ORDER BY a.street, a.house_number",
            [$event->id],
        );
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['event_id', 'event', 'address_id', 'address', 'zone', 'status', 'published', 'hidden', 'moderation_reason', 'days', 'note', 'details', 'updated_at'], escape: '');
        foreach ($rows as $r) {
            $line = [$event->id, $event->name, $r['id'], $r['display_address'], $r['zone_key'], $r['status'], $r['published'] ? 'yes' : 'no',
                $r['moderation_hidden'] ? 'yes' : 'no', $r['moderation_reason'], $r['days'], $r['note'], $r['details'], $r['updated_at']];
            fputcsv($fh, array_map(static fn ($v) => \is_string($v) && preg_match('~^[=+\-@\t\r]~', $v) ? "'".$v : $v, $line), escape: '');
        }
        rewind($fh);

        return (string) stream_get_contents($fh);
    }
}
