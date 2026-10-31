<?php

declare(strict_types=1);

namespace App\Listing;

use App\Community\CommunityConfig;
use App\Event\EventOccurrence;
use App\Event\EventSettingsType;
use App\Event\EventType;
use App\Event\EventTypeRegistry;
use App\Event\ListingTextCheck;
use App\Event\OccurrenceSettings;
use App\Event\Schedule\ScheduleWindows;
use App\Shared\TextInput;
use App\Shared\ValidationException;

/**
 * @module Listing/ListingDraft
 * @description Validates and normalizes resident-submitted listing input: the core fields every event type
 *     shares (hours, status, note, visibility) here, the type-specific details through the community's event
 *     type (strict: unknown keys are refused). Input sends details as a `details` object; during the
 *     compatibility window (D-20261031-02) the Halloween type's flat fields are accepted too, and a draft also
 *     carries them flat next to `details` (the shape 0.9 code and the legacy columns know). A one-day event's listing
 *     takes the event day with its own hours (start/end); a type with several days also sends `windows`, checked by
 *     the schedule core (Event/Schedule/ScheduleWindows) against the active event and the days the type allows
 *     (Event/EventSettingsType::listingDates). A type that checks resident text (Event/ListingTextCheck) refuses a
 *     note it may not publish: the draft fails, so nothing is stored.
 *
 * @input array<string,mixed> raw form/JSON input, CommunityConfig
 * @output array<string,mixed> normalized listing draft
 * @dependencies Community/CommunityConfig, Event/EventTypeRegistry, Event/EventSettingsType, Event/ListingTextCheck, Event/Schedule/ScheduleWindows,
 *     Shared/TextInput, Shared/ValidationException
 */
final class ListingDraft
{
    /** Fields every event type shares; everything else is a detail of the event type. */
    public const CORE_FIELDS = ['start', 'end', 'status', 'note', 'published'];
    public const NOTE_MAX = 400;

    /**
     * @param array<string,mixed>      $in
     * @param array<string,mixed>|null $current the listing as stored (toDraft), for partial updates
     *
     * @return array<string,mixed>
     */
    public static function fromInput(array $in, CommunityConfig $community, bool $partial = false, ?array $current = null, ?EventTypeRegistry $types = null): array
    {
        $type = ($types ?? EventTypeRegistry::builtIn())->for($community);
        $errors = [];
        $out = [
            'start' => $current['start'] ?? $community->settings['event']['starts_at'],
            'end' => $current['end'] ?? $community->settings['event']['ends_at'],
        ];

        foreach (['start', 'end'] as $f) {
            if (\array_key_exists($f, $in)) {
                $v = \is_string($in[$f]) ? substr(trim($in[$f]), 0, 5) : '';
                if (!preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $v)) {
                    $errors[$f] = 'Use a time like 18:00.';
                } else {
                    $out[$f] = $v;
                }
            }
        }
        if (!isset($errors['start'], $errors['end']) && $out['end'] <= $out['start']) {
            $errors['end'] = 'End time must be later than the start time.';
        }

        [$detailInput, $unknown] = self::detailInput($in, $type);
        $details = null;
        try {
            $details = $type->normalizeDetails(array_replace(self::detailsOf($current ?? [], $type), $detailInput), OccurrenceSettings::fromCommunity($community));
        } catch (ValidationException $e) {
            $errors += $e->fields;
        }

