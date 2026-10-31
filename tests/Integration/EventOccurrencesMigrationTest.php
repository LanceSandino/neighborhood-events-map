<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Event\Type\HalloweenType;
use App\Tests\Support\AppTestCase;
use App\Tests\Support\RunsMigrations;

/**
 * @module tests/Integration/EventOccurrencesMigrationTest
 * @description Migration fidelity for event-scoped listings (ROADMAP Stage 2, migrations/Version20261031000002):
 *   on a database seeded the 0.10 way (one `listings` row per address, several past seasons, a future date, hidden,
 *   pending-review, unpublished and out-of-boundary listings, an address under a moderation hold), the backfill makes
 *   one occurrence per event date (the configured one active, older ones archived, a later one a draft), copies every
 *   listing with its window losslessly, and the public projection is exactly what 0.10 showed. Down drops only the
 *   new tables and leaves the legacy rows as they were; up again gives the same result.
 * @dependencies tests/Support/AppTestCase, tests/Support/RunsMigrations
 */
final class EventOccurrencesMigrationTest extends AppTestCase
{
    use RunsMigrations;

    private const BEFORE_EVENTS = 'DoctrineMigrations\Version20261031000001';
    /** The public query of 0.10.0-beta.1 (ListingRepository::PUBLIC_COLUMNS / PUBLIC_WHERE), frozen. */
    private const PUBLIC_010 = 'SELECT a.id, a.display_address, a.zone_key, a.latitude, a.longitude, l.start_local, l.end_local, l.details, l.note, l.status, l.updated_at
        FROM listings l JOIN eligible_addresses a ON a.id = l.address_id
        WHERE l.published AND NOT l.moderation_hidden AND a.enabled AND a.inside_boundary AND l.event_date = :date ORDER BY a.street, a.house_number';
    private const LEGACY_ROW = 'SELECT address_id, event_date, start_local, end_local, details, note, status, published, moderation_hidden, moderation_reason, published_at, updated_at FROM listings ORDER BY address_id';

    public function testTheBackfillKeepsEverySeasonApartAndThePublicMapExactlyAsItWas(): void
    {
        $ids = $this->seedLegacyDatabase();
        $legacy = $this->db()->fetchAllAssociative(self::LEGACY_ROW);
        $public = $this->publicAsIn010('2026-10-31');
        self::assertSame([$ids['open'], $ids['late']], array_column($public, 'id'), 'the seed has two public homes in 0.10');
        try {
            $this->migrateTo('latest');
            $this->assertBackfilled($ids, $legacy, $public);

            $this->migrateTo(self::BEFORE_EVENTS);
            self::assertFalse($this->tableExists('event_occurrences'), 'down drops the new tables');
            self::assertFalse($this->tableExists('event_listings'));
            self::assertFalse($this->tableExists('event_listing_windows'));
            self::assertFalse($this->db()->fetchOne("SELECT 1 FROM pg_proc WHERE proname = 'listings_bridge_to_events'"), 'and the bridge function');
            self::assertEquals($legacy, $this->db()->fetchAllAssociative(self::LEGACY_ROW), 'the legacy rows are untouched by up and down');
        } finally {
            $this->migrateTo('latest');
        }
        $this->repo()->reset();
        $this->assertBackfilled($ids, $legacy, $public);
    }

    public function testWithoutListingsTheConfiguredEventStillBecomesTheActiveOne(): void
    {
        $this->configureCommunity(['event' => ['name' => 'Harvest Night', 'starts_at' => '16:00', 'ends_at' => '19:30']]);
        try {
            $this->migrateTo(self::BEFORE_EVENTS);
        } finally {
            $this->migrateTo('latest');
        }
        $rows = $this->db()->fetchAllAssociative('SELECT type, name, starts_on, ends_on, default_start_local, default_end_local, state, settings FROM event_occurrences');
        self::assertSame([['type' => 'halloween', 'name' => 'Harvest Night', 'starts_on' => '2026-10-31', 'ends_on' => '2026-10-31',
            'default_start_local' => '16:00:00', 'default_end_local' => '19:30:00', 'state' => 'active', 'settings' => '{}']], $rows);
    }

    public function testAnInstallThatIsNotSetUpYetGetsNoEvent(): void
    {
        try {
            $this->migrateTo(self::BEFORE_EVENTS);
            $this->db()->executeStatement('DELETE FROM community_settings');
        } finally {
            $this->migrateTo('latest');
        }
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM event_occurrences'), 'the bootstrap creates the first event later');
    }

