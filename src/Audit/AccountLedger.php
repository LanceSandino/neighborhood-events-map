<?php

declare(strict_types=1);

namespace App\Audit;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * @module Audit/AccountLedger
 * @description Append-only record of account deletions, erased review requests and organizer-role changes,
 *     by id and time only (no email or other personal data). A restore puts an older copy of the database back;
 *     bin/restore.sh exports this ledger first, merges it back afterwards and replays it (Admin/LedgerReplay),
 *     so nobody deleted or demoted since the backup comes back.
 */
final class AccountLedger
{
    public const ACTIONS = ['user.deleted', 'review.deleted', 'admin.granted', 'admin.revoked'];

    public function __construct(private readonly Connection $db, private readonly ClockInterface $clock)
    {
    }

    public function record(string $action, int $subjectId): void
    {
        if (!\in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown ledger action '.$action);
        }
        $this->db->insert('account_ledger', ['action' => $action, 'subject_id' => $subjectId, 'at' => $this->clock->now()->format('Y-m-d H:i:s.uP')]);
    }

    /** @return list<array{id:int, action:string, subject_id:int, at:string}> oldest first */
    public function entries(): array
    {
        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'action' => (string) $r['action'], 'subject_id' => (int) $r['subject_id'], 'at' => (string) $r['at']],
            $this->db->fetchAllAssociative('SELECT id, action, subject_id, at FROM account_ledger ORDER BY id'));
    }

    /**
     * Adds entries exported from another copy of this database (the live one, before a restore). The ledger only
     * ever grows, so an older copy holds a prefix of a newer one: entries are matched by id and missing ones added.
     *
     * @param mixed $entries decoded JSON from entries()
     *
     * @return int entries added
     */
    public function merge(mixed $entries): int
    {
        if (!\is_array($entries) || !array_is_list($entries)) {
            throw new \InvalidArgumentException('The ledger export must be a list of entries.');
        }
        $rows = [];
        foreach ($entries as $i => $e) {
            $ok = \is_array($e) && \is_int($e['id'] ?? null) && $e['id'] > 0 && \in_array($e['action'] ?? null, self::ACTIONS, true)
                && \is_int($e['subject_id'] ?? null) && \is_string($e['at'] ?? null) && false !== strtotime($e['at']);
            if (!$ok) {
                throw new \InvalidArgumentException(sprintf('Ledger entry %d is malformed.', $i + 1));
            }
            $rows[] = $e;
        }

        return $this->db->transactional(function (Connection $db) use ($rows): int {
            $added = 0;
            foreach ($rows as $e) {
                $added += $db->executeStatement('INSERT INTO account_ledger (id, action, subject_id, at) VALUES (?, ?, ?, ?) ON CONFLICT (id) DO NOTHING',
                    [$e['id'], $e['action'], $e['subject_id'], $e['at']]);
            }
            // New entries continue after the highest id, whichever copy it came from.
            $db->executeQuery("SELECT setval(pg_get_serial_sequence('account_ledger', 'id'), COALESCE(MAX(id), 1), MAX(id) IS NOT NULL) FROM account_ledger");

            return $added;
        });
    }
}
