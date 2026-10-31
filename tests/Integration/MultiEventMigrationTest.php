<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\RunsMigrations;

/**
 * @module tests/Integration/MultiEventMigrationTest
 * @description Several published events (migrations/Version20261031000003, ADR 0011) on the upgrade paths production
 *   takes. From a 0.14 database (an active Halloween with listings, an archived season, drafts of two types): the
 *   active event becomes open and the default, nothing else changes, every listing stays with its event, and the public
 *   homes are exactly the 0.14 ones; down gives the 0.14 states and indexes back and up again gives the same result.
 *   From a 0.9 database (production today: legacy `listings` only, several seasons): every migration in order gives one
 *   open default Halloween with the configured date, every listing copied once, and the public homes keep exactly the
 *   0.9 keys and values.
 * @dependencies tests/Support/AppTestCase, tests/Support/RunsMigrations
 */
final class MultiEventMigrationTest extends AppTestCase
{
    use RunsMigrations;

    private const V014 = 'DoctrineMigrations\Version20261031000002';
    private const V09 = 'DoctrineMigrations\Version20261021000001';
    /** The public query and home shape of 0.9.0-beta.1 (its ListingRepository), frozen. */
    private const PUBLIC_09 = 'SELECT a.id, a.display_address, a.zone_key, a.latitude, a.longitude, l.start_local, l.end_local, l.candy, l.nonfood, l.adult_drinks, l.low_noise,
        l.jump_scares, l.flashing_lights, l.loud_audio, l.scare_level, l.stroller_friendly, l.note, l.status, l.updated_at
        FROM listings l JOIN eligible_addresses a ON a.id = l.address_id
        WHERE l.published AND NOT l.moderation_hidden AND a.enabled AND a.inside_boundary AND l.event_date = :date ORDER BY a.street, a.house_number';
    /** The public query of 0.14.0-beta.1 (the active event's listings), frozen. */
    private const PUBLIC_014 = "SELECT a.id FROM event_listings el JOIN event_occurrences o ON o.id = el.event_id AND o.state = 'active' JOIN eligible_addresses a ON a.id = el.address_id
        WHERE el.published AND NOT el.moderation_hidden AND a.enabled AND a.inside_boundary ORDER BY a.street, a.house_number";
    private const EVENTS = 'SELECT id, type, name, starts_on, ends_on, state, activated_at IS NOT NULL AS activated, archived_at IS NOT NULL AS archived FROM event_occurrences ORDER BY id';
    private const LISTINGS = 'SELECT el.id, el.event_id, el.address_id, el.details, el.status, el.note, el.published, el.moderation_hidden, el.updated_at,
        (SELECT json_agg(json_build_array(w.local_date, w.start_local, w.end_local) ORDER BY w.local_date) FROM event_listing_windows w WHERE w.listing_id = el.id) AS windows
        FROM event_listings el ORDER BY el.id';

