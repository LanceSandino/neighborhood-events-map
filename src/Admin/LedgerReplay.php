<?php

declare(strict_types=1);

namespace App\Admin;

use App\Audit\AccountLedger;
use Doctrine\DBAL\Connection;

/**
 * @module Admin/LedgerReplay
 * @description Re-applies the account ledger to the database, oldest entry first: after a restore, accounts and
 *     review requests deleted since the backup are erased again and organizer roles return to their latest state.
 *     An entry applies only to a row that already existed when it was recorded (created_at ≤ its time), so a
 *     newer row that reuses an id after an older restore is never touched. Running it again changes nothing.
 */
final class LedgerReplay
{
    public function __construct(private readonly Connection $db, private readonly AccountLedger $ledger, private readonly AccountErasure $erasure)
    {
    }

    /** @return array{users:int, reviews:int, roles:int} rows erased or corrected */
    public function replay(): array
    {
        $done = ['users' => 0, 'reviews' => 0, 'roles' => 0];
        $roles = []; // the latest grant or revocation per account decides its role
        foreach ($this->ledger->entries() as $e) {
            if ('user.deleted' === $e['action']) {
                $email = $this->db->fetchOne('SELECT email_normalized FROM users WHERE id = ? AND created_at <= ?', [$e['subject_id'], $e['at']]);
                if (false !== $email) {
                    $done['reviews'] += $this->erasure->erase($e['subject_id'], (string) $email, recordInLedger: false)['reviews'];
                    ++$done['users'];
                }
            } elseif ('review.deleted' === $e['action']) {
                $done['reviews'] += $this->db->executeStatement('DELETE FROM disputes WHERE id = ? AND created_at <= ?', [$e['subject_id'], $e['at']]);
            } else {
                $roles[$e['subject_id']] = $e;
            }
        }
        foreach ($roles as $e) {
            $done['roles'] += $this->db->executeStatement('UPDATE users SET is_admin = :a WHERE id = :id AND created_at <= :at AND is_admin IS DISTINCT FROM :a',
                ['a' => 'admin.granted' === $e['action'], 'id' => $e['subject_id'], 'at' => $e['at']], ['a' => 'boolean']);
        }

        return $done;
    }
}
