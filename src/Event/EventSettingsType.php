<?php

declare(strict_types=1);

namespace App\Event;

/**
 * @module Event/EventSettingsType
 * @description An event type with settings of its own on each event (event_occurrences.settings), next to the
 *     settings every type shares (rules, route_modes, max_days; Event/OccurrenceInput). The type validates its own
 *     keys strictly, completes and checks them as a whole against the event's dates, says which of them the public
 *     event shows (GET /api/event), which choices the organizer console offers for them, on which days a listing
 *     may take part (a garage sale's own sale days; null: every day of the event) and which of the type's filters an
 *     event offers (a sale's category filters only for the categories it allows). Halloween has none, so its public
 *     event keeps exactly its keys and filters.
 *
 * @dependencies Event/EventOccurrence
 */
interface EventSettingsType
{
    /** @return list<string> the type's own setting keys */
    public function settingKeys(): array;

    /**
     * One of the type's settings, checked against the event's dates.
     *
     * @return array{0: mixed, 1: ?string} the normalized value and an error message (null when valid)
     */
    public function normalizeSetting(string $key, mixed $value, string $startsOn, string $endsOn): array;

    /**
     * The type's settings completed and checked as a whole once each key is valid (e.g. a sale's days default to the
     * event's days when it has at most three, and must lie inside the event).
     *
     * @param array<string,mixed> $settings the event's settings, each key already normalized
     *
     * @return array{0: array<string,mixed>, 1: array<string,string>} the completed settings and errors by field ("settings.<key>")
     */
    public function finishSettings(array $settings, string $startsOn, string $endsOn): array;

    /**
     * Choices the organizer console offers for a setting (e.g. a sale's categories), as value => label.
     *
     * @return array<string, array<string,string>>
     */
    public function settingOptions(): array;

    /**
     * The local dates a listing of this event may take part in, or null for every day of the event.
     *
     * @return list<string>|null
     */
    public function listingDates(EventOccurrence $event): ?array;

    /**
     * Whether an event with these settings offers one of the type's filters (EventType::filters()); a filter it does
     * not offer is neither drawn nor named in the public event.
     *
     * @param array<string,mixed> $settings validated settings
     */
    public function offersFilter(string $filterKey, array $settings): bool;

    /**
     * The public part of an event's settings, added to the public event after its shared keys.
     *
     * @param array<string,mixed> $settings validated settings
     *
     * @return array<string,mixed>
     */
    public function publicSettings(array $settings): array;
}
