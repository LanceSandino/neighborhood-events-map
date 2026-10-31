<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventBranding;
use App\Event\EventOccurrence;
use App\Event\EventType;

/**
 * @module Community/EventBrandingView
 * @description The community settings' branding and About text as one event shows them (ADR 0012): the community keeps
 *     its own name, subtitle, city line, logo, links and an optional general About text for the neighborhood; the
 *     emoji, headline, intro, colours and the event's About text come from the event (Event/EventBranding), each
 *     falling back to the event type's default. The About text a page shows is the event's own, else the community's
 *     general one (else the page uses the type's built-in text). The branding keys keep their stored positions, so
 *     every answer keeps its key order. The stored branding keys and `community.about` are what the previous release
 *     reads after an image-only rollback (Community/EventBlockMirror keeps them equal to the mirrored event's); this
 *     release never shows them for an event.
 *
 * @dependencies Event/EventBranding, Event/EventType, Event/EventOccurrence
 */
final class EventBrandingView
{
    /**
     * @param array<string,mixed> $settings stored (normalized) settings
     *
     * @return array<string,mixed> the settings with the event's branding and About text
     */
    public static function apply(array $settings, EventType $type, ?EventOccurrence $event): array
    {
        $own = \is_array($event?->settings['branding'] ?? null) ? $event->settings['branding'] : [];
        foreach (EventBranding::look($type, $own) as $k => $v) {
            $settings['branding'][$k] = $v;
        }
        $general = self::generalAbout($settings);
        $settings['community']['general_about'] = $general;
        $settings['community']['about'] = '' !== EventBranding::about($own) ? EventBranding::about($own) : $general;

        return $settings;
    }

    /** @param array<string,mixed> $settings */
    public static function generalAbout(array $settings): string
    {
        return \is_string($settings['community']['general_about'] ?? null) ? $settings['community']['general_about'] : '';
    }
}
