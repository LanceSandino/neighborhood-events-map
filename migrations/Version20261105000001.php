<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @module migrations/Version20261105000001
 * @description Logos live in the database (0.19, D-20261105-03), so a backup and a restore include them: one row per
 *     owner, the community (event_id NULL) or an event. Up also copies the logo the previous releases keep in the
 *     `branding` volume (the stored settings' `branding.logo` names the file) into the community's row and the row of
 *     every Halloween event, so an upgraded Halloween page looks unchanged. The volume file stays where it is and the
 *     settings key keeps naming it: an image rollback to 0.9 still shows the logo. Without a named, readable file there
 *     is nothing to copy. Down drops the table; the volume and the key are what the earlier releases read.
 */
final class Version20261105000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Logos in the database: the community\'s and each event\'s; an existing volume logo becomes the community\'s and every Halloween\'s';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE logos (
            id BIGSERIAL PRIMARY KEY,
            event_id BIGINT NULL REFERENCES event_occurrences (id) ON DELETE CASCADE,
            extension TEXT NOT NULL CHECK (extension IN ('svg', 'png', 'jpg', 'webp')),
            content BYTEA NOT NULL,
            sha256 CHAR(64) NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL,
            CONSTRAINT uniq_logo_owner UNIQUE NULLS NOT DISTINCT (event_id))");

        $stored = $this->connection->fetchOne('SELECT settings FROM community_settings WHERE id = 1');
        $name = false === $stored ? null : (json_decode((string) $stored, true, 64, \JSON_THROW_ON_ERROR)['branding']['logo'] ?? null);
        if (!\is_string($name) || !preg_match('~^logo\.(svg|png|jpg|webp)$~', $name, $m)) {
            return;
        }
        $file = \dirname(__DIR__).'/var/branding/'.$name;
        $bytes = is_file($file) ? file_get_contents($file) : false;
        if (false === $bytes || '' === $bytes) {
            return;
        }
        $params = [$m[1], base64_encode($bytes), hash('sha256', $bytes)];
        $this->addSql("INSERT INTO logos (event_id, extension, content, sha256, updated_at) VALUES (NULL, ?, decode(?, 'base64'), ?, NOW())", $params);
        $this->addSql("INSERT INTO logos (event_id, extension, content, sha256, updated_at) SELECT id, ?, decode(?, 'base64'), ?, NOW() FROM event_occurrences WHERE type = 'halloween'", $params);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE logos');
    }
}
