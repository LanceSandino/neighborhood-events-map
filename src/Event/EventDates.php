<?php

declare(strict_types=1);

namespace App\Event;

/**
 * @module Event/EventDates
 * @description An event's dates in words for visitors and organizers: "Oct 31, 2027" for one day, "Nov 1, 2027 – Jan 1,
 *     2028" for a range, "dates to be announced" without dates, and ", tentative" after dates that are not confirmed
 *     yet (D-20261031-39).
 *
 * @dependencies Event/EventOccurrence
 */
final class EventDates
{
    public const TBD = 'dates to be announced';

    /** The dates of an event in words, with ", tentative" when they are not confirmed. */
    public static function words(?EventOccurrence $event): string
    {
        if (null === $event || !$event->hasDates()) {
            return self::TBD;
        }

        return self::range($event->firstDay(), $event->lastDay()).($event->datesTentative ? ', tentative' : '');
    }

    /** "Oct 31, 2027" or "Nov 1, 2027 – Jan 1, 2028". */
    public static function range(string $first, string $last): string
    {
        $f = new \DateTimeImmutable($first);
        if ($first === $last) {
            return $f->format('M j, Y');
        }

        return $f->format('M j, Y').' – '.(new \DateTimeImmutable($last))->format('M j, Y');
    }

    /** The event's year (its first day's, else the year it was created in), e.g. for "Halloween 2026". */
    public static function year(EventOccurrence $event): string
    {
        return substr($event->startsOn ?? $event->createdAt ?? '', 0, 4);
    }
}
