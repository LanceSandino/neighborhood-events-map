<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * @module migrations/Version20261031000004
 * @description Per-event branding (ADR 0012, D-20261031-46): until 0.15 the community kept one branding — emoji,
 *     headline and its accent part, intro, the two colours — and one About text for every event, so a community that
 *     made them its own for Halloween showed its ghosts on the Christmas lights and the yard sale. Up copies the values
 *     an organizer changed (a value equal to a built-in default of any event type is left out, as the type defaults
 *     showed it before) and the About text into the settings of every Halloween event that has no branding of its own;
 *     Christmas lights and sales then show their own type's. The community keys themselves stay where they are: the
 *     0.9.0-beta.1 image reads them after an image-only rollback, and this release keeps them equal to the Halloween that
 *     image shows (Community/EventBlockMirror) and never shows them for an event. Data only, no schema change. Down
 *     writes the community keys and About text from the Halloween the 0.9 image shows (the newest open or closed one-day
 *     Halloween, else the newest Halloween) and removes every event's branding and the community's general About text.
 *     The built-in defaults are frozen here as 0.16.0-beta.1 ships them.
 */
final class Version20261031000004 extends AbstractMigration
{
    /** Each type's built-in default per field (Halloween, Christmas lights, garage sale), frozen. */
    private const BUILT_IN = [
        'brand_emoji' => ['🎃', '✨', '🏷️'],
        'headline' => ['Little ghosts.', 'Holiday nights.', 'Good finds.'],
        'headline_accent' => ['Big adventures.', 'Bright lights.', 'Close to home.'],
        'intro' => [
            'Find the treats, skip the scares, and plan a route that feels right for you and your crew—one sweet stop at a time.',
            'Find festive displays, discover quiet viewing options and plan an evening route at your own pace.',
            'Discover neighbors selling furniture, toys, tools and more. Browse categories and build a simple shopping route.',
        ],
        'primary_color' => ['#F07936', '#38597F', '#178D80'],
        'accent_color' => ['#524077', '#CE9D3D', '#E77C5D'],
    ];
    private const COLORS = ['primary_color', 'accent_color'];
    /** The Halloween the 0.9 image shows, else the newest Halloween. */
    private const MIRRORED = "SELECT settings->'branding' AS b FROM event_occurrences WHERE type = 'halloween'
        ORDER BY (state IN ('open', 'closed') AND starts_on = ends_on) DESC, starts_on DESC NULLS LAST, id DESC LIMIT 1";

    public function getDescription(): string
    {
        return 'Per-event branding: the community\'s own Halloween branding and About text move to its Halloween events';
    }

    public function up(Schema $schema): void
    {
        $fields = [];
        foreach (self::BUILT_IN as $key => $defaults) {
            $value = \in_array($key, self::COLORS, true) ? "upper(b->>'$key')" : "b->>'$key'";
            $valid = \in_array($key, self::COLORS, true) ? "b->>'$key' ~* '^#[0-9a-f]{6}$'" : "btrim(COALESCE(b->>'$key', '')) <> ''";
            $fields[] = sprintf("'%s', CASE WHEN %s AND %s NOT IN (%s) THEN %s END", $key, $valid, $value, implode(', ', array_map($this->connection->quote(...), $defaults)), $value);
        }
        $fields[] = "'about', CASE WHEN btrim(COALESCE(s->'community'->>'about', '')) <> '' THEN s->'community'->>'about' END";
        $this->addSql(sprintf(<<<'SQL'
            UPDATE event_occurrences o SET settings = jsonb_set(o.settings, '{branding}', m.branding)
            FROM (SELECT jsonb_strip_nulls(jsonb_build_object(%s)) AS branding
                  FROM (SELECT settings AS s, settings->'branding' AS b FROM community_settings WHERE id = 1) c) m
            WHERE o.type = 'halloween' AND o.settings->'branding' IS NULL AND m.branding <> '{}'::jsonb
            SQL, implode(",\n                ", $fields)));
    }

    public function down(Schema $schema): void
    {
        $fields = [];
        foreach (self::BUILT_IN as $key => $defaults) {
            $fields[] = sprintf("'%s', COALESCE(NULLIF(h.b->>'%s', ''), %s)", $key, $key, $this->connection->quote($defaults[0]));
        }
        $this->addSql(sprintf(<<<'SQL'
            UPDATE community_settings c SET settings = jsonb_set(jsonb_set(c.settings, '{branding}', COALESCE(c.settings->'branding', '{}'::jsonb) || jsonb_build_object(%s)),
                '{community,about}', to_jsonb(COALESCE(NULLIF(h.b->>'about', ''), c.settings->'community'->>'general_about', '')))
            FROM (%s) h WHERE c.id = 1
            SQL, implode(', ', $fields), self::MIRRORED));
        $this->addSql("UPDATE community_settings SET settings = settings #- '{community,general_about}' WHERE id = 1");
        $this->addSql("UPDATE event_occurrences SET settings = settings - 'branding' WHERE settings->'branding' IS NOT NULL");
    }
}
