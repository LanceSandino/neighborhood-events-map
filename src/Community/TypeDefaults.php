<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventType;
use App\Event\EventTypeRegistry;

/**
 * @module Community/TypeDefaults
 * @description The community settings as the selected (or previewed) event's type shows them: a subtitle that still
 *     equals another type's built-in default follows the event's type (D-20261031-16), and feature switches the type
 *     offers but the settings lack take the type's defaults. A type's own defaults are never replaced; subtitle() is what
 *     a page shows of it, naming an event that has a name of its own. Since 0.16 the
 *     emoji, headline, intro and colours are each event's own (Community/EventBrandingView, ADR 0012), with
 *     defaults() as their fallback.
 *
 * @dependencies Event/EventType, Event/EventTypeRegistry, Community/CommunitySchema
 */
final class TypeDefaults
{
    /**
     * @param array<string,mixed> $settings normalized settings
     *
     * @return array<string,mixed>
     */
    public static function apply(array $settings, EventType $type, EventTypeRegistry $types): array
    {
        $mine = self::defaults($type);
        $others = array_values(array_filter($types->enabled(), static fn (EventType $t) => $t->key() !== $type->key()));
        if (isset($settings['community']['subtitle']) && self::isDefaultOf($others, 'subtitle', $settings['community']['subtitle'])) {
            $settings['community']['subtitle'] = $mine['subtitle'];
        }
        foreach ($type->features() as $key => $feature) {
            $settings['features'][$key] ??= $feature['default'];
        }

        return $settings;
    }

    /**
     * What the page's eyebrow says: an organizer's own subtitle as written; a built-in one names the event when it has a
     * name of its own ("Your neighborhood. Your Rummage Sale."), otherwise the type's words.
     *
     * @param list<EventType> $types
     */
    public static function subtitle(string $subtitle, EventType $type, ?string $eventName, array $types): string
    {
        $default = self::isDefaultOf($types, 'subtitle', $subtitle);
        if (!$default || null === $eventName || '' === trim($eventName) || 0 === strcasecmp(trim($eventName), $type->defaultName())) {
            return $subtitle;
        }

        return sprintf('Your neighborhood. Your %s.', trim($eventName));
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
