<?php

declare(strict_types=1);

namespace App\Event;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Event/EventOccurrenceRepository
 * @description Rows of event_occurrences: the active one, one by id, all of them with their opted-in and public
 *     listing counts (organizer view), inserts and field updates. Life-cycle changes (activate, archive, purge) and
 *     their side effects live in Event/EventLifecycle; this class only reads and writes rows.
 *
 * @dependencies DBAL, clock, Event/EventOccurrence
 */
final class EventOccurrenceRepository
{
    private const COLUMNS = 'id, type, name, starts_on, ends_on, default_start_local, default_end_local, settings, state, created_at, activated_at, archived_at';

    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
    }

    /** The active occurrence whatever its type (the database allows at most one). */
    public function active(?Connection $db = null): ?EventOccurrence
    {
        $row = ($db ?? $this->db)->fetchAssociative('SELECT '.self::COLUMNS." FROM event_occurrences WHERE state = 'active'");

        return false === $row ? null : EventOccurrence::fromRow($row);
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
     * Every occurrence, newest dates first, with how many homes opted in and how many of them the public map shows.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithCounts(): array
    {
        return array_map(static fn (array $r) => EventOccurrence::fromRow($r)->toArray() + ['opted_in' => (int) $r['opted_in'], 'public' => (int) $r['public']],
            $this->db->fetchAllAssociative(
                'SELECT o.id, o.type, o.name, o.starts_on, o.ends_on, o.default_start_local, o.default_end_local, o.settings, o.state, o.created_at, o.activated_at, o.archived_at,
                        COUNT(el.id) AS opted_in,
                        COUNT(el.id) FILTER (WHERE el.published AND NOT el.moderation_hidden AND a.enabled AND a.inside_boundary) AS public
                 FROM event_occurrences o
                 LEFT JOIN event_listings el ON el.event_id = o.id
                 LEFT JOIN eligible_addresses a ON a.id = el.address_id
                 GROUP BY o.id ORDER BY o.starts_on DESC, o.id DESC',
            ));
    }

    public function listingCount(int $id, ?Connection $db = null): int
    {
        return (int) ($db ?? $this->db)->fetchOne('SELECT COUNT(*) FROM event_listings WHERE event_id = ?', [$id]);
    }

    /** @param array{type:string,name:string,starts_on:string,ends_on:string,default_start:string,default_end:string,settings:array<string,mixed>} $f */
    public function insert(Connection $db, array $f, string $state): int
    {
        $now = $this->now();

        return (int) $db->fetchOne(
            'INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, settings, state, created_at, activated_at)
             VALUES (:type, :name, :starts, :ends, :ds, :de, CAST(:settings AS JSONB), :state, :now, :activated) RETURNING id',
            ['type' => $f['type'], 'name' => $f['name'], 'starts' => $f['starts_on'], 'ends' => $f['ends_on'], 'ds' => $f['default_start'], 'de' => $f['default_end'],
                'settings' => self::json($f['settings']), 'state' => $state, 'now' => $now, 'activated' => EventOccurrence::ACTIVE === $state ? $now : null],
        );
    }

    /** @param array{name:string,starts_on:string,ends_on:string,default_start:string,default_end:string,settings:array<string,mixed>} $f */
    public function updateFields(Connection $db, int $id, array $f): void
    {
        $db->executeStatement(
            'UPDATE event_occurrences SET name = :name, starts_on = :starts, ends_on = :ends, default_start_local = :ds, default_end_local = :de,
                settings = CAST(:settings AS JSONB) WHERE id = :id',
            ['name' => $f['name'], 'starts' => $f['starts_on'], 'ends' => $f['ends_on'], 'ds' => $f['default_start'], 'de' => $f['default_end'],
                'settings' => self::json($f['settings']), 'id' => $id],
        );
    }

    public function setState(Connection $db, int $id, string $state): void
    {
        $column = match ($state) {
            EventOccurrence::ACTIVE => 'activated_at',
            EventOccurrence::ARCHIVED => 'archived_at',
            default => throw new \LogicException('An event goes only from draft to active to archived.'),
        };
        $db->executeStatement("UPDATE event_occurrences SET state = :state, $column = :now WHERE id = :id", ['state' => $state, 'now' => $this->now(), 'id' => $id]);
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
