<?php

declare(strict_types=1);

namespace App\Event\Type;

use App\Community\EventBlockSchema;
use App\Event\EventOccurrence;
use App\Event\EventSettingsType;
use App\Event\EventType;
use App\Event\OccurrenceSettings;
use App\Shared\TextInput;
use App\Shared\ValidationException;

/**
 * @module Event/Type/HolidayLightsType
 * @description Christmas lights, a season of nights (Dec–Jan ranges up to 62 nights): each home picks its nights and
 *     hours. Details are display styles (at least one), a self-reported flashing warning, music with an optional FM
 *     station (a frequency from 87.5 to 108.0, never free text, only with music), the viewing mode (drive-by only or
 *     walk-up welcome) and treats or cocoa; unknown keys are refused. Statuses: lights on, off tonight (day-scoped:
 *     only on the local date it was set) and done for the season. Walking or driving routes. Event settings of its
 *     own: the best night for a neighborhood walk and a quiet-hours note. Words live in HolidayLightsCopy.
 *
 * @dependencies Event/EventType, Event/EventSettingsType, Event/EventOccurrence, Event/OccurrenceSettings, Event/Type/HolidayLightsCopy, Community/EventBlockSchema,
 *     Shared/TextInput, Shared/ValidationException
 */
final class HolidayLightsType implements EventType, EventSettingsType
{
    public const KEY = 'holiday_lights';
    public const VIEWING_MODES = ['drive_by_only', 'walk_up_welcome'];
    public const BOOLEANS = ['flashing_warning', 'music_enabled', 'treats', 'cocoa'];
    /** The FM broadcast band in MHz; a station is one decimal, e.g. 88.7. */
    public const FM_MIN = 87.5;
    public const FM_MAX = 108.0;
    public const QUIET_HOURS_MAX = 120;
    /** Canonical details of a new listing (drive-by only is the cautious default: please do not approach). */
    private const DEFAULTS = ['styles' => [], 'flashing_warning' => false, 'music_enabled' => false, 'fm_frequency' => null,
        'viewing_mode' => 'drive_by_only', 'treats' => false, 'cocoa' => false];

    public function key(): string
    {
        return self::KEY;
    }

    public function defaultName(): string
    {
        return 'Christmas Lights';
    }

    public function defaultBranding(): array
    {
        return HolidayLightsCopy::BRANDING;
    }

    public function copy(): array
    {
        return HolidayLightsCopy::TEXT + ['reminders' => HolidayLightsCopy::REMINDERS];
    }

    public function scheduleKind(): string
    {
        return 'nightly_range';
    }

    public function maxDays(): int
    {
        return 62;
    }

    public function hourChoices(): array
    {
        return ['start' => [15, 22], 'end' => [16, 23], 'visit' => [16, 23]];
    }

    public function statuses(): array
    {
        return [
            'active' => ['label' => 'Lights on', 'icon' => '✨', 'day_scoped' => false],
            'off_tonight' => ['label' => 'Off tonight', 'icon' => '🌙', 'day_scoped' => true],
            'finished' => ['label' => 'Done for the season', 'icon' => '✅', 'day_scoped' => false],
        ];
    }

    public function displayRules(): array
    {
        return [
            'statuses' => [
                'active' => ['day_scoped' => false, 'display' => null],
                'off_tonight' => ['day_scoped' => true, 'display' => 'off_tonight'],
                'finished' => ['day_scoped' => false, 'display' => 'finished'],
            ],
            'phases' => ['inside' => 'lights_on', 'later_today' => 'later_tonight', 'ended_today' => 'not_tonight', 'not_today' => 'not_tonight'],
        ];
    }

    public function features(): array
    {
        return [];
    }

    public function defaultDetails(): array
    {
        return self::DEFAULTS;
    }

