<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventBranding;
use App\Event\EventOccurrence;
use App\Event\EventOccurrenceRepository;
use App\Event\EventTypeRegistry;
use Doctrine\DBAL\Connection;

/**
 * @module Community/EventBlockMirror
 * @description What the previous release reads from the stored settings (D-20261031-41): an event as the `event` block
 *     (an event whose dates are to be announced keeps the block's last date, which the block always has), the event the
 *     stored settings mirror — the Halloween the legacy `listings` table mirrors (the newest open or closed one), else
 *     the default event —, that event's branding as the community branding keys the previous release shows (emoji,
 *     headline, accent part, intro, colours: its own values over its type's defaults) and its About text as
 *     `community.about` (else the community's general text; ADR 0012), its logo (else the community's) as the settings'
 *     `branding.logo` and the volume file that key names (D-20261105-03), and the write of all of it into the stored
 *     settings after an organizer changed events or logos. This release never shows those keys for an event.
 *
 * @dependencies Event/EventOccurrence, Event/EventOccurrenceRepository, Event/EventTypeRegistry, Event/EventBranding, Community/EventBrandingView, Community/LogoStore, Community/BrandingStore, DBAL
 */
final class EventBlockMirror
{
    public function __construct(
        private readonly EventOccurrenceRepository $occurrences,
        private readonly EventTypeRegistry $types,
        private readonly LogoStore $logos,
        private readonly BrandingStore $files,
    ) {
    }

    /** @return array{name:string,date:string,starts_at:string,ends_at:string,type:string,starts_on:?string,ends_on:?string} */
    public static function block(EventOccurrence $o, string $fallbackDate): array
    {
        return ['name' => $o->name, 'date' => $o->startsOn ?? $fallbackDate, 'starts_at' => $o->defaultStart, 'ends_at' => $o->defaultEnd, 'type' => $o->type,
            'starts_on' => $o->startsOn, 'ends_on' => $o->endsOn];
    }

    /** The event the stored settings mirror for the previous release: the bridged Halloween, else the default event. */
    public function legacyEvent(Connection $db): ?EventOccurrence
    {
        return $this->occurrences->bridged($db) ?? $this->occurrences->defaultEvent($db);
    }

    /**
     * The settings with the community branding keys and About text the previous release shows: the event's.
     *
     * @param array<string,mixed> $settings normalized settings (with the community's general About text)
     *
     * @return array<string,mixed>
     */
    public function withLegacyBranding(array $settings, EventOccurrence $event): array
    {
        if (!$this->types->isEnabled($event->type)) {
            return $settings; // a type this release does not know: what is stored stays
        }
        $shown = EventBrandingView::apply($settings, $this->types->get($event->type), $event);
        foreach (EventBranding::LOOK as $k) {
            $settings['branding'][$k] = $shown['branding'][$k];
        }
        $settings['community']['about'] = $shown['community']['about'];
        $settings['branding']['logo'] = $this->mirrorLogo($settings['branding']['logo'] ?? null, $event->id);

        return $settings;
    }

    /**
     * The logo the previous release shows: the event's, else the community's. Writes the volume file and gives its name.
     * Before the logos table exists the stored name stays.
     */
    private function mirrorLogo(mixed $stored, ?int $eventId): mixed
    {
        if (!$this->logos->available()) {
            return $stored;
        }
        $logo = (null !== $eventId ? $this->logos->load($eventId) : null) ?? $this->logos->load(null);
        $this->files->mirror($logo['extension'] ?? null, $logo['bytes'] ?? null);

        return null === $logo ? null : 'logo.'.$logo['extension'];
    }

    /** Writes that event into the stored settings' event block, branding keys and About text (nothing to write without one). */
    public function write(Connection $db): void
    {
        $event = $this->legacyEvent($db);
        $json = $db->fetchOne('SELECT settings FROM community_settings WHERE id = 1');
        if (null === $event || false === $json) {
            return;
        }
        $stored = json_decode((string) $json, true, 64, \JSON_THROW_ON_ERROR);
        $mirrored = $this->withLegacyBranding($stored, $event);
        $db->executeStatement("UPDATE community_settings SET settings = jsonb_set(jsonb_set(jsonb_set(settings,
                '{event}', COALESCE(settings->'event', '{}'::jsonb) || CAST(:e AS JSONB)),
                '{branding}', COALESCE(settings->'branding', '{}'::jsonb) || CAST(:b AS JSONB)),
                '{community,about}', to_jsonb(CAST(:a AS TEXT))) WHERE id = 1", [
            'e' => json_encode(self::block($event, (string) ($stored['event']['date'] ?? '')), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'b' => json_encode(array_intersect_key((array) $mirrored['branding'], array_flip([...EventBranding::LOOK, 'logo'])), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'a' => (string) ($mirrored['community']['about'] ?? ''),
        ]);
    }
}
