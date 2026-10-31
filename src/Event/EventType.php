<?php

declare(strict_types=1);

namespace App\Event;

use App\Shared\ValidationException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * @module Event/EventType
 * @description The contract of a code-defined event type (ADR 0010): its immutable key, default name, branding
 *     and copy, schedule kind, listing statuses, the strict validation and public projection of its listing
 *     details, its filters, legend, statistics and route profiles, and the whitelisted Twig fragments that
 *     present it, and how a listing's status shows. Core code asks the type instead of branching on a type key.
 *
 * @dependencies Event/OccurrenceSettings, Shared/ValidationException
 */
#[AutoconfigureTag(self::TAG)]
interface EventType
{
    public const TAG = 'app.event_type';

    /** The immutable registry key (one of EventTypeRegistry::KEYS). */
    public function key(): string;

    /** The event name a new community gets ("Halloween"). */
    public function defaultName(): string;

    /**
     * Default branding; a type without its own colours uses the community defaults (Community/CommunitySchema).
     *
     * @return array{subtitle:string, brand_emoji:string, headline:string, headline_accent:string, intro:string, primary_color?:string, accent_color?:string}
     */
    public function defaultBranding(): array;

    /**
     * Plain-text copy by purpose (reminders, headings, labels, captions); Twig and the console escape it.
     *
     * @return array<string,mixed>
     */
    public function copy(): array;

    /** single_day | nightly_range | sale_days */
    public function scheduleKind(): string;

    /** The most days one listing may take part in (single_day 1, nightly_range 62, sale_days 3). */
    public function maxDays(): int;

    /**
     * Hour choices offered by the forms and the "Visit at" filter, as [first, last] hours of the day.
     *
     * @return array{start: array{0:int,1:int}, end: array{0:int,1:int}, visit: array{0:int,1:int}}
     */
    public function hourChoices(): array;

    /**
     * Stored listing statuses in display order. A day-scoped status holds only on the community-local date it
     * was set (status_date); on any other date the effective status is "active".
     *
     * @return array<string, array{label:string, icon:string, day_scoped:bool, register_label?:string}>
     */
    public function statuses(): array;

    /**
     * How visitors see a listing's status (Event/Schedule/DisplayStatus): for each stored status whether it is
     * day-scoped and its own display key (null: today's window phase decides), and the display key of each phase
     * (inside the window, later today, ended today, no window today). public/assets/js/events/<key>.js exports the
     * same table.
     *
     * @return array{statuses: array<string, array{day_scoped:bool, display:?string}>, phases: array{inside:string, later_today:string, ended_today:string, not_today:string}}
     */
    public function displayRules(): array;

    /**
     * Community feature switches the type offers organizers, in display order (stored in settings.features).
     *
     * @return array<string, array{label:string, default:bool}>
     */
    public function features(): array;

    /**
     * The details of a new listing, in canonical order; its keys are the whitelist of detail fields.
     *
     * @return array<string,mixed>
     */
    public function defaultDetails(): array;

    /**
     * Strict validation: unknown keys are refused ("details.<key>"), values are coerced, switched-off features
     * are forced off and missing keys take their defaults.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed> normalized details in canonical order
     *
     * @throws ValidationException with field errors
     */
    public function normalizeDetails(array $input, OccurrenceSettings $settings): array;

    /**
     * The strict public projection of stored details (only whitelisted keys, typed values).
     *
     * @param array<string,mixed> $details
     *
     * @return array<string,mixed>
     */
    public function publicDetails(array $details): array;

    /**
     * The flat fields older clients and the legacy listings columns know, for the compatibility window
     * (D-20261031-03); [] for a type that has no legacy representation.
     *
     * @param array<string,mixed> $details
     *
     * @return array<string,mixed>
     */
    public function legacyFields(array $details): array;

    /**
     * Filter toggles in display order; quick ones are icon buttons, the others sit under "Filters". Labels are
     * plain text and icons decorative; `feature` names the community switch that must be on (null: always).
     *
     * @return list<array{key:string, label:string, icon:string, quick:bool, feature:?string, id?:string, title?:string, icon_class?:string}>
     */
    public function filters(): array;

    /** @return list<array{cls:string, icon:string, label:string, feature:?string}> the map legend */
    public function legend(): array;

    /**
     * Counts for the public payload's stats block.
     *
     * @param list<array<string,mixed>> $homes public homes (with their details)
     *
     * @return array<string,int>
     */
    public function stats(array $homes): array;

    /** @return list<string> route profiles the type allows: ['walking'] or ['walking', 'driving'] */
    public function routeProfiles(): array;

    /**
     * Twig fragments by name; every path lies under templates/event/<key>/ (the whitelist core templates use).
     *
     * @return array<string,string>
     */
    public function fragments(): array;
}
