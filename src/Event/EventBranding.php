<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\TypeDefaults;
use App\Shared\TextInput;

/**
 * @module Event/EventBranding
 * @description One event's own branding (ADR 0012), kept in its settings as `branding`: the emoji (never drawn since 0.19, kept
 *     only for the 0.9 mirror, ADR 0013), the headline and
 *     its accent part, the intro, the two brand colours and the event's About text (what visitors read on About and in
 *     the Rules for that event). Every field is optional: empty means the event type's default, so a Christmas lights
 *     event shows the lights' words and colours unless its organizer set its own. The checks are strict (unknown keys,
 *     text over its length and anything but #RRGGBB colours are refused, never cut). The logo, the city line, the
 *     subtitle and the neighborhood's general About text belong to the community, never to an event. Before 0.16 the
 *     community kept one branding for every event; fromLegacy() turns it into an event's branding, moving only the
 *     values an organizer changed (a value that equals a built-in default of any type takes the event's own type default,
 *     as Community/TypeDefaults showed it before).
 *
 * @dependencies Event/EventType, Event/EventTypeRegistry, Community/TypeDefaults, Shared/TextInput
 */
final class EventBranding
{
    /** The fields that have a default of the event's type, in canonical order. */
    public const LOOK = ['brand_emoji', 'headline', 'headline_accent', 'intro', 'primary_color', 'accent_color'];
    /** Every field an event's branding may have. */
    public const KEYS = [...self::LOOK, 'about'];
    public const COLORS = ['primary_color', 'accent_color'];
    /** Longest text per field, in characters. */
    public const MAX = ['brand_emoji' => 8, 'headline' => 40, 'headline_accent' => 40, 'intro' => 280, 'about' => 1500];
    private const NAMES = ['brand_emoji' => 'the emoji', 'headline' => 'the headline', 'headline_accent' => 'the accent part', 'intro' => 'the intro', 'about' => 'the About text'];

    /**
     * An organizer's branding for an event, checked: only the fields that are set, in canonical order.
     *
     * @param array<string,string> $e field errors, keyed settings.branding.<field>
     *
     * @return array<string,string>
     */
    public static function normalize(mixed $raw, array &$e): array
    {
        if (null === $raw || [] === $raw) {
            return [];
        }
        if (!\is_array($raw) || array_is_list($raw)) {
            $e['settings.branding'] = 'Send the branding as an object.';

            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $k = (string) $k;
            $field = 'settings.branding.'.$k;
            if (!\in_array($k, self::KEYS, true)) {
                $e[$field] = 'Unknown field.';
                continue;
            }
            if (null === $v) {
                continue;
            }
            if (!\is_string($v)) {
                $e[$field] = 'Send text.';
                continue;
            }
            if (\in_array($k, self::COLORS, true)) {
                $color = strtoupper(trim($v));
                if ('' !== $color && !preg_match('~^#[0-9A-F]{6}$~', $color)) {
                    $e[$field] = 'Use a colour like #38597F, or leave it empty for the default.';
                } elseif ('' !== $color) {
                    $out[$k] = $color;
                }
                continue;
            }
            $max = self::MAX[$k];
            $text = 'about' === $k ? TextInput::paragraphs($v, 4 * $max) : TextInput::line($v, 4 * $max);
            if (mb_strlen($text) > $max) {
                $e[$field] = sprintf('Keep %s to %d characters.', self::NAMES[$k], $max);
            } elseif ('' !== $text) {
                $out[$k] = $text;
            }
        }

        return array_intersect_key(array_replace(array_flip(self::KEYS), $out), $out);
    }

    /**
     * What visitors see for an event: its own values over its type's defaults (the six look fields).
     *
     * @param array<string,mixed> $branding the event's stored branding
     *
     * @return array<string,string>
     */
    public static function look(EventType $type, array $branding): array
    {
        $defaults = TypeDefaults::defaults($type);
        $out = [];
        foreach (self::LOOK as $k) {
            $own = $branding[$k] ?? null;
            $out[$k] = \is_string($own) && '' !== $own ? $own : $defaults[$k];
        }

        return $out;
    }

    /**
     * The event's own About text ('' when it has none: the community's general text, else the type's, applies).
     *
     * @param array<string,mixed> $branding
     */
    public static function about(array $branding): string
    {
        return \is_string($branding['about'] ?? null) ? $branding['about'] : '';
    }

    /**
     * The branding a community kept for every event before 0.16 (its settings' branding block and About text) as one
     * event's branding: only the values an organizer changed; a value equal to the built-in default of any type is left
     * out, so the event shows its own type's default for it, exactly as Community/TypeDefaults showed it.
     *
     * @param array<string,mixed> $legacy the community settings' branding block
     *
     * @return array<string,string>
     */
    public static function fromLegacy(array $legacy, string $about, EventTypeRegistry $types): array
    {
        $out = [];
        foreach (self::LOOK as $k) {
            $v = $legacy[$k] ?? null;
            if (!\is_string($v) || '' === trim($v) || self::isBuiltIn($types, $k, $v)) {
                continue;
            }
            $out[$k] = \in_array($k, self::COLORS, true) ? strtoupper($v) : $v;
        }
        if ('' !== trim($about)) {
            $out['about'] = $about;
        }

        return $out;
    }

    /** A value is a built-in default when it equals that field's default of any type (colours in any case). */
    private static function isBuiltIn(EventTypeRegistry $types, string $key, string $value): bool
    {
        foreach ($types->enabled() as $t) {
            $default = TypeDefaults::defaults($t)[$key] ?? null;
            if (\is_string($default) && (\in_array($key, self::COLORS, true) ? 0 === strcasecmp($value, $default) : $value === $default)) {
                return true;
            }
        }

        return false;
    }
}
