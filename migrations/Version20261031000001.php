<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @module migrations/Version20261031000001
 * @description Listing details by event type (ADR 0010, D-20261031-03): `listings.details` JSONB, backfilled from
 *     the Halloween columns. The columns stay and the app writes both; until they are retired, a trigger copies
 *     the columns into `details` on every insert and update, so rows written by 0.9 after an image-only rollback
 *     still read correctly once the newer release runs again. Additive: down drops only what up adds.
 */
final class Version20261031000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Listing details JSONB (event types), backfilled from and kept in step with the Halloween columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE listings ADD COLUMN details JSONB NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(details) = 'object')");
        $this->addSql(<<<'SQL'
            UPDATE listings SET details = jsonb_build_object(
                'candy', candy, 'nonfood', nonfood, 'adult_drinks', adult_drinks, 'low_noise', low_noise, 'jump_scares', jump_scares,
                'flashing_lights', flashing_lights, 'loud_audio', loud_audio, 'scare_level', scare_level, 'stroller_friendly', stroller_friendly)
            SQL);
        $this->addSql(<<<'SQL'
            CREATE FUNCTION listings_details_from_columns() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN
                NEW.details := NEW.details || jsonb_build_object(
                    'candy', NEW.candy, 'nonfood', NEW.nonfood, 'adult_drinks', NEW.adult_drinks, 'low_noise', NEW.low_noise,
                    'jump_scares', NEW.jump_scares, 'flashing_lights', NEW.flashing_lights, 'loud_audio', NEW.loud_audio,
                    'scare_level', NEW.scare_level, 'stroller_friendly', NEW.stroller_friendly);
                RETURN NEW;
            END
            $fn$
            SQL);
        $this->addSql('CREATE TRIGGER listings_details_sync BEFORE INSERT OR UPDATE ON listings FOR EACH ROW EXECUTE FUNCTION listings_details_from_columns()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER listings_details_sync ON listings');
        $this->addSql('DROP FUNCTION listings_details_from_columns()');
        $this->addSql('ALTER TABLE listings DROP COLUMN details');
    }
}
