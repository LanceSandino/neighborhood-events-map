<?php

declare(strict_types=1);

namespace App\Event;

/**
 * @module Event/ClosingMessage
 * @description The thank-you text a closed event shows instead of homes (D-20261031-39): the organizer's own text when they
 *     wrote one (settings.closing_message, plain text up to 400 characters), otherwise the type's default
 *     (`page_closing_message`) with {event} — the event's name, with its year unless the name already has it — and
 *     {next} — next year's dates in words ("Oct 31, 2027, tentative", or "dates to be announced").
 *
 * @dependencies Event/EventOccurrence, Event/EventType, Event/EventDates
 */
final class ClosingMessage
{
    /** @param EventOccurrence|null $next the event of the same type that follows (upcoming or open), if any */
    public static function text(EventOccurrence $closed, EventType $type, ?EventOccurrence $next): string
    {
        $own = trim($closed->closingMessage());
        if ('' !== $own) {
            return $own;
        }

        return self::fill((string) ($type->copy()['page_closing_message'] ?? '{event} was a success — thank you! Next: {next}.'), $closed, $next);
    }

    /** The type's default text for an event that is closing now, with the dates its next event will get. */
    public static function preview(EventOccurrence $closing, EventType $type, ?string $nextFirst, ?string $nextLast): string
    {
        $next = new EventOccurrence(0, $closing->type, $closing->name, $nextFirst, $nextLast, $closing->defaultStart, $closing->defaultEnd, [],
            EventOccurrence::UPCOMING, datesTentative: null !== $nextFirst);

        return self::text($closing, $type, $next);
    }

    /** The event's name with its year ("Halloween 2026"); a name that already holds the year stays as it is. */
    public static function nameWithYear(EventOccurrence $event): string
    {
        $year = EventDates::year($event);

        return '' === $year || str_contains($event->name, $year) ? $event->name : $event->name.' '.$year;
    }

    private static function fill(string $template, EventOccurrence $closed, ?EventOccurrence $next): string
    {
        return strtr($template, ['{event}' => self::nameWithYear($closed), '{next}' => EventDates::words($next)]);
    }
}
