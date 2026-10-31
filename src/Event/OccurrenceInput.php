<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\EventBlockSchema;
use App\Shared\TextInput;
use App\Shared\ValidationException;

/**
 * @module Event/OccurrenceInput
 * @description Validates what an organizer sends for an event (Organizer → Events): name, first and last day (a
 *     one-day type has one day; others span at most 62 days), default hours, and the settings every type shares —
 *     rules (up to 8 short plain-text lines, shown with the event), route_modes (walking, plus the type's other
 *     profiles) and, for a type with several days, max_days (the organizer's cap of days per listing). Unknown
 *     fields are refused. Type-specific settings come with the types that use them.
 *
 * @dependencies Event/EventType, Event/EventOccurrence, Community/EventBlockSchema, Shared/TextInput, Shared/ValidationException
 */
final class OccurrenceInput
{
    public const FIELDS = ['type', 'name', 'starts_on', 'ends_on', 'default_start', 'default_end', 'settings'];
    public const RULES_MAX = 8;
    public const RULE_LENGTH = 160;

    /**
     * @param array<string,mixed> $in      the organizer's input
     * @param EventOccurrence|null $current the event being edited (its values fill what the input leaves out)
     *
     * @return array{type:string,name:string,starts_on:string,ends_on:string,default_start:string,default_end:string,settings:array<string,mixed>}
     *
     * @throws ValidationException with field errors
     */
    public static function normalize(array $in, EventType $type, ?EventOccurrence $current = null): array
    {
        $e = [];
        foreach (array_keys($in) as $k) {
            if (!\in_array($k, self::FIELDS, true)) {
                $e[(string) $k] = 'Unknown field.';
            }
        }
        if (null !== $current && \array_key_exists('type', $in) && $in['type'] !== $current->type) {
            $e['type'] = 'The type of an event never changes. Prepare a new event of the other type.';
        }
        $name = \array_key_exists('name', $in) ? TextInput::line($in['name'], 60) : ($current->name ?? '');
        if ('' === $name) {
            $name = $type->defaultName();
        }
        $startsOn = \is_string($in['starts_on'] ?? null) ? $in['starts_on'] : ($current->startsOn ?? '');
        $oneDay = 'single_day' === $type->scheduleKind();
        $endsOn = $oneDay ? $startsOn : (\is_string($in['ends_on'] ?? null) ? $in['ends_on'] : ($current->endsOn ?? $startsOn));
        if (!EventBlockSchema::isDate($startsOn)) {
            $e['starts_on'] = 'Choose the first day (YYYY-MM-DD).';
        } elseif (!EventBlockSchema::isDate($endsOn) || $endsOn < $startsOn) {
            $e['ends_on'] = 'Choose a last day on or after the first day.';
        } elseif (EventBlockSchema::days($startsOn, $endsOn) > EventBlockSchema::MAX_RANGE_DAYS) {
            $e['ends_on'] = sprintf('An event spans at most %d days.', EventBlockSchema::MAX_RANGE_DAYS);
        }
        $start = EventBlockSchema::time($in['default_start'] ?? ($current->defaultStart ?? '17:00'));
        $end = EventBlockSchema::time($in['default_end'] ?? ($current->defaultEnd ?? '21:00'));
        if (null === $start) {
            $e['default_start'] = 'Use a time like 17:00.';
        } elseif (null === $end || $end <= $start) {
            $e['default_end'] = 'The end must be a time after the start, on the same day.';
        }
        $settings = \array_key_exists('settings', $in) ? self::settings($in['settings'], $type, $e) : ($current->settings ?? []);
        if ([] !== $e) {
            throw new ValidationException($e);
        }

        return ['type' => $type->key(), 'name' => $name, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'default_start' => (string) $start,
            'default_end' => (string) $end, 'settings' => $settings];
    }

    /**
     * @param array<string,string> $e
     *
     * @return array<string,mixed>
     */
    private static function settings(mixed $raw, EventType $type, array &$e): array
    {
        if (!\is_array($raw) || ([] !== $raw && array_is_list($raw))) {
            $e['settings'] = 'Send the settings as an object.';

            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            match ((string) $k) {
                'rules' => $out['rules'] = self::rules($v, $e),
                'route_modes' => $out['route_modes'] = self::routeModes($v, $type, $e),
                'max_days' => $out['max_days'] = self::maxDays($v, $type, $e),
                default => $e['settings.'.$k] = 'Unknown field.',
            };
        }

        return $out;
    }

    /**
     * @param array<string,string> $e
     *
     * @return list<string>
     */
    private static function rules(mixed $v, array &$e): array
    {
        $lines = [];
        foreach (\is_array($v) ? $v : [] as $line) {
            $text = TextInput::line($line, 4 * self::RULE_LENGTH);
            if (mb_strlen($text) > self::RULE_LENGTH) {
                $e['settings.rules'] = sprintf('Keep each rule to %d characters.', self::RULE_LENGTH);
            } elseif ('' !== $text) {
                $lines[] = $text;
            }
        }
        if (\count($lines) > self::RULES_MAX) {
            $e['settings.rules'] = sprintf('Up to %d rules.', self::RULES_MAX);
        }

        return $lines;
    }

    /**
     * @param array<string,string> $e
     *
     * @return list<string>
     */
    private static function routeModes(mixed $v, EventType $type, array &$e): array
    {
        $modes = array_values(array_unique(array_map('strval', \is_array($v) ? $v : [])));
        if (!\in_array('walking', $modes, true) || [] !== array_diff($modes, $type->routeProfiles())) {
            $e['settings.route_modes'] = sprintf('Choose from %s; walking is always offered.', implode(', ', $type->routeProfiles()));
        }

        return $modes;
    }

    /** @param array<string,string> $e */
    private static function maxDays(mixed $v, EventType $type, array &$e): int
    {
        $n = \is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
        if ('single_day' === $type->scheduleKind() || $n < 1 || $n > $type->maxDays()) {
            $e['settings.max_days'] = 'single_day' === $type->scheduleKind() ? 'A one-day event has one day per listing.' : sprintf('Choose 1 to %d days.', $type->maxDays());
        }

        return $n;
    }
}
