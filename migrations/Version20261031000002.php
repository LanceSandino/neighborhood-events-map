<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @module migrations/Version20261031000002
 * @description Event-scoped listings (ROADMAP Stage 2, D-20261031-05/-06): event_occurrences (one active at a time,
 *     enforced by a partial unique index), event_listings (one per event and address; claims stay address-scoped)
 *     and event_listing_windows (the days and hours a listing takes part). Additive: the legacy `listings` table and
 *     its trigger stay. Backfill: one Halloween occurrence per distinct listings.event_date (name and hours from the
 *     community settings); the configured date is active, earlier ones archived, later ones drafts; without listings
 *     the configured event still becomes the active one; every listing is copied with one window. A trigger then
 *     mirrors writes that the previous release makes to `listings` (after an image-only rollback) into the active
 *     Halloween occurrence, so going forward again loses nothing; the app's own mirror writes set
 *     nhm.bridge_writer and are skipped. Down drops only what up adds.
 */
final class Version20261031000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event occurrences and event-scoped listings with windows, backfilled from listings; bridge trigger for the previous release';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE event_occurrences (
                id BIGSERIAL PRIMARY KEY,
                type TEXT NOT NULL CHECK (type IN ('halloween', 'holiday_lights', 'garage_sale')),
                name VARCHAR(60) NOT NULL,
                starts_on DATE NOT NULL,
                ends_on DATE NOT NULL CHECK (ends_on >= starts_on),
                default_start_local TIME NOT NULL,
                default_end_local TIME NOT NULL CHECK (default_end_local > default_start_local),
                settings JSONB NOT NULL DEFAULT '{}',
                state TEXT NOT NULL CHECK (state IN ('draft', 'active', 'archived')),
                created_at TIMESTAMPTZ NOT NULL,
                activated_at TIMESTAMPTZ NULL,
                archived_at TIMESTAMPTZ NULL
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX uniq_one_active_event ON event_occurrences ((TRUE)) WHERE state = 'active'");
        $this->addSql('CREATE INDEX idx_event_occurrences_state ON event_occurrences (state, starts_on)');

        $this->addSql(<<<'SQL'
            CREATE TABLE event_listings (
                id BIGSERIAL PRIMARY KEY,
                event_id BIGINT NOT NULL REFERENCES event_occurrences (id) ON DELETE CASCADE,
                address_id BIGINT NOT NULL REFERENCES claims (address_id) ON DELETE CASCADE,
                details JSONB NOT NULL DEFAULT '{}',
                status TEXT NOT NULL,
                status_date DATE NULL,
                note VARCHAR(400) NOT NULL DEFAULT '',
                published BOOLEAN NOT NULL DEFAULT TRUE,
                moderation_hidden BOOLEAN NOT NULL DEFAULT FALSE,
                moderation_reason TEXT NULL,
                published_at TIMESTAMPTZ NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                UNIQUE (event_id, address_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_event_listings_public ON event_listings (event_id) WHERE published AND NOT moderation_hidden');
        $this->addSql('CREATE INDEX idx_event_listings_address ON event_listings (address_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE event_listing_windows (
                listing_id BIGINT NOT NULL REFERENCES event_listings (id) ON DELETE CASCADE,
                local_date DATE NOT NULL,
                start_local TIME NOT NULL,
                end_local TIME NOT NULL CHECK (end_local > start_local),
                PRIMARY KEY (listing_id, local_date)
            )
            SQL);

        // One occurrence per event date the listings know, plus the configured one; ids follow the dates.
        $this->addSql(<<<'SQL'
            WITH cfg AS (
                SELECT COALESCE(NULLIF(LEFT(settings->'event'->>'name', 60), ''), 'Halloween') AS name,
                       COALESCE(NULLIF(settings->'event'->>'starts_at', ''), '17:00')::time AS starts_at,
                       COALESCE(NULLIF(settings->'event'->>'ends_at', ''), '21:00')::time AS ends_at,
                       NULLIF(settings->'event'->>'date', '')::date AS current_date_
                FROM community_settings WHERE id = 1
            ), days AS (
                SELECT DISTINCT event_date AS day FROM listings
                UNION SELECT current_date_ FROM cfg WHERE current_date_ IS NOT NULL
            ), states AS (
                SELECT d.day, CASE WHEN d.day = cfg.current_date_ THEN 'active'
                                   WHEN cfg.current_date_ IS NULL OR d.day < cfg.current_date_ THEN 'archived'
                                   ELSE 'draft' END AS state,
                       COALESCE(cfg.name, 'Halloween') AS name, COALESCE(cfg.starts_at, '17:00') AS starts_at, COALESCE(cfg.ends_at, '21:00') AS ends_at
                FROM days d LEFT JOIN cfg ON TRUE
            )
            INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, settings, state, created_at, activated_at, archived_at)
            SELECT 'halloween', name, day, day, starts_at, ends_at, '{}'::jsonb, state, NOW(),
                   CASE WHEN state = 'active' THEN NOW() END, CASE WHEN state = 'archived' THEN NOW() END
            FROM states ORDER BY day
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO event_listings (event_id, address_id, details, status, status_date, note, published, moderation_hidden, moderation_reason, published_at, updated_at)
            SELECT o.id, l.address_id, l.details, l.status, NULL, l.note, l.published, l.moderation_hidden, l.moderation_reason, l.published_at, l.updated_at
            FROM listings l JOIN event_occurrences o ON o.type = 'halloween' AND o.starts_on = l.event_date AND o.ends_on = l.event_date
            ORDER BY o.id, l.address_id
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO event_listing_windows (listing_id, local_date, start_local, end_local)
            SELECT el.id, l.event_date, l.start_local, l.end_local
            FROM listings l JOIN event_occurrences o ON o.type = 'halloween' AND o.starts_on = l.event_date AND o.ends_on = l.event_date
            JOIN event_listings el ON el.event_id = o.id AND el.address_id = l.address_id
            SQL);

        // The previous release writes only `listings`: carry those writes into the active Halloween occurrence.
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION listings_bridge_to_events() RETURNS trigger LANGUAGE plpgsql AS $fn$
            DECLARE
                active_id BIGINT;
                active_day DATE;
                listing BIGINT;
            BEGIN
                IF current_setting('nhm.bridge_writer', true) = 'events' THEN
                    RETURN NULL; -- the app's own mirror of the event tables
                END IF;
                SELECT id, starts_on INTO active_id, active_day FROM event_occurrences
                 WHERE state = 'active' AND type = 'halloween' AND starts_on = ends_on;
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
            $fn$
            SQL);
        $this->addSql('CREATE TRIGGER listings_bridge_to_events AFTER INSERT OR UPDATE OR DELETE ON listings FOR EACH ROW EXECUTE FUNCTION listings_bridge_to_events()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER listings_bridge_to_events ON listings');
        $this->addSql('DROP FUNCTION listings_bridge_to_events()');
        $this->addSql('DROP TABLE event_listing_windows');
        $this->addSql('DROP TABLE event_listings');
        $this->addSql('DROP TABLE event_occurrences');
    }
}
