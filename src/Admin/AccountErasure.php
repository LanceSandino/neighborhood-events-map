<?php

declare(strict_types=1);

namespace App\Admin;

use App\Audit\AccountLedger;
use Doctrine\DBAL\Connection;

/**
 * @module Admin/AccountErasure
 * @description Erases what is stored about one email address: the account with its claims (listings cascade),
 *     pending sign-in links and claim intents, and the review requests it filed. Used by app:user:delete and by
 *     the ledger replay after a restore. A deletion is written to the account ledger in the same transaction.
 */
final class AccountErasure
{
    public function __construct(private readonly Connection $db, private readonly AccountLedger $ledger)
    {
    }

    /** @return array{homes:int, reviews:int} homes released and review requests erased */
    public function erase(?int $userId, string $email, bool $recordInLedger = true): array
    {
        return $this->db->transactional(function (Connection $db) use ($userId, $email, $recordInLedger): array {
            $homes = null === $userId ? 0 : $db->executeStatement('DELETE FROM claims WHERE user_id = ?', [$userId]); // listings cascade
            $db->executeStatement('DELETE FROM auth_link_tokens WHERE email_normalized = ?', [$email]);
            $db->executeStatement('DELETE FROM claim_intents WHERE email_normalized = ?', [$email]);
            $reviews = array_map('intval', $db->fetchFirstColumn('DELETE FROM disputes WHERE reporter_email = ? RETURNING id', [$email]));
            if (null !== $userId) {
                $db->executeStatement('UPDATE disputes SET resolved_by = NULL WHERE resolved_by = ?', [$userId]);
                $db->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);
            }
            if ($recordInLedger) {
                if (null !== $userId) {
                    $this->ledger->record('user.deleted', $userId);
                }
                foreach ($reviews as $id) {
                    $this->ledger->record('review.deleted', $id);
                }
            }

            return ['homes' => (int) $homes, 'reviews' => \count($reviews)];
        });
    }
}
