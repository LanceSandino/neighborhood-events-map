<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @module migrations/Version20261018000001
 * @description Initial schema: community config/geometry, private address registry, users,
 *     atomic claims, listings, magic-link tokens, PoW challenges, throttles, disputes, audit,
 *     PostgreSQL-backed sessions and an optional routing cache. No PostGIS.
 */
final class Version20261018000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial Neighborhood Halloween schema';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE community_settings (
                id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
                deployment_id TEXT NOT NULL,
                settings JSONB NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                updated_by BIGINT NULL
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE community_geometries (
                id BIGSERIAL PRIMARY KEY,
                kind TEXT NOT NULL CHECK (kind IN ('boundary', 'zone')),
                zone_key TEXT NULL CHECK (zone_key IS NULL OR zone_key ~ '^[a-z0-9][a-z0-9-]{0,47}$'),
                label TEXT NOT NULL,
                geometry JSONB NOT NULL,
                properties JSONB NOT NULL DEFAULT '{}'::jsonb,
                version INT NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                approved_at TIMESTAMPTZ NULL,
                approved_by BIGINT NULL,
                updated_by BIGINT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                CHECK ((kind = 'boundary' AND zone_key IS NULL) OR (kind = 'zone' AND zone_key IS NOT NULL))
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX uniq_active_boundary ON community_geometries (kind) WHERE is_active AND kind = 'boundary'");
        $this->addSql("CREATE UNIQUE INDEX uniq_active_zone ON community_geometries (zone_key) WHERE is_active AND kind = 'zone'");

        $this->addSql(<<<'SQL'
            CREATE TABLE eligible_addresses (
                id BIGSERIAL PRIMARY KEY,
                display_address TEXT NOT NULL CHECK (char_length(display_address) BETWEEN 3 AND 160),
                normalized_address TEXT NOT NULL UNIQUE,
                house_number TEXT NOT NULL,
                street TEXT NOT NULL,
                unit TEXT NULL,
                zone_key TEXT NULL,
                latitude DOUBLE PRECISION NOT NULL CHECK (latitude BETWEEN -90 AND 90),
                longitude DOUBLE PRECISION NOT NULL CHECK (longitude BETWEEN -180 AND 180),
                enabled BOOLEAN NOT NULL DEFAULT TRUE,
                source TEXT NOT NULL CHECK (source IN ('import', 'admin', 'self_entry')),
                inside_boundary BOOLEAN NOT NULL DEFAULT TRUE,
                reviewed_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_addresses_zone ON eligible_addresses (zone_key)');
        $this->addSql('CREATE INDEX idx_addresses_street ON eligible_addresses (street, house_number)');

        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id BIGSERIAL PRIMARY KEY,
                email_normalized TEXT NOT NULL UNIQUE CHECK (char_length(email_normalized) BETWEEN 3 AND 254),
                verified_at TIMESTAMPTZ NULL,
                is_admin BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL,
                last_login_at TIMESTAMPTZ NULL
            )
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE claims (
                address_id BIGINT PRIMARY KEY REFERENCES eligible_addresses (id) ON DELETE RESTRICT,
                user_id BIGINT NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                claimed_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_claims_user ON claims (user_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE claim_intents (
                id UUID PRIMARY KEY,
                address_id BIGINT NULL REFERENCES eligible_addresses (id) ON DELETE CASCADE,
                candidate JSONB NULL,
                email_normalized TEXT NOT NULL,
                listing_draft JSONB NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                consumed_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL,
                CHECK (address_id IS NOT NULL OR candidate IS NOT NULL)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_claim_intents_expiry ON claim_intents (expires_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE listings (
                address_id BIGINT PRIMARY KEY REFERENCES claims (address_id) ON DELETE CASCADE,
                event_date DATE NOT NULL,
                start_local TIME NOT NULL,
                end_local TIME NOT NULL,
                candy BOOLEAN NOT NULL DEFAULT FALSE,
                nonfood BOOLEAN NOT NULL DEFAULT FALSE,
                adult_drinks BOOLEAN NOT NULL DEFAULT FALSE,
                low_noise BOOLEAN NOT NULL DEFAULT FALSE,
                jump_scares BOOLEAN NOT NULL DEFAULT FALSE,
                flashing_lights BOOLEAN NOT NULL DEFAULT FALSE,
                loud_audio BOOLEAN NOT NULL DEFAULT FALSE,
                scare_level TEXT NOT NULL CHECK (scare_level IN ('gentle', 'mild', 'intense')),
                stroller_friendly BOOLEAN NOT NULL DEFAULT FALSE,
                note VARCHAR(400) NOT NULL DEFAULT '',
                status TEXT NOT NULL CHECK (status IN ('active', 'paused', 'out_of_candy', 'done')),
                published BOOLEAN NOT NULL DEFAULT TRUE,
                moderation_hidden BOOLEAN NOT NULL DEFAULT FALSE,
                moderation_reason TEXT NULL,
                published_at TIMESTAMPTZ NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                CHECK (end_local > start_local),
                CHECK (candy OR nonfood OR adult_drinks)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_listings_public ON listings (event_date, status) WHERE published AND NOT moderation_hidden');

        $this->addSql(<<<'SQL'
            CREATE TABLE auth_link_tokens (
                id UUID PRIMARY KEY,
                token_hash CHAR(64) NOT NULL UNIQUE,
                email_normalized TEXT NOT NULL,
                intent_id UUID NULL REFERENCES claim_intents (id) ON DELETE CASCADE,
                purpose TEXT NOT NULL CHECK (purpose IN ('login', 'claim')),
                expires_at TIMESTAMPTZ NOT NULL,
                consumed_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_auth_tokens_expiry ON auth_link_tokens (expires_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE pow_challenges (
                id UUID PRIMARY KEY,
                purpose TEXT NOT NULL CHECK (purpose IN ('login', 'claim', 'dispute')),
                salt CHAR(32) NOT NULL,
                difficulty SMALLINT NOT NULL CHECK (difficulty BETWEEN 1 AND 32),
                failed_attempts INT NOT NULL DEFAULT 0,
                client_hash CHAR(64) NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL,
                consumed_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_pow_expiry ON pow_challenges (expires_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE throttle_events (
                id BIGSERIAL PRIMARY KEY,
                bucket TEXT NOT NULL,
                key_hash CHAR(64) NOT NULL,
                occurred_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_throttle_lookup ON throttle_events (bucket, key_hash, occurred_at)');
        $this->addSql('CREATE INDEX idx_throttle_age ON throttle_events (occurred_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE disputes (
                id BIGSERIAL PRIMARY KEY,
                address_id BIGINT NOT NULL REFERENCES eligible_addresses (id) ON DELETE CASCADE,
                reporter_email TEXT NOT NULL,
                reason TEXT NOT NULL CHECK (char_length(reason) BETWEEN 200 AND 2000),
                status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved', 'rejected')),
                notified_at TIMESTAMPTZ NULL,
                resolution_note TEXT NULL,
                resolved_by BIGINT NULL REFERENCES users (id),
                resolved_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_disputes_status ON disputes (status, created_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE audit_events (
                id BIGSERIAL PRIMARY KEY,
                actor_user_id BIGINT NULL,
                actor_label TEXT NOT NULL,
                action TEXT NOT NULL,
                target_type TEXT NOT NULL,
                target_id TEXT NULL,
                details JSONB NOT NULL DEFAULT '{}'::jsonb,
                source TEXT NOT NULL,
                created_at TIMESTAMPTZ NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_audit_created ON audit_events (created_at DESC)');
        $this->addSql('CREATE INDEX idx_audit_target ON audit_events (target_type, target_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                sess_id VARCHAR(128) NOT NULL PRIMARY KEY,
                sess_data BYTEA NOT NULL,
                sess_lifetime INTEGER NOT NULL,
                sess_time INTEGER NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_sessions_lifetime ON sessions (sess_lifetime)');

        $this->addSql(<<<'SQL'
            CREATE TABLE route_cache (
                fingerprint CHAR(64) PRIMARY KEY,
                provider TEXT NOT NULL,
                profile TEXT NOT NULL,
                response JSONB NOT NULL,
                expires_at TIMESTAMPTZ NOT NULL
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        foreach (['route_cache', 'sessions', 'audit_events', 'disputes', 'throttle_events', 'pow_challenges',
            'auth_link_tokens', 'listings', 'claim_intents', 'claims', 'users', 'eligible_addresses',
            'community_geometries', 'community_settings'] as $table) {
            $this->addSql("DROP TABLE IF EXISTS {$table}");
        }
    }
}
