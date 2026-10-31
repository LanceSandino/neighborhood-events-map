<?php

declare(strict_types=1);

namespace App\Event\Type;

use App\Community\EventBlockSchema;
use App\Event\EventOccurrence;
use App\Event\EventSettingsType;
use App\Event\EventType;
use App\Event\ListingTextCheck;
use App\Event\OccurrenceSettings;
use App\Shared\ContactDetails;
use App\Shared\ValidationException;

/**
 * @module Event/Type/GarageSaleType
 * @description Rummage and garage sales: one to three sale days chosen by the organizer (event settings `days`), each
 *     home picking its days and its own hours on each. Details are item categories (at least one, from a fixed list
 *     and only those the sale allows), multi-family, early birds welcome and payment kinds (cash, card, apps: the
 *     kind only); unknown keys are refused. The note is the shared listing note, refused when it carries a phone
 *     number, email, payment handle or web address (Shared/ContactDetails), so it is never stored. Statuses: open,
 *     paused and sold out (both day-scoped: only on the local date they were set) and closed; "sold out" never shows
 *     as open. Walking or driving routes. The public name defaults to "Garage Sale" (an organizer may call it
 *     "Community Rummage Sale"). Words live in GarageSaleCopy.
 *
 * @dependencies Event/EventType, Event/EventSettingsType, Event/ListingTextCheck, Event/EventOccurrence, Event/OccurrenceSettings,
 *     Event/Type/GarageSaleCopy, Community/EventBlockSchema, Shared/ContactDetails, Shared/ValidationException
 */
final class GarageSaleType implements EventType, EventSettingsType, ListingTextCheck
{
    public const KEY = 'garage_sale';
    public const CATEGORIES = ['kids_baby', 'toys', 'furniture', 'clothing', 'tools', 'books_media', 'electronics', 'sports', 'household', 'free'];
    public const PAYMENT_TYPES = ['cash', 'card', 'apps'];
    public const BOOLEANS = ['multi_family', 'early_birds_allowed'];
    public const MAX_DAYS = 3;
    /** Canonical details of a new listing. */
    private const DEFAULTS = ['categories' => [], 'multi_family' => false, 'early_birds_allowed' => false, 'payment_types' => []];

    public function key(): string
    {
        return self::KEY;
    }

    public function defaultName(): string
    {
        return 'Garage Sale';
    }

    public function defaultBranding(): array
    {
        return GarageSaleCopy::BRANDING;
    }

    public function copy(): array
    {
        return GarageSaleCopy::TEXT + ['reminders' => GarageSaleCopy::REMINDERS];
    }

    public function scheduleKind(): string
    {
        return 'sale_days';
    }

    public function maxDays(): int
    {
        return self::MAX_DAYS;
    }

    public function hourChoices(): array
    {
        return ['start' => [6, 17], 'end' => [7, 20], 'visit' => [6, 19]];
    }

    public function statuses(): array
    {
        return [
            'active' => ['label' => 'Open', 'icon' => '🏷️', 'day_scoped' => false],
            'paused' => ['label' => 'Paused today', 'icon' => '⏸️', 'day_scoped' => true],
            'sold_out' => ['label' => 'Sold out today', 'icon' => '📦', 'day_scoped' => true],
            'closed' => ['label' => 'Closed', 'icon' => '🔒', 'day_scoped' => false],
        ];
    }