        $status = $current['status'] ?? 'active';
        if (\array_key_exists('status', $in)) {
            if (!\is_string($in['status']) || !\array_key_exists($in['status'], $type->statuses())) {
                $errors['status'] = 'Unknown status.';
            } else {
                $status = $in['status'];
            }
        }
        $note = $current['note'] ?? '';
        if (\array_key_exists('note', $in)) {
            $text = TextInput::paragraphs(\is_string($in['note']) ? $in['note'] : '', 4 * self::NOTE_MAX);
            $problem = $type instanceof ListingTextCheck ? $type->textProblem('note', $text) : null;
            if (mb_strlen($text) > self::NOTE_MAX) {
                $errors['note'] = 'Keep the note to 400 characters.';
            } elseif (null !== $problem) {
                $errors['note'] = $problem;
            } else {
                $note = $text;
            }
        }
        $published = $current['published'] ?? true;
        if (\array_key_exists('published', $in)) {
            $published = filter_var($in['published'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? true;
        }
        $windows = self::hasDays($type) ? ($current['windows'] ?? null) : null;
        if (self::hasDays($type) && \array_key_exists('windows', $in)) {
            try {
                $windows = null === $community->event
                    ? throw new ValidationException(['windows' => 'There is no event on the map right now.'])
                    : ScheduleWindows::normalize($type->scheduleKind(), $community->event, $in['windows'], ScheduleWindows::DAY, self::dayCap($community->event),
                        self::dates($community->event, $type));
            } catch (ValidationException $e) {
                $errors += $e->fields;
            }
        }

        $errors += $unknown;
        if ([] !== $errors || null === $details) {
            throw new ValidationException($errors);
        }

        return $out + $type->legacyFields($details) + ['note' => $note, 'status' => $status, 'published' => $published, 'details' => $details]
            + (null !== $windows ? ['windows' => $windows] : []);
    }

    /**
     * The windows a draft takes part in for an event: a one-day event's day with the draft's hours, or the draft's
     * own windows for a type with several days.
     *
     * @param array<string,mixed> $draft a normalized draft
     *
     * @return list<array{date:string,start:string,end:string}>
     *
     * @throws ValidationException when a type with several days got no days
     */
    public static function windowsFor(array $draft, EventOccurrence $event, EventType $type): array
    {
        if (!self::hasDays($type)) {
            return ScheduleWindows::singleDay($event, (string) $draft['start'], (string) $draft['end']);
        }

        return ScheduleWindows::normalize($type->scheduleKind(), $event, $draft['windows'] ?? null, ScheduleWindows::DAY, self::dayCap($event), self::dates($event, $type));
    }

    /**
     * The days a listing of the event may choose, when the type narrows them (a garage sale's sale days).
     *
     * @return list<string>|null
     */
    private static function dates(EventOccurrence $event, EventType $type): ?array
    {
        return $type instanceof EventSettingsType ? $type->listingDates($event) : null;
    }

    /**
     * The status_date a listing stores with its status: the community-local date for a day-scoped status (it holds
     * only that day), else null.
     *
     * @param array<string,mixed> $draft
     */
    public static function statusDate(array $draft, EventType $type, CommunityConfig $community, \DateTimeImmutable $now): ?string
    {
        return ($type->statuses()[$draft['status']]['day_scoped'] ?? false) ? $community->localDate($now) : null;
    }

    /** A type whose listings choose their own days (not a one-day event). */
    private static function hasDays(EventType $type): bool
    {
        return 'single_day' !== $type->scheduleKind();
    }

    /** The organizer's own limit of days per listing (occurrence settings max_days), if any. */
    private static function dayCap(EventOccurrence $event): ?int
    {
        return isset($event->settings['max_days']) && \is_int($event->settings['max_days']) ? $event->settings['max_days'] : null;
    }

    /**
     * The details of a draft or stored listing. A draft saved by 0.9 (a pending claim intent) has only the flat
     * Halloween fields; they are its details.
     *
     * @param array<string,mixed> $draft
     *
     * @return array<string,mixed>
     */
    public static function detailsOf(array $draft, EventType $type): array
    {
        if (\is_array($draft['details'] ?? null)) {
            return $draft['details'];
        }

        return array_intersect_key($draft, $type->defaultDetails());
    }

    /**
     * A draft with its `details`, for a draft stored before details existed.
     *
     * @param array<string,mixed> $draft
     *
     * @return array<string,mixed>
     */
    public static function withDetails(array $draft, EventType $type): array
    {
        return \is_array($draft['details'] ?? null) ? $draft : $draft + ['details' => $type->publicDetails(self::detailsOf($draft, $type))];
    }

    /**
     * Splits the input into the type's detail input (the `details` object wins over flat legacy fields) and
     * refusals for keys nobody knows.
     *
     * @param array<string,mixed> $in
     *
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private static function detailInput(array $in, EventType $type): array
    {
        $legacy = $type->legacyFields($type->defaultDetails());
        $details = [];
        $unknown = [];
        foreach ($in as $k => $v) {
            $k = (string) $k;
            if (\in_array($k, self::CORE_FIELDS, true) || 'details' === $k || ('windows' === $k && self::hasDays($type))) {
                continue;
            }
            if (\array_key_exists($k, $legacy)) {
                $details[$k] = $v;
            } else {
                $unknown[$k] = 'Unknown field.';
            }
        }
        if (\array_key_exists('details', $in)) {
            if (!\is_array($in['details']) || ([] !== $in['details'] && array_is_list($in['details']))) {
                $unknown['details'] = 'Send the listing details as an object.';
            } else {
                $details = $in['details'] + $details;
            }
        }

        return [$details, $unknown];
    }
}
