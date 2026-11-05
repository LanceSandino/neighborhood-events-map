<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\CommunitySchema;

/**
 * @module Event/EventAccess
 * @description An event's own access (D-20261104-05): how visitors may reach the homes during this event, an optional
 *     `settings.access` with the same values as the community's. Unset, the event follows the community
 *     (Community/CommunityConfig::access); empty input means unset. Also the choices the organizer sees, in the words of
 *     the event's type.
 *
 * @dependencies Community/CommunitySchema (the allowed values), Event/EventOccurrence, Event/EventType
 */
final class EventAccess
{
    /** What the event says for itself; null when it follows the community. */
    public static function own(?EventOccurrence $event): ?string
    {
        $access = $event?->settings['access'] ?? null;

        return \in_array($access, CommunitySchema::ACCESS, true) ? $access : null;
    }

    /**
     * The organizer's input: one of the allowed values, or null for an empty choice ("Same as the community").
     *
     * @param array<string,string> $e field errors
     */
    public static function normalize(mixed $value, array &$e): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\in_array($value, CommunitySchema::ACCESS, true)) {
            $e['settings.access'] = 'Choose one of: '.implode(', ', CommunitySchema::ACCESS).', or leave it empty to follow the community.';

            return null;
        }

        return $value;
    }

    /**
     * The choices under Organizer → Events; the type words the "gates open" one. The empty choice is the console's.
     *
     * @return list<array{value:string, label:string}>
     */
    public static function options(EventType $type): array
    {
        return [['value' => 'public', 'label' => 'Open to the public'],
            ['value' => 'gated_open', 'label' => (string) ($type->copy()['admin_access_gated_open'] ?? 'Gated — gates open during the event')],
            ['value' => 'gated_guests', 'label' => 'Gated — residents and guests'],
            ['value' => 'private', 'label' => 'Residents only'],
            ['value' => 'unspecified', 'label' => 'Not stated']];
    }
}
