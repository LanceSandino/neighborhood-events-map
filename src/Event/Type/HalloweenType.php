<?php

declare(strict_types=1);

namespace App\Event\Type;

use App\Event\EventType;
use App\Event\OccurrenceSettings;
use App\Shared\ValidationException;

/**
 * @module Event/Type/HalloweenType
 * @description Halloween trick-or-treating, the first event type: one evening; listing details are offerings
 *     (candy, non-food treats, 21+ refreshments), sensory details, scare level and stroller access; statuses
 *     handing out, paused, out of candy and done. Its details keys are the legacy listings columns, so its
 *     public homes keep their flat keys (D-20261031-03). Copy lives in HalloweenCopy.
 *
 * @dependencies Event/EventType, Event/OccurrenceSettings, Event/Type/HalloweenCopy, Shared/ValidationException
 */
final class HalloweenType implements EventType
{
    public const KEY = 'halloween';
    public const SCARE_LEVELS = ['gentle', 'mild', 'intense'];
    /** Yes/no details in canonical order (scare_level sits between loud_audio and stroller_friendly). */
    public const BOOLEANS = ['candy', 'nonfood', 'adult_drinks', 'low_noise', 'jump_scares', 'flashing_lights', 'loud_audio', 'stroller_friendly'];
    /** Canonical details of a new listing: the order of the public API and of the legacy columns. */
    private const DEFAULTS = [
        'candy' => false, 'nonfood' => false, 'adult_drinks' => false, 'low_noise' => false, 'jump_scares' => false,
        'flashing_lights' => false, 'loud_audio' => false, 'scare_level' => 'gentle', 'stroller_friendly' => false,
    ];
    /** An offering the community switched off is forced off: detail => community feature. */
    private const OFFERING_FEATURES = ['candy' => 'candy', 'nonfood' => 'nonfood_treats', 'adult_drinks' => 'adult_refreshments'];

    public function key(): string
    {
        return self::KEY;
    }

    public function defaultName(): string
    {
        return 'Halloween';
    }

    public function defaultBranding(): array
    {
        return HalloweenCopy::BRANDING;
    }

    public function copy(): array
    {
        return HalloweenCopy::TEXT + ['reminders' => HalloweenCopy::REMINDERS];
    }

    public function scheduleKind(): string
    {
        return 'single_day';
    }

    public function maxDays(): int
    {
        return 1;
    }

    public function hourChoices(): array
    {
        return ['start' => [15, 22], 'end' => [15, 23], 'visit' => [15, 22]];
    }

    public function statuses(): array
    {
        return [
            'active' => ['label' => 'Handing out', 'icon' => '🎃', 'day_scoped' => false, 'register_label' => 'Handing out'],
            'paused' => ['label' => 'Paused', 'icon' => '⏸️', 'day_scoped' => false, 'register_label' => 'Paused for now'],
            'out_of_candy' => ['label' => 'Out of candy', 'icon' => '🍬', 'day_scoped' => false],
            'done' => ['label' => 'Done for tonight', 'icon' => '✅', 'day_scoped' => false],
        ];
    }

    /** The 0.9 status words of the map (events/halloween.js statusOf): open, later, done, paused, out. */
    public function displayRules(): array
    {
        return [
            'statuses' => [
                'active' => ['day_scoped' => false, 'display' => null],
                'paused' => ['day_scoped' => false, 'display' => 'paused'],
                'out_of_candy' => ['day_scoped' => false, 'display' => 'out'],
                'done' => ['day_scoped' => false, 'display' => 'done'],
            ],
            'phases' => ['inside' => 'open', 'later_today' => 'later', 'ended_today' => 'done', 'not_today' => 'later'],
        ];
    }

