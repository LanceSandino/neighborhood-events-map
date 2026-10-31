<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @module migrations/Version20261031000003
 * @description Several published events per community (ADR 0011, D-20261031-37): an event's state becomes
 *     draft | upcoming | open | closed | archived (the one `active` event becomes `open`); the one-active index goes; at
 *     most one current (upcoming or open) event per type (partial unique index); one default event, the one "/" shows
 *     (is_default, a partial unique index, and only a public event may be the default — the open event becomes it);
 *     tentative dates (dates_tentative) and dates to be announced (starts_on and ends_on both NULL); closed_at. Additive
 *     and lossless: no row is deleted and every listing stays with its event. The rollback bridge trigger now follows the
 *     event the legacy `listings` table mirrors: the newest open or closed one-day Halloween (D-20261031-41). Down maps
 *     the states back (the default or newest open event active, other open or upcoming events drafts, closed archived),
 *     gives an event without dates its creation day, and restores the 0.11 schema and trigger.
 */
final class Version20261031000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Several published events: upcoming/open/closed states, one default event, one current event per type, tentative and TBD dates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_state_check');
        $this->addSql("UPDATE event_occurrences SET state = 'open' WHERE state = 'active'");
        $this->addSql("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_state_check CHECK (state IN ('draft', 'upcoming', 'open', 'closed', 'archived'))");
        $this->addSql('DROP INDEX uniq_one_active_event');
        $this->addSql('ALTER TABLE event_occurrences ADD COLUMN is_default BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE event_occurrences ADD COLUMN dates_tentative BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE event_occurrences ADD COLUMN closed_at TIMESTAMPTZ NULL');
        $this->addSql('ALTER TABLE event_occurrences ALTER COLUMN starts_on DROP NOT NULL');
        $this->addSql('ALTER TABLE event_occurrences ALTER COLUMN ends_on DROP NOT NULL');
        $this->addSql('ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_dates_tbd CHECK ((starts_on IS NULL) = (ends_on IS NULL))');
        $this->addSql("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_default_public CHECK (NOT is_default OR state IN ('upcoming', 'open', 'closed'))");
        $this->addSql("CREATE UNIQUE INDEX uniq_current_event_per_type ON event_occurrences (type) WHERE state IN ('upcoming', 'open')");
        $this->addSql('CREATE UNIQUE INDEX uniq_default_event ON event_occurrences ((TRUE)) WHERE is_default');
        $this->addSql("UPDATE event_occurrences SET is_default = TRUE WHERE state = 'open'");
        $this->addSql(self::bridgeFunction("state IN ('open', 'closed') AND type = 'halloween' AND starts_on = ends_on ORDER BY starts_on DESC, id DESC LIMIT 1"));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_default_event');
        $this->addSql('DROP INDEX uniq_current_event_per_type');
        $this->addSql('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_default_public');
        $this->addSql('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_dates_tbd');
        $this->addSql('ALTER TABLE event_occurrences DROP CONSTRAINT event_occurrences_state_check');
        // One event may be active in 0.11: the default if it is open, else the newest open one.
        $this->addSql(<<<'SQL'
            UPDATE event_occurrences SET state = 'active' WHERE id = (
                SELECT id FROM event_occurrences WHERE state = 'open' ORDER BY is_default DESC, starts_on DESC, id DESC LIMIT 1)
            SQL);
        $this->addSql("UPDATE event_occurrences SET state = 'draft', activated_at = NULL WHERE state IN ('open', 'upcoming')");
        $this->addSql("UPDATE event_occurrences SET state = 'archived', archived_at = COALESCE(archived_at, closed_at, NOW()) WHERE state = 'closed'");
        $this->addSql('UPDATE event_occurrences SET starts_on = created_at::date, ends_on = created_at::date WHERE starts_on IS NULL');
        $this->addSql('ALTER TABLE event_occurrences ALTER COLUMN starts_on SET NOT NULL');
        $this->addSql('ALTER TABLE event_occurrences ALTER COLUMN ends_on SET NOT NULL');
        $this->addSql("ALTER TABLE event_occurrences ADD CONSTRAINT event_occurrences_state_check CHECK (state IN ('draft', 'active', 'archived'))");
        $this->addSql('ALTER TABLE event_occurrences DROP COLUMN closed_at');
        $this->addSql('ALTER TABLE event_occurrences DROP COLUMN dates_tentative');
        $this->addSql('ALTER TABLE event_occurrences DROP COLUMN is_default');
        $this->addSql("CREATE UNIQUE INDEX uniq_one_active_event ON event_occurrences ((TRUE)) WHERE state = 'active'");
        $this->addSql(self::bridgeFunction("state = 'active' AND type = 'halloween' AND starts_on = ends_on"));
    }

    /**
     * The reverse bridge (Version20261031000002): rows the previous release writes to `listings` reach the event the
     * legacy table mirrors, chosen by $mirrored (a WHERE clause on event_occurrences).
     */
    private static function bridgeFunction(string $mirrored): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION listings_bridge_to_events() RETURNS trigger LANGUAGE plpgsql AS \$fn\$
            DECLARE
                active_id BIGINT;
                active_day DATE;
                listing BIGINT;
            BEGIN
                IF current_setting('nhm.bridge_writer', true) = 'events' THEN
                    RETURN NULL; -- the app's own mirror of the event tables
                END IF;
                SELECT id, starts_on INTO active_id, active_day FROM event_occurrences
                 WHERE {$mirrored};
                IF active_id IS NULL THEN
                    RETURN NULL;
                END IF;
                IF TG_OP <> 'INSERT' THEN
                    IF OLD.event_date = active_day THEN
                        IF TG_OP = 'DELETE' THEN
                            DELETE FROM event_listings WHERE event_id = active_id AND address_id = OLD.address_id;
                        ELSIF NEW.event_date <> active_day OR NEW.address_id <> OLD.address_id THEN
                            DELETE FROM event_listings WHERE event_id = active_id AND address_id = OLD.address_id;
                        END IF;
                    END IF;
                END IF;
                IF TG_OP <> 'DELETE' THEN
                    IF NEW.event_date = active_day THEN
                        INSERT INTO event_listings (event_id, address_id, details, status, status_date, note, published, moderation_hidden, moderation_reason, published_at, updated_at)
                        VALUES (active_id, NEW.address_id, NEW.details, NEW.status, NULL, NEW.note, NEW.published, NEW.moderation_hidden, NEW.moderation_reason, NEW.published_at, NEW.updated_at)
                        ON CONFLICT (event_id, address_id) DO UPDATE SET details = EXCLUDED.details, status = EXCLUDED.status, status_date = NULL, note = EXCLUDED.note,
                            published = EXCLUDED.published, moderation_hidden = EXCLUDED.moderation_hidden, moderation_reason = EXCLUDED.moderation_reason,
                            published_at = EXCLUDED.published_at, updated_at = EXCLUDED.updated_at
                        RETURNING id INTO listing;
                        DELETE FROM event_listing_windows WHERE listing_id = listing AND local_date <> active_day;
                        INSERT INTO event_listing_windows (listing_id, local_date, start_local, end_local) VALUES (listing, active_day, NEW.start_local, NEW.end_local)
                        ON CONFLICT (listing_id, local_date) DO UPDATE SET start_local = EXCLUDED.start_local, end_local = EXCLUDED.end_local;
                    END IF;
                END IF;
                RETURN NULL;
            END
            \$fn\$
            SQL;
    }
}