    public function normalizeDetails(array $input, OccurrenceSettings $settings): array
    {
        $errors = [];
        $out = self::DEFAULTS;
        foreach (self::BOOLEANS as $f) {
            if (\array_key_exists($f, $input)) {
                $out[$f] = filter_var($input[$f], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE) ?? false;
            }
        }
        if (\array_key_exists('styles', $input)) {
            $raw = $input['styles'];
            $known = \is_array($raw) && array_is_list($raw) && [] === array_filter($raw, static fn ($s) => !\is_string($s) || !isset(HolidayLightsCopy::STYLES[$s]));
            if ($known) {
                $out['styles'] = array_values(array_intersect(array_keys(HolidayLightsCopy::STYLES), $raw));
            } else {
                $errors['styles'] = 'Choose display styles from: '.implode(', ', array_keys(HolidayLightsCopy::STYLES)).'.';
            }
        }
        if ([] === $out['styles'] && !isset($errors['styles'])) {
            $errors['styles'] = 'Choose at least one display style.';
        }
        if (\array_key_exists('viewing_mode', $input)) {
            if (\is_string($input['viewing_mode']) && \in_array($input['viewing_mode'], self::VIEWING_MODES, true)) {
                $out['viewing_mode'] = $input['viewing_mode'];
            } else {
                $errors['viewing_mode'] = 'Choose drive-by only or walk-up welcome.';
            }
        }
        if (\array_key_exists('fm_frequency', $input)) {
            [$fm, $ok] = self::frequency($input['fm_frequency']);
            if ($ok) {
                $out['fm_frequency'] = $fm;
            } else {
                $errors['fm_frequency'] = sprintf('Use an FM station like 88.7 (%.1f to %.1f).', self::FM_MIN, self::FM_MAX);
            }
        }
        if (null !== $out['fm_frequency'] && !$out['music_enabled'] && !isset($errors['fm_frequency'])) {
            $errors['fm_frequency'] = 'Turn on music to add its radio station, or leave the station empty.';
        }
        foreach (array_keys($input) as $k) {
            if (!\array_key_exists((string) $k, self::DEFAULTS)) {
                $errors['details.'.$k] = 'Unknown field.';
            }
        }
        if ([] !== $errors) {
            throw new ValidationException($errors);
        }

        return $out;
    }

    public function publicDetails(array $details): array
    {
        $styles = \is_array($details['styles'] ?? null) ? array_values(array_intersect(array_keys(HolidayLightsCopy::STYLES), $details['styles'])) : [];
        $music = (bool) ($details['music_enabled'] ?? false);
        [$fm, $ok] = self::frequency($details['fm_frequency'] ?? null);
        $mode = $details['viewing_mode'] ?? null;

        return [
            'styles' => $styles,
            'flashing_warning' => (bool) ($details['flashing_warning'] ?? false),
            'music_enabled' => $music,
            'fm_frequency' => $music && $ok ? $fm : null,
            'viewing_mode' => \is_string($mode) && \in_array($mode, self::VIEWING_MODES, true) ? $mode : self::DEFAULTS['viewing_mode'],
            'treats' => (bool) ($details['treats'] ?? false),
            'cocoa' => (bool) ($details['cocoa'] ?? false),
        ];
    }

    /** No older client or legacy column knows this type's details. */
    public function legacyFields(array $details): array
    {
        return [];
    }

    public function filters(): array
    {
        return [
            ['key' => 'driveBy', 'label' => 'Drive-by', 'icon' => '🚗', 'quick' => true, 'feature' => null, 'title' => 'Drive-by homes: enjoy them from the street'],
            ['key' => 'noFlashing', 'label' => 'No flashing reported', 'icon' => '🕯️', 'quick' => true, 'feature' => null, 'title' => 'No flashing reported (self-reported by residents)'],
            ['key' => 'music', 'label' => 'Music', 'icon' => '🎵', 'quick' => true, 'feature' => null],
            ['key' => 'walkUp', 'label' => 'Walk-up welcome', 'icon' => '🚶', 'quick' => false, 'feature' => null],
            ['key' => 'openTonight', 'label' => 'Open tonight', 'icon' => '🌙', 'quick' => false, 'feature' => null, 'title' => 'Lights on now or later tonight'],
        ];
    }

