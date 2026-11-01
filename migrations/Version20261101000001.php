<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @module migrations/Version20261101000001
 * @description Hidden events (0.17, D-20261101-01): a new state `hidden` between draft and upcoming — visitors never see
 *     the event, residents get their house ready for it in My house, and closing an event creates next year's in it.
 *     Additive: up widens the state check and makes the one-current-per-type index count hidden events (so a hidden
 *     event is the current one of its type, like an upcoming or open one); no row changes, so every existing event —
 *     upcoming ones included — keeps its state. The default check already refuses a hidden default (it lists the public
 *     states). Down makes every hidden event a draft (organizers only: nothing becomes public; its listings stay) and
 *     puts the 0.16 check and index back. The 0.9.0-beta.1 image (image rollback) reads neither table.
 */
final class Version20261101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hidden events: residents may list, visitors see nothing; counted as the current event of their type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_state_check');
        $this->addSql("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_state_check CHECK (state IN ('draft', 'hidden', 'upcoming', 'open', 'closed', 'archived'))");
        $this->addSql('DROP INDEX uniq_current_event_per_type');
        $this->addSql("CREATE UNIQUE INDEX uniq_current_event_per_type ON event_occurrences (type) WHERE state IN ('hidden', 'upcoming', 'open')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE event_occurrences SET state = 'draft', is_default = FALSE WHERE state = 'hidden'");
        $this->addSql('DROP INDEX uniq_current_event_per_type');
        $this->addSql("CREATE UNIQUE INDEX uniq_current_event_per_type ON event_occurrences (type) WHERE state IN ('upcoming', 'open')");
        $this->addSql('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_state_check');
        $this->addSql("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_state_check CHECK (state IN ('draft', 'upcoming', 'open', 'closed', 'archived'))");
    }
}