    public function displayRules(): array
    {
        return [
            'statuses' => [
                'active' => ['day_scoped' => false, 'display' => null],
                'paused' => ['day_scoped' => true, 'display' => 'paused'],
                'sold_out' => ['day_scoped' => true, 'display' => 'sold_out'],
                'closed' => ['day_scoped' => false, 'display' => 'closed'],
            ],
            'phases' => ['inside' => 'open', 'later_today' => 'opens_at', 'ended_today' => 'not_today', 'not_today' => 'not_today'],
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
        if (\array_key_exists('categories', $input)) {
            $chosen = self::subset($input['categories'], self::CATEGORIES);
            $allowed = self::allowedCategories($settings->settings);
            if (null === $chosen) {
                $errors['categories'] = 'Choose categories from: '.implode(', ', self::CATEGORIES).'.';
            } elseif ([] !== array_diff($chosen, $allowed)) {
                $errors['categories'] = sprintf('This sale lists %s; choose from those.', self::listing(array_map(static fn (string $c) => GarageSaleCopy::CATEGORIES[$c][0], $allowed)));
            } else {
                $out['categories'] = $chosen;
            }
        }
        if ([] === $out['categories'] && !isset($errors['categories'])) {
            $errors['categories'] = 'Choose at least one category.';
        }
        if (\array_key_exists('payment_types', $input)) {
            $kinds = self::subset($input['payment_types'], self::PAYMENT_TYPES);
            if (null === $kinds) {
                $errors['payment_types'] = 'Choose payment kinds from cash, card and apps (the kind only, never a username or phone number).';
            } else {
                $out['payment_types'] = $kinds;
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
        $list = static fn (mixed $v, array $known): array => \is_array($v) ? array_values(array_intersect($known, $v)) : [];

        return [
            'categories' => $list($details['categories'] ?? null, self::CATEGORIES),
            'multi_family' => (bool) ($details['multi_family'] ?? false),
            'early_birds_allowed' => (bool) ($details['early_birds_allowed'] ?? false),
            'payment_types' => $list($details['payment_types'] ?? null, self::PAYMENT_TYPES),
        ];
    }

    /** No older client or legacy column knows this type's details. */
    public function legacyFields(array $details): array
    {
        return [];
    }

    /** A note with a phone number, email, payment handle or web address is refused (and so never stored). */
    public function textProblem(string $field, string $text): ?string
    {
        $kind = ContactDetails::find($text);

        return null === $kind ? null : GarageSaleCopy::CONTACT_ERRORS[$kind];
    }

    /** The "open on <day>" toggles are drawn per sale day by the filter_controls fragment (days differ per event). */
    public function filters(): array
    {
        return [
            ['key' => 'kidsToys', 'label' => 'Kids & toys', 'icon' => '🧸', 'quick' => true, 'feature' => null, 'title' => 'Kids & baby or toys'],
            ['key' => 'furniture', 'label' => 'Furniture', 'icon' => '🪑', 'quick' => true, 'feature' => null],
            ['key' => 'tools', 'label' => 'Tools', 'icon' => '🛠️', 'quick' => true, 'feature' => null],
            ['key' => 'household', 'label' => 'Household', 'icon' => '🧺', 'quick' => false, 'feature' => null],
            ['key' => 'electronics', 'label' => 'Electronics', 'icon' => '🔌', 'quick' => false, 'feature' => null],
            ['key' => 'free', 'label' => 'Free items', 'icon' => '🆓', 'quick' => false, 'feature' => null],
            ['key' => 'openNow', 'label' => 'Open now', 'icon' => '⏰', 'quick' => false, 'feature' => null, 'title' => 'Open right now, by each home’s own hours today'],
            ['key' => 'multiFamily', 'label' => 'Multi-family', 'icon' => '🏘️', 'quick' => false, 'feature' => null],
            ['key' => 'earlyBirds', 'label' => 'Early birds welcome', 'icon' => '🐦', 'quick' => false, 'feature' => null],
        ];
    }

    public function legend(): array
    {
        return [
            ['cls' => 'sale', 'icon' => '🏷️', 'label' => 'Open or opening today', 'feature' => null],
            ['cls' => 'closed', 'icon' => '⏸️', 'label' => 'Not open now', 'feature' => null],
        ];
    }

    public function stats(array $homes): array
    {
        $d = static fn (array $h): array => \is_array($h['details'] ?? null) ? $h['details'] : $h;

        return [
            'multi_family' => \count(array_filter($homes, static fn (array $h) => (bool) ($d($h)['multi_family'] ?? false))),
            'early_birds' => \count(array_filter($homes, static fn (array $h) => (bool) ($d($h)['early_birds_allowed'] ?? false))),
            'free' => \count(array_filter($homes, static fn (array $h) => \in_array('free', (array) ($d($h)['categories'] ?? []), true))),
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
        return ['days', 'categories', 'early_bird_time'];
    }

    public function normalizeSetting(string $key, mixed $value, string $startsOn, string $endsOn): array
    {
        return match ($key) {
            'days' => self::days($value, $startsOn, $endsOn),
            'categories' => null === ($c = self::subset($value, self::CATEGORIES)) || [] === $c
                ? [[], 'Choose at least one category from the list.'] : [$c, null],
            'early_bird_time' => null === $value || '' === $value ? [null, null]
                : (null !== ($t = self::hhmm($value)) ? [$t, null] : [null, 'Use a time like 07:00 for early birds, or leave it empty.']),
            default => [null, 'Unknown field.'],
        };
    }

    /** A sale names its days: an event of up to three days takes them all; a longer span must choose (1 to 3). */
    public function finishSettings(array $settings, string $startsOn, string $endsOn): array
    {
        $days = $settings['days'] ?? null;
        if (null === $days && EventBlockSchema::days($startsOn, $endsOn) <= self::MAX_DAYS) {
            $all = [];
            for ($d = new \DateTimeImmutable($startsOn); $d->format('Y-m-d') <= $endsOn; $d = $d->modify('+1 day')) {
                $all[] = $d->format('Y-m-d');
            }

            return [['days' => $all] + $settings, []];
        }
        $inside = \is_array($days) && [] !== $days && [] === array_filter($days, static fn ($d) => !\is_string($d) || $d < $startsOn || $d > $endsOn);

        return [$settings, $inside ? [] : ['settings.days' => 'Choose the sale days (1 to 3) inside the event.']];
    }

    public function settingOptions(): array
    {
        return ['categories' => array_map(static fn (array $c) => $c[0], GarageSaleCopy::CATEGORIES)];
    }

    /** The sale days; without them (an event of up to three days) every day of the event. */
    public function listingDates(EventOccurrence $event): array
    {
        $days = $event->settings['days'] ?? null;
        if (\is_array($days) && [] !== $days) {
            return array_values(array_filter($days, static fn ($d) => \is_string($d) && $event->contains($d)));
        }
        $all = $event->dates();

        return \count($all) <= self::MAX_DAYS ? $all : [];
    }

    public function publicSettings(array $settings): array
    {
        $days = \is_array($settings['days'] ?? null) ? array_values(array_filter($settings['days'], static fn ($d) => \is_string($d) && EventBlockSchema::isDate($d))) : [];

        return ['days' => $days, 'categories' => self::allowedCategories($settings),
            'early_bird_time' => \is_string($settings['early_bird_time'] ?? null) ? self::hhmm($settings['early_bird_time']) : null];
    }

    /**
     * @param array<string,mixed> $settings
     *
     * @return list<string> the categories the sale allows (all of them unless the organizer chose some)
     */
    private static function allowedCategories(array $settings): array
    {
        $chosen = self::subset($settings['categories'] ?? null, self::CATEGORIES);

        return null === $chosen || [] === $chosen ? self::CATEGORIES : $chosen;
    }

    /**
     * A list of known values in canonical order, or null for anything else.
     *
     * @param list<string> $known
     *
     * @return list<string>|null
     */
    private static function subset(mixed $value, array $known): ?array
    {
        if (!\is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn ($v) => !\is_string($v) || !\in_array($v, $known, true))) {
            return null;
        }

        return array_values(array_intersect($known, $value));
    }

    /** @return array{0: list<string>, 1: ?string} */
    private static function days(mixed $value, string $startsOn, string $endsOn): array
    {
        $error = sprintf('Choose 1 to %d sale days from %s to %s.', self::MAX_DAYS, $startsOn, $endsOn);
        if (!\is_array($value) || !array_is_list($value) || [] === $value) {
            return [[], $error];
        }
        $days = array_values(array_unique(array_filter($value, static fn ($d) => \is_string($d) && EventBlockSchema::isDate($d) && $d >= $startsOn && $d <= $endsOn)));
        sort($days);
        $wellFormed = \count($days) === \count(array_unique(array_map(static fn ($d) => \is_scalar($d) ? (string) $d : '', $value)));

        return $wellFormed && \count($days) <= self::MAX_DAYS ? [$days, null] : [[], $error];
    }

    private static function hhmm(mixed $value): ?string
    {
        return \is_string($value) && preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', $value) ? $value : null;
    }

    /** @param list<string> $words "A, B and C" */
    private static function listing(array $words): string
    {
        $last = array_pop($words);

        return [] === $words ? (string) $last : implode(', ', $words).' and '.$last;
    }
}