    public function features(): array
    {
        return [
            'candy' => ['label' => 'Candy', 'default' => true],
            'nonfood_treats' => ['label' => 'Non-food treats', 'default' => true],
            'adult_refreshments' => ['label' => 'Adult refreshments (21+)', 'default' => true],
            'sensory_filters' => ['label' => 'Sensory filters', 'default' => true],
        ];
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
        foreach (self::OFFERING_FEATURES as $detail => $feature) {
            if (!$settings->feature($feature)) {
                $out[$detail] = false;
            }
        }
        if (!$out['candy'] && !$out['nonfood'] && !$out['adult_drinks']) {
            $errors['offerings'] = 'Choose at least one: candy, non-food treats or adult refreshments.';
        }
        if (\array_key_exists('scare_level', $input)) {
            if (!\in_array($input['scare_level'], self::SCARE_LEVELS, true)) {
                $errors['scare_level'] = 'Choose gentle, mild or intense.';
            } else {
                $out['scare_level'] = $input['scare_level'];
            }
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
        $out = [];
        foreach (self::DEFAULTS as $k => $default) {
            $v = $details[$k] ?? $default;
            $out[$k] = 'scare_level' === $k ? (\in_array($v, self::SCARE_LEVELS, true) ? $v : $default) : (bool) $v;
        }

        return $out;
    }

    public function legacyFields(array $details): array
    {
        return $this->publicDetails($details);
    }

    public function filters(): array
    {
        return [
            ['key' => 'candy', 'label' => 'Candy', 'icon' => '🍬', 'quick' => true, 'feature' => 'candy', 'id' => 'btnCandy'],
            ['key' => 'lowSensory', 'label' => 'Sensory-friendly', 'icon' => '🧩', 'quick' => true, 'feature' => 'sensory_filters', 'id' => 'btnQuiet'],
            ['key' => 'adult', 'label' => '21+ refreshments', 'icon' => '🍺', 'quick' => true, 'feature' => 'adult_refreshments'],
            ['key' => 'noScares', 'label' => 'No big scares', 'icon' => '😱', 'quick' => false, 'feature' => 'sensory_filters', 'title' => 'No jump scares or intense setups'],
            ['key' => 'noFlashing', 'label' => 'No flashing lights', 'icon' => '🔦', 'quick' => false, 'feature' => 'sensory_filters'],
            ['key' => 'noLoud', 'label' => 'No loud audio', 'icon' => '🔊', 'quick' => false, 'feature' => 'sensory_filters'],
            ['key' => 'nonfood', 'label' => 'Non-food treats', 'icon' => '🎃', 'quick' => false, 'feature' => 'nonfood_treats', 'icon_class' => 'teal-pumpkin'],
            ['key' => 'stroller', 'label' => 'Kid-friendly', 'icon' => '👶', 'quick' => false, 'feature' => null],
        ];
    }

    public function legend(): array
    {
        return [
            ['cls' => 'candy', 'icon' => '🍬', 'label' => 'Treats', 'feature' => null],
            ['cls' => 'gentle', 'icon' => '🧩', 'label' => 'Sensory-friendly', 'feature' => null],
            ['cls' => 'spooky', 'icon' => '👻', 'label' => 'Spooky', 'feature' => null],
            ['cls' => 'adult', 'icon' => '🍺', 'label' => '21+', 'feature' => 'adult_refreshments'],
        ];
    }

    public function stats(array $homes): array
    {
        $d = static fn (array $h): array => $h['details'] ?? $h; // a public home carries its details flat (D-20261031-04)
        $sensory = static fn (array $x): bool => $x['low_noise'] && !$x['flashing_lights'] && !$x['jump_scares'] && !$x['loud_audio'] && 'intense' !== $x['scare_level'];

        return [
            'candy' => \count(array_filter($homes, static fn (array $h) => $d($h)['candy'])),
            'low_sensory' => \count(array_filter($homes, static fn (array $h) => $sensory($d($h)))),
            'adult' => \count(array_filter($homes, static fn (array $h) => $d($h)['adult_drinks'])),
        ];
    }

    public function routeProfiles(): array
    {
        return ['walking'];
    }

    public function fragments(): array
    {
        $names = ['hero_art', 'card_symbol', 'card_tags', 'confirm_tags', 'form_fields', 'manage_half', 'manage_full', 'listing_checks'];

        return array_combine($names, array_map(static fn (string $n) => 'event/'.self::KEY.'/_'.$n.'.html.twig', $names));
    }
}
