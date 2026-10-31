<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventType;
use App\Event\EventTypeRegistry;

/**
 * @module Community/TypeDefaults
 * @description The community settings as the active (or previewed) event's type shows them (D-20261031-16). Branding
 *     texts, the brand colours and the subtitle that still equal another type's built-in default follow the event's
 *     type, so a community that never changed them gets the Christmas lights headline, words and midnight blue and
 *     gold when Christmas Lights goes on the map, and Halloween's again next October; anything an organizer changed
 *     stays as they set it (Community & branding). Feature switches the type offers but the settings lack take the
 *     type's defaults. A type's own defaults are never replaced, so Halloween's settings read exactly as stored.
 *
 * @dependencies Event/EventType, Event/EventTypeRegistry, Community/CommunitySchema
 */
final class TypeDefaults
{
    /** Branding fields that follow the event's type while they hold a built-in default. */
    public const BRANDING = ['brand_emoji', 'headline', 'headline_accent', 'intro', 'primary_color', 'accent_color'];

    /**
     * @param array<string,mixed> $settings normalized settings
     *
     * @return array<string,mixed>
     */
    public static function apply(array $settings, EventType $type, EventTypeRegistry $types): array
    {
        $mine = self::defaults($type);
        $others = array_values(array_filter($types->enabled(), static fn (EventType $t) => $t->key() !== $type->key()));
        foreach (self::BRANDING as $k) {
            if (isset($settings['branding'][$k]) && self::isDefaultOf($others, $k, $settings['branding'][$k])) {
                $settings['branding'][$k] = $mine[$k];
            }
        }
        if (isset($settings['community']['subtitle']) && self::isDefaultOf($others, 'subtitle', $settings['community']['subtitle'])) {
            $settings['community']['subtitle'] = $mine['subtitle'];
        }
        foreach ($type->features() as $key => $feature) {
            $settings['features'][$key] ??= $feature['default'];
        }

        return $settings;
    }

    /** @return array<string,string> a type's default branding with the colours every type falls back to */
    public static function defaults(EventType $type): array
    {
        return $type->defaultBranding() + ['primary_color' => CommunitySchema::DEFAULT_PRIMARY_COLOR, 'accent_color' => CommunitySchema::DEFAULT_ACCENT_COLOR];
    }

    /** @param list<EventType> $types */
    private static function isDefaultOf(array $types, string $key, mixed $value): bool
    {
        foreach ($types as $t) {
            $default = self::defaults($t)[$key] ?? null;
            if (\is_string($value) && \is_string($default) && (str_ends_with($key, '_color') ? 0 === strcasecmp($value, $default) : $value === $default)) {
                return true;
            }
        }

        return false;
    }
}
