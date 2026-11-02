<?php

declare(strict_types=1);

namespace App\Event;

use App\Event\Type\HalloweenType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Event/EventOccurrenceRepository
 * @description Rows of event_occurrences: one by id, the public events (upcoming, open, closed; the default first,
 *     then by date), the events residents see in My house (the public ones and hidden ones), the default event — the
 *     flagged one, else the newest open event, else the newest public one —, the current (hidden, upcoming or open) event
 *     of a type, the event the legacy `listings` table mirrors (the newest open or
 *     closed one-day Halloween, D-20261031-41), all events with their opted-in and public listing counts (organizer
 *     view), inserts and field updates. Life-cycle changes (open, close, default, archive, purge) and their side
 *     effects live in Event/EventLifecycle; this class only reads and writes rows.
 *
 * @dependencies DBAL, clock, Event/EventOccurrence, Event/Type/HalloweenType (the bridged type key)
 */
final class EventOccurrenceRepository
{
    private const COLUMNS = 'id, type, name, starts_on, ends_on, default_start_local, default_end_local, settings, state, created_at, activated_at, archived_at,
        is_default, dates_tentative, closed_at';
    /** Newest first: the latest first day (events to be announced last), then the latest id. */
    private const NEWEST = 'starts_on DESC NULLS LAST, id DESC';

    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
    }

    public function find(int $id, ?Connection $db = null, bool $forUpdate = false): ?EventOccurrence
    {
        $row = ($db ?? $this->db)->fetchAssociative('SELECT '.self::COLUMNS.' FROM event_occurrences WHERE id = ?'.($forUpdate ? ' FOR UPDATE' : ''), [$id]);

        return false === $row ? null : EventOccurrence::fromRow($row);
    }

    public function any(): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM event_occurrences LIMIT 1');
    }

    /**
     * The events visitors see (upcoming, open, closed): the default first, then by first day (to be announced last).
     *
     * @return list<EventOccurrence>
     */
    public function publicEvents(?Connection $db = null): array
    {
        return array_map(EventOccurrence::fromRow(...), ($db ?? $this->db)->fetchAllAssociative(
            'SELECT '.self::COLUMNS." FROM event_occurrences WHERE state IN ('upcoming', 'open', 'closed') ORDER BY is_default DESC, starts_on ASC NULLS LAST, id ASC",
        ));
    }

    /**
     * The events residents see in My house: the public ones and hidden ones (D-20261101-01), in the same order.
     *
     * @return list<EventOccurrence>
     */
    public function residentEvents(): array
    {
        return array_map(EventOccurrence::fromRow(...), $this->db->fetchAllAssociative(
            'SELECT '.self::COLUMNS." FROM event_occurrences WHERE state IN ('hidden', 'upcoming', 'open', 'closed') ORDER BY is_default DESC, starts_on ASC NULLS LAST, id ASC",
        ));
    }

    /** The default event: the flagged one, else the newest open event, else the newest public one (null: none is public). */
    public function defaultEvent(?Connection $db = null): ?EventOccurrence
    {
        $row = ($db ?? $this->db)->fetchAssociative('SELECT '.self::COLUMNS." FROM event_occurrences WHERE state IN ('upcoming', 'open', 'closed')
            ORDER BY is_default DESC, (state = 'open') DESC, ".self::NEWEST.' LIMIT 1');

        return false === $row ? null : EventOccurrence::fromRow($row);
    }

    /** The current (hidden, upcoming or open) event of a type; the database allows at most one. */
    public function currentOfType(string $type, ?Connection $db = null): ?EventOccurrence
    {
        $row = ($db ?? $this->db)->fetchAssociative('SELECT '.self::COLUMNS." FROM event_occurrences WHERE type = ? AND state IN ('hidden', 'upcoming', 'open')", [$type]);

        return false === $row ? null : EventOccurrence::fromRow($row);
    }

    /** The event the legacy `listings` table mirrors for the previous release: the newest open or closed one-day Halloween. */
    public function bridged(?Connection $db = null): ?EventOccurrence
    {
        $row = ($db ?? $this->db)->fetchAssociative('SELECT '.self::COLUMNS." FROM event_occurrences
            WHERE state IN ('open', 'closed') AND type = :type AND starts_on = ends_on ORDER BY ".self::NEWEST.' LIMIT 1', ['type' => HalloweenType::KEY]);

        return false === $row ? null : EventOccurrence::fromRow($row);
    }

    /**
     * Every occurrence, newest dates first, with how many homes opted in and how many of them the public map shows.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithCounts(): array
    {
        return array_map(static fn (array $r) => EventOccurrence::fromRow($r)->toArray() + ['opted_in' => (int) $r['opted_in'], 'public' => (int) $r['public']],
            $this->db->fetchAllAssociative(
                "SELECT o.id, o.type, o.name, o.starts_on, o.ends_on, o.default_start_local, o.default_end_local, o.settings, o.state, o.created_at, o.activated_at, o.archived_at,
                        o.is_default, o.dates_tentative, o.closed_at,
                        COUNT(el.id) AS opted_in,
                        COUNT(el.id) FILTER (WHERE o.state = 'open' AND el.published AND NOT el.moderation_hidden AND a.enabled AND a.inside_boundary) AS public
                 FROM event_occurrences o
                 LEFT JOIN event_listings el ON el.event_id = o.id
                 LEFT JOIN eligible_addresses a ON a.id = el.address_id
                 GROUP BY o.id ORDER BY o.starts_on DESC NULLS FIRST, o.id DESC",
            ));
    }

    public function listingCount(int $id, ?Connection $db = null): int
    {
        return (int) ($db ?? $this->db)->fetchOne('SELECT COUNT(*) FROM event_listings WHERE event_id = ?', [$id]);
    }

    /** @param array{type:string,name:string,starts_on:?string,ends_on:?string,default_start:string,default_end:string,settings:array<string,mixed>,dates_tentative?:bool} $f */
    public function insert(Connection $db, array $f, string $state, bool $default = false): int
    {
        $now = $this->now();

        return (int) $db->fetchOne(
            'INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, settings, state, created_at, activated_at, is_default, dates_tentative)
             VALUES (:type, :name, :starts, :ends, :ds, :de, CAST(:settings AS JSONB), :state, :now, :activated, :def, :tentative) RETURNING id',
            ['type' => $f['type'], 'name' => $f['name'], 'starts' => $f['starts_on'], 'ends' => $f['ends_on'], 'ds' => $f['default_start'], 'de' => $f['default_end'],
                'settings' => self::json($f['settings']), 'state' => $state, 'now' => $now, 'activated' => EventOccurrence::OPEN === $state ? $now : null,
                'def' => $default, 'tentative' => (bool) ($f['dates_tentative'] ?? false)],
            ['def' => 'boolean', 'tentative' => 'boolean'],
        );
    }

    /** @param array{name:string,starts_on:?string,ends_on:?string,default_start:string,default_end:string,settings:array<string,mixed>,dates_tentative?:bool} $f */
    public function updateFields(Connection $db, int $id, array $f): void
    {
        $db->executeStatement(
            'UPDATE event_occurrences SET name = :name, starts_on = :starts, ends_on = :ends, default_start_local = :ds, default_end_local = :de,
                settings = CAST(:settings AS JSONB), dates_tentative = COALESCE(:tentative, dates_tentative) WHERE id = :id',
            ['name' => $f['name'], 'starts' => $f['starts_on'], 'ends' => $f['ends_on'], 'ds' => $f['default_start'], 'de' => $f['default_end'],
                'settings' => self::json($f['settings']), 'tentative' => $f['dates_tentative'] ?? null, 'id' => $id],
            ['tentative' => 'boolean'],
        );
    }

    /**
     * Moves an event to another state and stamps it (open: activated_at and the dates confirmed; closed: closed_at;
     * archived: archived_at; hidden or archived: never the default).
     */
    public function setState(Connection $db, int $id, string $state): void
    {
        $set = match ($state) {
            EventOccurrence::UPCOMING => '',
            EventOccurrence::HIDDEN => ', is_default = FALSE',
            EventOccurrence::OPEN => ', activated_at = :now, dates_tentative = FALSE',
            EventOccurrence::CLOSED => ', closed_at = :now',
            EventOccurrence::ARCHIVED => ', archived_at = :now, is_default = FALSE',
            default => throw new \LogicException('An event never goes back to a draft.'),
        };
        $db->executeStatement("UPDATE event_occurrences SET state = :state$set WHERE id = :id", ['state' => $state, 'id' => $id] + (str_contains($set, ':now') ? ['now' => $this->now()] : []));
    }

    /** Makes one public event the default (the previous default stops being it in the same statement order). */
    public function setDefault(Connection $db, int $id): void
    {
        $db->executeStatement('UPDATE event_occurrences SET is_default = FALSE WHERE is_default AND id <> ?', [$id]);
        $db->executeStatement('UPDATE event_occurrences SET is_default = TRUE WHERE id = ?', [$id]);
    }

    /** Flags a default when none is (after the default was archived): the newest open event, else the newest public one. */
    public function ensureDefault(Connection $db): void
    {
        if (false !== $db->fetchOne('SELECT 1 FROM event_occurrences WHERE is_default')) {
            return;
        }
        $fallback = $this->defaultEvent($db);
        if (null !== $fallback) {
            $this->setDefault($db, $fallback->id);
        }
    }

    public function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.uP');
    }

    /** @param array<string,mixed> $settings */
    private static function json(array $settings): string
    {
        return json_encode((object) $settings, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }
}
