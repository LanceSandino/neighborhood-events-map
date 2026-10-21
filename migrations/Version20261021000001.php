<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @module migrations/Version20261021000001
 * @description Sign out everywhere (users.sessions_ended_at: sessions that began before it are over) and the
 *     account ledger: deletions and organizer-role changes, by id and time only, so a restored backup can have
 *     them re-applied (bin/restore.sh → app:ledger:replay). The ledger is created only if missing: a restore of
 *     a backup taken before this migration keeps the live table, and this migration then runs again.
 */
final class Version20261021000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sign out everywhere; account ledger for re-applying deletions and role changes after a restore';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS sessions_ended_at TIMESTAMPTZ NULL');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS account_ledger (
                id BIGSERIAL PRIMARY KEY,
                action TEXT NOT NULL CHECK (action IN ('user.deleted', 'review.deleted', 'admin.granted', 'admin.revoked')),
                subject_id BIGINT NOT NULL,
                at TIMESTAMPTZ NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_ledger');
        $this->addSql('ALTER TABLE users DROP COLUMN sessions_ended_at');
    }
}
