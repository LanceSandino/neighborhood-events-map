<?php

declare(strict_types=1);

namespace App\Community;

use App\Event\EventOccurrence;
use App\Event\EventOccurrenceRepository;
use Doctrine\DBAL\Connection;

/**
 * @module Community/EventBlockMirror
 * @description The settings' `event` block as the previous release reads it (D-20261031-41): an event as the block
 *     (an event whose dates are to be announced keeps the block's last date, which the block always has), the event the
 *     stored block mirrors — the Halloween the legacy `listings` table mirrors (the newest open or closed one), else the
 *     default event — and the write of that event into the stored settings after an organizer changed events.
 *
 * @dependencies Event/EventOccurrence, Event/EventOccurrenceRepository, DBAL
 */
final class EventBlockMirror
{
    public function __construct(private readonly EventOccurrenceRepository $occurrences)
    {
    }

    /** @return array{name:string,date:string,starts_at:string,ends_at:string,type:string,starts_on:?string,ends_on:?string} */
    public static function block(EventOccurrence $o, string $fallbackDate): array
    {
        return ['name' => $o->name, 'date' => $o->startsOn ?? $fallbackDate, 'starts_at' => $o->defaultStart, 'ends_at' => $o->defaultEnd, 'type' => $o->type,
            'starts_on' => $o->startsOn, 'ends_on' => $o->endsOn];
    }

    /** The event the stored block mirrors for the previous release: the bridged Halloween, else the default event. */
    public function legacyEvent(Connection $db): ?EventOccurrence
    {
        return $this->occurrences->bridged($db) ?? $this->occurrences->defaultEvent($db);
    }

    /** Writes that event into the stored settings' event block (nothing to write without one). */
    public function write(Connection $db): void
    {
        $event = $this->legacyEvent($db);
        if (null === $event) {
            return;
        }
        $stored = (string) ($db->fetchOne("SELECT settings->'event'->>'date' FROM community_settings WHERE id = 1") ?: '');
        $db->executeStatement("UPDATE community_settings SET settings = jsonb_set(settings, '{event}', COALESCE(settings->'event', '{}'::jsonb) || CAST(:e AS JSONB)) WHERE id = 1",
            ['e' => json_encode(self::block($event, $stored), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)]);
    }
}
