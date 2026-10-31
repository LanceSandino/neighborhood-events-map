<?php

declare(strict_types=1);

namespace App\Event;

/**
 * @module Event/EventSettingsType
 * @description An event type with settings of its own on each event (event_occurrences.settings), next to the
 *     settings every type shares (rules, route_modes, max_days; Event/OccurrenceInput). The type validates its own
 *     keys strictly and says which of them the public event shows (GET /api/event). Halloween has none, so its
 *     public event keeps exactly its keys.
 *
 * @dependencies none
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
     * The public part of an event's settings, added to the public event after its shared keys.
     *
     * @param array<string,mixed> $settings validated settings
     *
     * @return array<string,mixed>
     */
    public function publicSettings(array $settings): array;
}