    /**
     * @param array<string,int>               $ids
     * @param list<array<string,mixed>>       $legacy
     * @param list<array<string,mixed>>       $public
     */
    private function assertBackfilled(array $ids, array $legacy, array $public): void
    {
        $db = $this->db();
        self::assertSame([
            ['starts_on' => '2024-10-31', 'ends_on' => '2024-10-31', 'state' => 'archived', 'type' => 'halloween', 'name' => 'Halloween', 'default_start_local' => '17:00:00', 'default_end_local' => '21:00:00'],
            ['starts_on' => '2025-10-31', 'ends_on' => '2025-10-31', 'state' => 'archived', 'type' => 'halloween', 'name' => 'Halloween', 'default_start_local' => '17:00:00', 'default_end_local' => '21:00:00'],
            ['starts_on' => '2026-10-31', 'ends_on' => '2026-10-31', 'state' => 'active', 'type' => 'halloween', 'name' => 'Halloween', 'default_start_local' => '17:00:00', 'default_end_local' => '21:00:00'],
            ['starts_on' => '2027-10-31', 'ends_on' => '2027-10-31', 'state' => 'draft', 'type' => 'halloween', 'name' => 'Halloween', 'default_start_local' => '17:00:00', 'default_end_local' => '21:00:00'],
        ], $db->fetchAllAssociative('SELECT starts_on, ends_on, state, type, name, default_start_local, default_end_local FROM event_occurrences ORDER BY starts_on'));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM event_occurrences WHERE (state = 'active') <> (activated_at IS NOT NULL) OR (state = 'archived') <> (archived_at IS NOT NULL)"));

        self::assertSame(\count($legacy), (int) $db->fetchOne('SELECT COUNT(*) FROM event_listings'), 'every listing is copied');
        self::assertSame(\count($legacy), (int) $db->fetchOne('SELECT COUNT(*) FROM event_listing_windows'), 'with exactly one window each');
        $copied = $db->fetchAllAssociative('SELECT el.address_id, w.local_date AS event_date, w.start_local, w.end_local, el.details, el.note, el.status, el.published,
                el.moderation_hidden, el.moderation_reason, el.published_at, el.updated_at
            FROM event_listings el JOIN event_occurrences o ON o.id = el.event_id JOIN event_listing_windows w ON w.listing_id = el.id
            WHERE o.starts_on = w.local_date AND el.status_date IS NULL ORDER BY el.address_id');
        self::assertEquals($legacy, $copied, 'each listing keeps its season, hours, details, note, status, publication and moderation');

        $homes = $this->json('GET', '/api/homes')['homes'];
        $keys09 = array_flip(array_keys($public[0]));
        self::assertSame($public, array_map(static fn (array $h) => array_intersect_key($h, $keys09), $homes), 'the public map is exactly the 0.10 one');
        foreach (['last_season', 'two_seasons_ago', 'next_year', 'hidden', 'pending', 'unpublished', 'outside'] as $name) {
            $this->json('GET', '/api/homes/'.$ids[$name]);
            self::assertSame(404, $this->code(), "$name is not public");
        }
        self::assertTrue((bool) $db->fetchOne('SELECT moderation_hold FROM eligible_addresses WHERE id = ?', [$ids['hidden']]), 'the hold stays on the address');
        $c = $this->json('GET', '/api/event')['event'];
        self::assertSame(['date' => '2026-10-31', 'name' => 'Halloween', 'type' => 'halloween'], array_intersect_key($c, ['date' => 1, 'name' => 1, 'type' => 1]));
    }

    /** @return array<string,int> address ids by role */
    private function seedLegacyDatabase(): array
    {
        $this->configureCommunity();
        $this->migrateTo(self::BEFORE_EVENTS);
        $db = $this->db();
        $user = (int) $db->fetchOne("INSERT INTO users (email_normalized, verified_at, created_at) VALUES ('legacy@example.test', NOW(), NOW()) RETURNING id");
        $ids = [];
        $n = 0;
        $add = function (string $name, string $date, array $over = []) use (&$ids, &$n, $db, $user): void {
            ++$n;
            $id = $this->addAddress((string) (100 + 2 * $n), 'Pumpkin Ln', 40.004 + $n / 10000, -75.005, 'west');
            $db->executeStatement('INSERT INTO claims (address_id, user_id, claimed_at, updated_at) VALUES (?, ?, NOW(), NOW())', [$id, $user]);
            $row = $over + ['start_local' => '17:30', 'end_local' => '20:30', 'candy' => true, 'nonfood' => false, 'adult_drinks' => false, 'low_noise' => true,
                'scare_level' => 'gentle', 'note' => "Home $name", 'status' => 'active', 'published' => true, 'moderation_hidden' => false, 'moderation_reason' => null];
            $db->executeStatement(
                'INSERT INTO listings (address_id, event_date, start_local, end_local, candy, nonfood, adult_drinks, low_noise, jump_scares, flashing_lights, loud_audio,
                    scare_level, stroller_friendly, note, status, published, published_at, updated_at, moderation_hidden, moderation_reason)
                 VALUES (:id, :date, :s, :e, :candy, :nonfood, :adult, :low, FALSE, FALSE, FALSE, :scare, FALSE, :note, :status, :pub, NOW() - INTERVAL \'3 days\', NOW() - (:n * INTERVAL \'1 hour\'), :hidden, :reason)',
                ['id' => $id, 'date' => $date, 's' => $row['start_local'], 'e' => $row['end_local'], 'candy' => $row['candy'], 'nonfood' => $row['nonfood'], 'adult' => $row['adult_drinks'],
                    'low' => $row['low_noise'], 'scare' => $row['scare_level'], 'note' => $row['note'], 'status' => $row['status'], 'pub' => $row['published'], 'n' => $n,
                    'hidden' => $row['moderation_hidden'], 'reason' => $row['moderation_reason']],
                ['candy' => 'boolean', 'nonfood' => 'boolean', 'adult' => 'boolean', 'low' => 'boolean', 'pub' => 'boolean', 'hidden' => 'boolean'],
            );
            $ids[$name] = $id;
        };
        $add('open', '2026-10-31');
        $add('late', '2026-10-31', ['start_local' => '19:00', 'end_local' => '22:30', 'candy' => false, 'nonfood' => true, 'scare_level' => 'intense', 'status' => 'out_of_candy']);
        $add('hidden', '2026-10-31', ['moderation_hidden' => true, 'moderation_reason' => 'Offensive note']);
        $add('pending', '2026-10-31', ['moderation_hidden' => true, 'moderation_reason' => 'pending_review']);
        $add('unpublished', '2026-10-31', ['published' => false, 'status' => 'paused']);
        $add('outside', '2026-10-31');
        $add('last_season', '2025-10-31', ['adult_drinks' => true, 'note' => 'Last year']);
        $add('two_seasons_ago', '2024-10-31', ['status' => 'done']);
        $add('next_year', '2027-10-31');
        $db->executeStatement('UPDATE eligible_addresses SET moderation_hold = TRUE WHERE id IN (?, ?)', [$ids['hidden'], $ids['pending']]);
        $db->executeStatement('UPDATE eligible_addresses SET inside_boundary = FALSE WHERE id = ?', [$ids['outside']]);

        return $ids;
    }

    /**
     * What 0.10.0-beta.1 published for the event date: its query and its home shape (the 0.9 keys).
     *
     * @return list<array<string,mixed>>
     */
    private function publicAsIn010(string $date): array
    {
        $type = new HalloweenType();

        return array_map(static function (array $r) use ($type): array {
            $details = $type->publicDetails(json_decode((string) $r['details'], true, 16, \JSON_THROW_ON_ERROR));

            return ['id' => (int) $r['id'], 'address' => (string) $r['display_address'], 'zone' => $r['zone_key'], 'lat' => round((float) $r['latitude'], 7),
                'lon' => round((float) $r['longitude'], 7), 'start' => substr((string) $r['start_local'], 0, 5), 'end' => substr((string) $r['end_local'], 0, 5)]
                + $details + ['note' => (string) $r['note'], 'status' => (string) $r['status'], 'updated_at' => (new \DateTimeImmutable((string) $r['updated_at']))->format(\DATE_ATOM)];
        }, $this->db()->fetchAllAssociative(self::PUBLIC_010, ['date' => $date]));
    }

    private function tableExists(string $table): bool
    {
        return false !== $this->db()->fetchOne('SELECT 1 FROM information_schema.tables WHERE table_name = ?', [$table]);
    }
}