    public function testA014DatabaseKeepsEveryEventAndListingAndItsOpenEventBecomesTheDefault(): void
    {
        $this->configureCommunity();
        $homes = [];
        foreach (['11', '13', '15'] as $house) {
            $homes[] = $this->listHome($house, "r$house@example.test");
        }
        $public = $this->json('GET', '/api/homes')['homes'];
        self::assertCount(3, $public);
        try {
            $this->migrateTo(self::V014);
            $db = $this->db();
            self::assertSame(['active'], $db->fetchFirstColumn('SELECT state FROM event_occurrences'), 'down maps open back to active');
            $archived = (int) $db->fetchOne("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, archived_at)
                VALUES ('halloween', 'Halloween', '2025-10-31', '2025-10-31', '17:00', '21:00', 'archived', NOW(), NOW()) RETURNING id");
            $db->executeStatement("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
                VALUES ('halloween', 'Halloween', '2027-10-31', '2027-10-31', '17:00', '21:00', 'draft', NOW()), ('holiday_lights', 'Christmas Lights', '2026-12-01', '2027-01-03', '18:00', '22:00', 'draft', NOW())");
            $old = (int) $db->fetchOne("INSERT INTO event_listings (event_id, address_id, details, status, note, published, updated_at) VALUES (?, ?, '{\"candy\": true}', 'done', 'Last year', TRUE, NOW()) RETURNING id", [$archived, $homes[0]]);
            $db->executeStatement("INSERT INTO event_listing_windows (listing_id, local_date, start_local, end_local) VALUES (?, '2025-10-31', '17:00', '20:00')", [$old]);
            $events = $db->fetchAllAssociative(self::EVENTS);
            $listings = $db->fetchAllAssociative(self::LISTINGS);
            self::assertSame(array_column($public, 'id'), array_map('intval', $db->fetchFirstColumn(self::PUBLIC_014)), '0.14 publishes the same homes');

            $this->migrateTo('latest');
            $this->assertUpgraded($events, $listings, $public);

            $this->migrateTo(self::V014);
            self::assertSame(['active', 'archived', 'draft', 'draft'], $this->db()->fetchFirstColumn('SELECT state FROM event_occurrences ORDER BY id'), 'down gives the 0.14 states back');
            self::assertNotFalse($this->db()->fetchOne("SELECT 1 FROM pg_indexes WHERE indexname = 'uniq_one_active_event'"), 'and the one-active index');
            self::assertFalse($this->db()->fetchOne("SELECT 1 FROM information_schema.columns WHERE table_name = 'event_occurrences' AND column_name = 'is_default'"));
            self::assertEquals($listings, $this->db()->fetchAllAssociative(self::LISTINGS), 'no listing changes on the way down');
        } finally {
            $this->migrateTo('latest');
        }
        $this->repo()->reset();
        $this->assertUpgraded($events, $listings, $public);
    }

    public function testA09DatabaseUpgradesThroughEveryMigrationLosslessly(): void
    {
        $this->configureCommunity();
        try {
            $this->migrateTo(self::V09);
            $db = $this->db();
            self::assertFalse($db->fetchOne("SELECT 1 FROM information_schema.tables WHERE table_name = 'event_occurrences'"), 'the 0.9 schema has no events');
            $user = (int) $db->fetchOne("INSERT INTO users (email_normalized, verified_at, created_at) VALUES ('legacy@example.test', NOW(), NOW()) RETURNING id");
            $n = 0;
            foreach ([['2026-10-31', 'Porch light on', true], ['2026-10-31', 'Ring twice', true], ['2026-10-31', 'Hidden by organizer', false], ['2025-10-31', 'Last year', true]] as [$date, $note, $visible]) {
                ++$n;
                $id = $this->addAddress((string) (40 + 2 * $n), 'Pumpkin Ln', 40.004 + $n / 10000, -75.005, 'west');
                $db->executeStatement('INSERT INTO claims (address_id, user_id, claimed_at, updated_at) VALUES (?, ?, NOW(), NOW())', [$id, $user]);
                $db->executeStatement(
                    "INSERT INTO listings (address_id, event_date, start_local, end_local, candy, nonfood, adult_drinks, low_noise, jump_scares, flashing_lights, loud_audio,
                        scare_level, stroller_friendly, note, status, published, published_at, updated_at, moderation_hidden, moderation_reason)
                     VALUES (?, ?, '17:30', '20:30', TRUE, ?, FALSE, TRUE, FALSE, FALSE, FALSE, 'mild', FALSE, ?, 'active', TRUE, NOW(), NOW() - (? * INTERVAL '1 hour'), ?, ?)",
                    [$id, $date, 1 === $n % 2, $note, $n, !$visible, $visible ? null : 'Offensive'],
                    [2 => 'boolean', 5 => 'boolean'],
                );
            }
            $public09 = array_map(static fn (array $r) => ['id' => (int) $r['id'], 'address' => (string) $r['display_address'], 'zone' => $r['zone_key'],
                'lat' => round((float) $r['latitude'], 7), 'lon' => round((float) $r['longitude'], 7), 'start' => substr((string) $r['start_local'], 0, 5), 'end' => substr((string) $r['end_local'], 0, 5),
                'candy' => (bool) $r['candy'], 'nonfood' => (bool) $r['nonfood'], 'adult_drinks' => (bool) $r['adult_drinks'], 'low_noise' => (bool) $r['low_noise'],
                'jump_scares' => (bool) $r['jump_scares'], 'flashing_lights' => (bool) $r['flashing_lights'], 'loud_audio' => (bool) $r['loud_audio'], 'scare_level' => (string) $r['scare_level'],
                'stroller_friendly' => (bool) $r['stroller_friendly'], 'note' => (string) $r['note'], 'status' => (string) $r['status'],
                'updated_at' => (new \DateTimeImmutable((string) $r['updated_at']))->format(\DATE_ATOM)], $db->fetchAllAssociative(self::PUBLIC_09, ['date' => '2026-10-31']));
            self::assertCount(2, $public09);
            $legacy = (int) $db->fetchOne('SELECT COUNT(*) FROM listings');
        } finally {
            $this->migrateTo('latest');
        }
        $this->repo()->reset();
        $db = $this->db();
        self::assertSame([['halloween', '2025-10-31', 'archived', false], ['halloween', '2026-10-31', 'open', true]],
            array_map(static fn (array $r) => [$r['type'], $r['starts_on'], $r['state'], (bool) $r['is_default']], $db->fetchAllAssociative('SELECT type, starts_on, state, is_default FROM event_occurrences ORDER BY starts_on')));
        self::assertSame($legacy, (int) $db->fetchOne('SELECT COUNT(*) FROM event_listings'), 'every legacy listing copied once');
        self::assertSame($legacy, (int) $db->fetchOne('SELECT COUNT(*) FROM listings'), 'and the legacy table kept for a rollback');
        $homes = $this->json('GET', '/api/homes')['homes'];
        $keys = array_flip(array_keys($public09[0]));
        self::assertSame($public09, array_map(static fn (array $h) => array_intersect_key($h, $keys), $homes), 'the 0.9 keys and values, in order');
        self::assertSame(array_keys($public09[0]), \array_slice(array_keys($homes[0]), 0, \count($public09[0])));
        self::assertSame(['open', true], [$this->json('GET', '/api/event')['event']['state'], $this->json('GET', '/api/event')['event']['is_default']]);
    }

    /**
     * @param list<array<string,mixed>> $events   the 0.14 rows
     * @param list<array<string,mixed>> $listings the 0.14 rows
     * @param list<array<string,mixed>> $public   what 0.14 published
     */
    private function assertUpgraded(array $events, array $listings, array $public): void
    {
        $db = $this->db();
        $after = $db->fetchAllAssociative(self::EVENTS);
        self::assertSame(array_map(static fn (array $e) => ['active' => 'open'][$e['state']] ?? $e['state'], $events), array_column($after, 'state'), 'active becomes open; nothing else changes');
        self::assertSame(array_map(static fn (array $e) => array_diff_key($e, ['state' => 1]), $events), array_map(static fn (array $e) => array_diff_key($e, ['state' => 1]), $after));
        self::assertSame([(int) $events[0]['id']], array_map('intval', $db->fetchFirstColumn('SELECT id FROM event_occurrences WHERE is_default')), 'the open event is the default');
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM event_occurrences WHERE dates_tentative OR starts_on IS NULL OR closed_at IS NOT NULL'));
        self::assertEquals($listings, $db->fetchAllAssociative(self::LISTINGS), 'every listing stays with its event, windows included');
        self::assertSame($public, $this->json('GET', '/api/homes')['homes'], 'the public homes are exactly the 0.14 ones');
        self::assertNotFalse($db->fetchOne("SELECT 1 FROM pg_indexes WHERE indexname = 'uniq_current_event_per_type'"));
        self::assertNotFalse($db->fetchOne("SELECT 1 FROM pg_indexes WHERE indexname = 'uniq_default_event'"));
        self::assertFalse($db->fetchOne("SELECT 1 FROM pg_indexes WHERE indexname = 'uniq_one_active_event'"));
    }

    private function listHome(string $house, string $email): int
    {
        $id = $this->addAddress($house, 'Pumpkin Ln', 40.004 + (int) $house / 100000, -75.005, 'west');
        $this->confirm($this->requestLink($email, ['address' => $this->byId($id), 'listing' => self::listing(['note' => "Home $house"])]));
        self::assertSame(303, $this->code());
        $this->client->getCookieJar()->clear();

        return $id;
    }
}
