<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventType;
use App\Shared\TextInput;

/**
 * @module Community/EventBlockSchema
 * @description Validates the `event` block of the community settings (community.yaml or the console): name, the
 *     event's dates (`date` for a one-day event; `starts_on`/`ends_on` for several days, `date` being the first), default hours
 *     (`starts_at`/`ends_at`) and the type key chosen by CommunitySchema. The block describes the active event
 *     occurrence: the YAML bootstrap creates the first occurrence from it, later saves write the occurrence.
 *
 * @dependencies Event/EventType, Shared/TextInput
 */
final class EventBlockSchema
{
    /** The longest date range of an event whose type is not a one-day event. */
    public const MAX_RANGE_DAYS = 62;

    /**
     * A one-day event (Halloween) keeps exactly its 0.10 block: name, date, starts_at, ends_at, type; its day is
     * `date` (starts_on/ends_on, if sent, are that day). An event of several days adds starts_on/ends_on.
     *
     * @param array<mixed> $ev
     * @param list<string> $e  collected errors
     *
     * @return array{name:string,date:string,starts_at:?string,ends_at:?string,type:string,starts_on?:string,ends_on?:string}
     */
    public static function normalize(array $ev, EventType $type, array &$e): array
    {
        $oneDay = 'single_day' === $type->scheduleKind();
        $name = TextInput::line($ev['name'] ?? '', 40) ?: $type->defaultName();
        $date = (string) ($ev['date'] ?? $ev['starts_on'] ?? '');
        if (!self::isDate($date)) {
            $e[] = 'event.date must be YYYY-MM-DD.';
        }
        $startsOn = $oneDay ? $date : (string) ($ev['starts_on'] ?? $date);
        $endsOn = $oneDay ? $date : (string) ($ev['ends_on'] ?? $startsOn);
        if (self::isDate($date) && !$oneDay) {
            if ($startsOn !== $date) {
                $e[] = 'event.starts_on must be the same day as event.date (the first day of the event).';
            } elseif (!self::isDate($endsOn) || $endsOn < $startsOn) {
                $e[] = 'event.ends_on must be YYYY-MM-DD, on or after the first day.';
            } elseif (self::days($startsOn, $endsOn) > self::MAX_RANGE_DAYS) {
                $e[] = sprintf('event.ends_on: an event spans at most %d days.', self::MAX_RANGE_DAYS);
            }
        }
        $start = self::time($ev['starts_at'] ?? '17:00');
        $end = self::time($ev['ends_at'] ?? '21:00');
        if (null === $start || null === $end || $end <= $start) {
            $e[] = 'event.starts_at/ends_at must be HH:MM with the end after the start.';
        }

        return ['name' => $name, 'date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'type' => $type->key()]
            + ($oneDay ? [] : ['starts_on' => $startsOn, 'ends_on' => $endsOn]);
    }

    /** @param array<string,mixed> $block a normalized block @return array{0:string,1:string} its first and last day */
    public static function range(array $block): array
    {
        $first = (string) ($block['starts_on'] ?? $block['date']);

        return [$first, (string) ($block['ends_on'] ?? $first)];
    }

    public static function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return false !== $d && $d->format('Y-m-d') === $v;
    }

    /** HH:MM (YAML may parse 17:00 as the sexagesimal integer 1020), or null. */
    public static function time(mixed $v): ?string
    {
        $s = \is_scalar($v) ? (string) $v : '';
        if (\is_int($v)) {
            $s = sprintf('%02d:%02d', intdiv($v, 60), $v % 60);
        }

        return preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $s) ? $s : null;
    }

    /** Number of days from the first to the last date, both included. */
    public static function days(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
    }
}