    public function legend(): array
    {
        return [
            ['cls' => 'lights', 'icon' => '✨', 'label' => 'Lights', 'feature' => null],
            ['cls' => 'music', 'icon' => '🎵', 'label' => 'Music', 'feature' => null],
            ['cls' => 'inflatable', 'icon' => '⛄', 'label' => 'Inflatables', 'feature' => null],
            ['cls' => 'walkthrough', 'icon' => '🎄', 'label' => 'Walk-through', 'feature' => null],
        ];
    }

    public function stats(array $homes): array
    {
        $d = static fn (array $h): array => \is_array($h['details'] ?? null) ? $h['details'] : $h;

        return [
            'music' => \count(array_filter($homes, static fn (array $h) => (bool) ($d($h)['music_enabled'] ?? false))),
            'drive_by' => \count(array_filter($homes, static fn (array $h) => 'drive_by_only' === ($d($h)['viewing_mode'] ?? null))),
            'no_flashing' => \count(array_filter($homes, static fn (array $h) => !($d($h)['flashing_warning'] ?? false))),
        ];
    }

    public function routeProfiles(): array
    {
        return ['walking', 'driving'];
    }

    public function fragments(): array
    {
        $names = ['head', 'hero_art', 'schedule_row', 'filter_controls', 'card_symbol', 'card_tags', 'confirm_tags', 'form_hours', 'form_fields', 'manage_hours',
            'manage_half', 'manage_full', 'listing_checks', 'route_rules', 'rule_lines'];

        return array_combine($names, array_map(static fn (string $n) => 'event/'.self::KEY.'/_'.$n.'.html.twig', $names));
    }

    public function settingKeys(): array
    {
        return ['best_night', 'quiet_hours'];
    }

    public function normalizeSetting(string $key, mixed $value, string $startsOn, string $endsOn): array
    {
        if ('best_night' === $key) {
            if (null === $value || '' === $value) {
                return [null, null];
            }
            $ok = \is_string($value) && EventBlockSchema::isDate($value) && $value >= $startsOn && $value <= $endsOn;

            return $ok ? [$value, null] : [null, sprintf('Choose a best night from %s to %s, or leave it empty.', $startsOn, $endsOn)];
        }
        if ('quiet_hours' === $key) {
            $text = TextInput::line($value, 4 * self::QUIET_HOURS_MAX);

            return mb_strlen($text) > self::QUIET_HOURS_MAX ? ['', sprintf('Keep the quiet-hours note to %d characters.', self::QUIET_HOURS_MAX)] : [$text, null];
        }

        return [null, 'Unknown field.'];
    }

    /** The best night and quiet hours stand on their own: nothing to complete. */
    public function finishSettings(array $settings, string $startsOn, string $endsOn): array
    {
        return [$settings, []];
    }

    public function settingOptions(): array
    {
        return [];
    }

    /** A home picks any night of the season. */
    public function listingDates(EventOccurrence $event): ?array
    {
        return null;
    }

    /** Every lights filter is offered whatever the event's settings. */
    public function offersFilter(string $filterKey, array $settings): bool
    {
        return true;
    }

    public function publicSettings(array $settings): array
    {
        $best = $settings['best_night'] ?? null;

        return ['best_night' => \is_string($best) && EventBlockSchema::isDate($best) ? $best : null,
            'quiet_hours' => \is_string($settings['quiet_hours'] ?? null) ? $settings['quiet_hours'] : ''];
    }

    /**
     * An FM station as a canonical one-decimal string ("88.7"), null for none.
     *
     * @return array{0: ?string, 1: bool} the station and whether the value was acceptable
     */
    public static function frequency(mixed $value): array
    {
        if (null === $value || '' === $value) {
            return [null, true];
        }
        if (\is_int($value) || \is_float($value)) {
            $value = is_finite((float) $value) ? sprintf('%.1f', $value) : '';
        }
        if (!\is_string($value) || !preg_match('~^\d{2,3}\.\d$~', $value)) {
            return [null, false];
        }
        $mhz = (float) $value;
        if ($mhz < self::FM_MIN || $mhz > self::FM_MAX) {
            return [null, false];
        }

        return [sprintf('%.1f', $mhz), true];
    }
}
