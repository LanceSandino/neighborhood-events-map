<?php

declare(strict_types=1);

namespace App\Event;

use Doctrine\DBAL\Connection;

/**
 * @module Event/EventDateChange
 * @description What new dates or days of an event mean for the listings it has, inside the organizer's edit
 *     (Event/EventLifecycle::update, under the occurrences lock): a one-day event's windows move to its new day, other
 *     kinds' windows must still fit the new dates, and a day homes chose (a sale's days) cannot be removed.
 *
 * @dependencies DBAL, Event/EventOccurrence, Event/EventType, Event/EventSettingsType, Event/EventConflictException
 */
final class EventDateChange
{
    /**
     * Moves the listings' windows with the event's new dates: a one-day event's to its new day; for other kinds the
     * windows must still fit.
     *
     * @param array{starts_on:?string,ends_on:?string} $fields
     */
    public function moveWindows(Connection $db, EventOccurrence $current, array $fields, EventType $type): void
    {
        if ('single_day' === $type->scheduleKind()) {
            $db->executeStatement('UPDATE event_listing_windows w SET local_date = :day FROM event_listings el WHERE el.id = w.listing_id AND el.event_id = :event',
                ['day' => $fields['starts_on'], 'event' => $current->id]);

            return;
        }
        $outside = (int) $db->fetchOne('SELECT COUNT(*) FROM event_listing_windows w JOIN event_listings el ON el.id = w.listing_id
            WHERE el.event_id = :event AND (w.local_date < :from OR w.local_date > :to)', ['event' => $current->id, 'from' => $fields['starts_on'], 'to' => $fields['ends_on']]);
        if ($outside > 0) {
            throw new EventConflictException('listings_outside', sprintf('%d listing day(s) fall outside the new dates. Keep the dates that homes chose.', $outside));
        }
    }

    /**
     * A type that narrows a listing's days (a sale's days) keeps every day homes already chose: removing a sale day
     * that has listings is refused.
     *
     * @param array{name:string,starts_on:?string,ends_on:?string,default_start:string,default_end:string,settings:array<string,mixed>} $fields
     */
    public function keepListedDays(Connection $db, EventOccurrence $current, array $fields, EventType $type): void
    {
        if (!$type instanceof EventSettingsType || null === $fields['starts_on']) {
            return;
        }
        $next = new EventOccurrence($current->id, $current->type, $fields['name'], $fields['starts_on'], $fields['ends_on'], $fields['default_start'],
            $fields['default_end'], $fields['settings'], $current->state);
        $dates = $type->listingDates($next);
        if (null === $dates) {
            return;
        }
        $off = (int) $db->fetchOne('SELECT COUNT(*) FROM event_listing_windows w JOIN event_listings el ON el.id = w.listing_id
            WHERE el.event_id = :event AND NOT (to_char(w.local_date, \'YYYY-MM-DD\') = ANY(string_to_array(:dates, \',\')))',
            ['event' => $current->id, 'dates' => implode(',', $dates)]);
        if ($off > 0) {
            throw new EventConflictException('listings_days', sprintf('%d listing day(s) fall on a day that is no longer one of the event\'s days. Keep the days that homes chose.', $off));
        }
    }
}
