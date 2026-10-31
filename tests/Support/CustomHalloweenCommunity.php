<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\EventLifecycle;

/**
 * @module tests/Support/CustomHalloweenCommunity
 * @description A fictional community whose organizer gave the community its own Halloween branding before branding
 *   belonged to events (0.9–0.15: emoji, a ghost headline, a trick-or-treat intro, colours and an About text stored in
 *   the community settings), with Halloween, Christmas lights and a yard sale open at once — the shape of the report
 *   that led to per-event branding (ADR 0012). The branding is written straight into the stored settings, as those
 *   releases stored it, so the same data can be built on any release. Fictional: Fernbrook Glen, Larkfield.
 * @dependencies tests/Support/AppTestCase, Event/EventLifecycle
 */
trait CustomHalloweenCommunity
{
    /** The custom Halloween branding as 0.15 stored it in the community settings (the accent part kept the default). */
    private const CUSTOM_BRANDING = [
        'brand_emoji' => '🎃',
        'headline' => '👻 Tiny ghosts.',
        'headline_accent' => 'Big adventures.',
        'intro' => 'Grab a bucket, follow the porch lights and find the candy stops of Fernbrook Glen — skip the scares if you like.',
        'primary_color' => '#D9652B',
        'accent_color' => '#524077',
    ];
    private const CUSTOM_ABOUT = "Fernbrook Glen's trick-or-treat night: porches with a light on hand out candy, and a few yards go all out with ghosts and pumpkins.\n\nPlease stay on the sidewalks and keep an eye on little ones.";

    /**
     * The community, its custom Halloween branding in the stored settings, and Christmas lights and a yard sale opened
     * next to the open Halloween (the default event).
     *
     * @return array{halloween:int, lights:int, sale:int}
     */
    private function customHalloweenCommunity(): array
    {
        $this->configureCommunity(['community' => ['id' => 'fernbrook-glen', 'name' => 'Fernbrook Glen', 'locality' => 'Larkfield', 'region' => 'OR']]);
        $organizer = (int) $this->db()->fetchOne("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('branding-organizer@example.test', TRUE, NOW()) RETURNING id");
        $events = static::getContainer()->get(EventLifecycle::class);
        $lights = $events->createDraft(['type' => 'holiday_lights', 'name' => 'Christmas Lights', 'starts_on' => '2026-11-27', 'ends_on' => '2027-01-01',
            'default_start' => '18:00', 'default_end' => '22:00'], $organizer)->id;
        $sale = $events->createDraft(['type' => 'garage_sale', 'name' => 'Yard Sale', 'starts_on' => '2027-04-10', 'ends_on' => '2027-04-11',
            'default_start' => '08:00', 'default_end' => '14:00'], $organizer)->id;
        $events->open($lights, $organizer);
        $events->open($sale, $organizer);
        // Last: a release with per-event branding rewrites these keys (its 0.9 mirror) whenever events change.
        $this->storeLegacyBranding(self::CUSTOM_BRANDING, self::CUSTOM_ABOUT);

        return ['halloween' => (int) $this->db()->fetchOne("SELECT id FROM event_occurrences WHERE type = 'halloween'"), 'lights' => $lights, 'sale' => $sale];
    }

    /**
     * Writes branding and the About text into the stored community settings exactly where 0.9–0.15 kept them.
     *
     * @param array<string,string> $branding
     */
    private function storeLegacyBranding(array $branding, string $about): void
    {
        // 0.9–0.15 had no general About text of the community (community.general_about, 0.16).
        $this->db()->executeStatement("UPDATE community_settings SET settings = jsonb_set(jsonb_set(settings, '{branding}', settings->'branding' || CAST(:b AS JSONB)),
            '{community,about}', to_jsonb(CAST(:a AS TEXT))) #- '{community,general_about}' WHERE id = 1",
            ['b' => json_encode($branding, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), 'a' => $about]);
        $this->repo()->reset();
    }
}
