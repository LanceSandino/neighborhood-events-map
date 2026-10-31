<?php

declare(strict_types=1);

namespace App\Event;

use App\Community\ConfigException;
use App\Community\EventBlockSchema;
use Doctrine\DBAL\Connection;

/**
 * @module Event/OccurrenceSettingsSync
 * @description The community settings' `event` block is a view of the active occurrence (D-20261031-07). When
 *     settings are saved (community.yaml bootstrap, Organizer → Community → Event) this writes the block to the
 *     occurrence: with no occurrence at all it creates the first one, active; otherwise it updates the active one's
 *     name and default hours, and its dates only while no home is listed for it (moving a season's listings is an
 *     explicit step under Organizer → Events, and the next season is a new event). An occurrence's type never changes.
 *     Takes the same lock as activation, so a save never interleaves with a switch of events.
 *
 * @dependencies DBAL, Event/EventOccurrenceRepository, Event/EventTypeRegistry, Community/EventBlockSchema, Community/ConfigException
 */
final class OccurrenceSettingsSync
{
    /** The advisory lock every change of the active occurrence takes (activation, archive, edits). */
    public const LOCK = 'event-occurrences';

    public function __construct(
        private readonly Connection $db,
        private readonly EventOccurrenceRepository $occurrences,
        private readonly EventTypeRegistry $types,
    ) {
    }

    /**
     * @param array<string,mixed> $event the normalized settings block (Community/EventBlockSchema)
     *
     * @throws ConfigException when the change must go through Organizer → Events
     */
    public function apply(array $event): void
    {
        $this->db->transactional(function (Connection $db) use ($event): void {
            self::lock($db);
            $active = $this->occurrences->active($db);
            [$first, $last] = EventBlockSchema::range($event);
            $fields = ['type' => (string) $event['type'], 'name' => (string) $event['name'], 'starts_on' => $first,
                'ends_on' => $last, 'default_start' => (string) $event['starts_at'], 'default_end' => (string) $event['ends_at']];
            if (null === $active) {
                if (false === $db->fetchOne('SELECT 1 FROM event_occurrences LIMIT 1')) {
                    $this->occurrences->insert($db, $fields + ['settings' => []], EventOccurrence::ACTIVE); // the install's first event
                }

                return; // between events the block is only the last event's view; Organizer → Events starts the next one
            }
            if (!$this->types->isEnabled($active->type)) {
                return; // a type this release does not offer: left as it is for the organizer to archive
            }
            if ($fields['type'] !== $active->type) {
                throw new ConfigException(['event.type cannot change for the current event; prepare an event of another type under Organizer → Events.']);
            }
            $moved = $fields['starts_on'] !== $active->startsOn || $fields['ends_on'] !== $active->endsOn;
            $listed = $moved ? $this->occurrences->listingCount($active->id, $db) : 0;
            if ($listed > 0) {
                throw new ConfigException([sprintf('event.date: %d home(s) are listed for this event. To start the next season, prepare a new event under Organizer → Events (this season\'s listings stay with this season); to move this event and its homes to other dates, edit it there.', $listed)]);
            }
            $this->occurrences->updateFields($db, $active->id, $fields + ['settings' => $active->settings]);
        });
    }

    public static function lock(Connection $db): void
    {
        $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))', ['k' => self::LOCK]);
    }
}
