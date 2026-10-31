<?php

declare(strict_types=1);

namespace App\Event\Schedule;

use App\Event\EventOccurrence;
use App\Shared\ValidationException;

/**
 * @module Event/Schedule/ScheduleWindows
 * @description The days and hours a listing takes part in (event_listing_windows), checked against its event:
 *     every window lies inside the occurrence's dates, a date appears once, a listing has at most the schedule
 *     kind's days (single_day 1 — exactly the event day —, nightly_range 62, sale_days 3) and the organizer's own
 *     cap, the end is later than the start on the same local day (no overnight windows) and the hours stay inside
 *     the bounds. A type may narrow the days a listing can choose (a garage sale's own sale days). Windows come back
 *     sorted by date as {date, start, end} with HH:MM times.
 *
 * @dependencies Event/EventOccurrence, Shared/ValidationException
 */
final class ScheduleWindows
{
    /** Schedule kinds and the most days one listing may take part in. */
    public const MAX_DAYS = ['single_day' => 1, 'nightly_range' => 62, 'sale_days' => 3];
    /** Hours a window may use when the type sets no narrower bounds: the whole local day. */
    public const DAY = ['00:00', '23:59'];

    /**
     * A one-day listing: the event's day with the home's own hours.
     *
     * @return list<array{date:string,start:string,end:string}>
     */
    public static function singleDay(EventOccurrence $event, string $start, string $end): array
    {
        return self::normalize('single_day', $event, [['date' => $event->startsOn, 'start' => $start, 'end' => $end]]);
    }

    /**
     * @param list<mixed>            $input  windows as {date, start, end}
     * @param array{0:string,1:string} $bounds earliest start and latest end (HH:MM)
     * @param int|null                $cap    the organizer's own limit of days per listing, if any
     * @param list<string>|null       $dates  the only days a listing may choose (null: every day of the event)
     *
     * @return list<array{date:string,start:string,end:string}>
     *
     * @throws ValidationException field "windows" (the whole list) or "windows.N" (one window)
     */
    public static function normalize(string $kind, EventOccurrence $event, mixed $input, array $bounds = self::DAY, ?int $cap = null, ?array $dates = null): array
    {
        $max = self::MAX_DAYS[$kind] ?? throw new \InvalidArgumentException(sprintf('Unknown schedule kind "%s".', $kind));
        $max = null === $cap ? $max : max(1, min($max, $cap));
        if (!\is_array($input) || !array_is_list($input) || [] === $input) {
            throw new ValidationException(['windows' => 'Choose at least one day.']);
        }
        if (\count($input) > $max) {
            throw new ValidationException(['windows' => 1 === $max ? 'This event has one day.' : sprintf('Choose at most %d days.', $max)]);
        }
        $out = [];
        $errors = [];
        foreach ($input as $i => $w) {
            $date = \is_array($w) && \is_string($w['date'] ?? null) ? $w['date'] : '';
            $start = \is_array($w) ? self::time($w['start'] ?? null) : null;
            $end = \is_array($w) ? self::time($w['end'] ?? null) : null;
            if (!self::isDate($date)) {
                $errors["windows.$i"] = 'Use a date like 2026-12-18.';
            } elseif (!$event->contains($date)) {
                $errors["windows.$i"] = sprintf('Choose a day from %s to %s.', $event->startsOn, $event->endsOn);
            } elseif (null !== $dates && !\in_array($date, $dates, true)) {
                $errors["windows.$i"] = sprintf('Choose one of the event\'s days: %s.', implode(', ', $dates));
            } elseif ('single_day' === $kind && $date !== $event->startsOn) {
                $errors["windows.$i"] = 'This event is on '.$event->startsOn.'.';
            } elseif (isset($out[$date])) {
                $errors["windows.$i"] = 'That day is already chosen.';
            } elseif (null === $start || null === $end) {
                $errors["windows.$i"] = 'Use times like 18:00.';
            } elseif ($end <= $start) {
                $errors["windows.$i"] = 'End time must be later than the start time.';
            } elseif ($start < $bounds[0] || $end > $bounds[1]) {
                $errors["windows.$i"] = sprintf('Choose hours between %s and %s.', $bounds[0], $bounds[1]);
            } else {
                $out[$date] = ['date' => $date, 'start' => $start, 'end' => $end];
            }
        }
        if ([] !== $errors) {
            throw new ValidationException($errors);
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * The window that answers "when": today's, else the next one, else the last one (a listing whose days are over).
     *
     * @param list<array{date:string,start:string,end:string}> $windows sorted by date
     *
     * @return array{date:string,start:string,end:string}|null
     */
    public static function currentOrNext(array $windows, string $today): ?array
    {
        foreach ($windows as $w) {
            if ($w['date'] >= $today) {
                return $w;
            }
        }

        return [] === $windows ? null : $windows[\count($windows) - 1];
    }

    private static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return false !== $d && $d->format('Y-m-d') === $v;
    }

    private static function time(mixed $v): ?string
    {
        $s = \is_string($v) ? substr(trim($v), 0, 5) : '';

        return preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $s) ? $s : null;
    }
}
