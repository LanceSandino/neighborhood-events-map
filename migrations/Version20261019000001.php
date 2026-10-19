<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @module migrations/Version20261019000001
 * @description Security hardening: an organizer's "hide" is kept on the address
 *     (eligible_addresses.moderation_hold) so releasing and re-claiming a home cannot undo it;
 *     index for the per-client proof-of-work difficulty count.
 */
final class Version20261019000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sticky listing moderation per address; proof-of-work client index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eligible_addresses ADD COLUMN moderation_hold BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('UPDATE eligible_addresses a SET moderation_hold = TRUE FROM listings l WHERE l.address_id = a.id AND l.moderation_hidden');
        $this->addSql('CREATE INDEX idx_pow_client_created ON pow_challenges (client_hash, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_pow_client_created');
        $this->addSql('ALTER TABLE eligible_addresses DROP COLUMN moderation_hold');
    }
}
