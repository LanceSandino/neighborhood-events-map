<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\RunsMigrations;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * @module tests/Integration/HiddenEventMigrationTest
 * @description The hidden state (migrations/Version20261101000001, D-20261101-01) is additive: up only widens the state
 *   check and the one-current-per-type index — every existing event, upcoming ones included, keeps its state, default
 *   and listings, and what visitors see is unchanged. Down makes every hidden event a draft (organizers only, so nothing
 *   becomes public) with its listings kept, and puts the 0.16 check and index back; up again leaves those drafts as they
 *   are.
 * @dependencies tests/Support/AppTestCase, tests/Support/RunsMigrations
 */
final class HiddenEventMigrationTest extends AppTestCase
{
    use RunsMigrations;

    private const V016 = 'DoctrineMigrations\Version20261031000004';
    private const EVENTS = 'SELECT id, type, name, starts_on, ends_on, state, is_default, dates_tentative FROM event_occurrences ORDER BY id';

    public function testUpKeepsEveryEventAndDownMakesHiddenEventsDrafts(): void
    {
        $this->configureCommunity();
        try {
            $this->migrateTo(self::V016);
            $db = $this->db();
            $db->executeStatement("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at, dates_tentative)
                VALUES ('holiday_lights', 'Lights', '2026-12-01', '2027-01-03', '18:00', '22:00', 'upcoming', NOW(), TRUE), ('garage_sale', 'Sale', NULL, NULL, '08:00', '14:00', 'draft', NOW(), FALSE)");
            $events = $db->fetchAllAssociative(self::EVENTS);
            $public = $this->json('GET', '/api/events')['events'];
            try {
                $db->executeStatement("UPDATE event_occurrences SET state = 'hidden' WHERE type = 'garage_sale'");
                self::fail('0.16 knows no hidden state');
            } catch (DriverException) {
            }

            $this->migrateTo('latest');
            $this->repo()->reset();
            $db = $this->db();
            self::assertSame($events, $db->fetchAllAssociative(self::EVENTS), 'up changes no event: the upcoming lights stay upcoming');
            self::assertSame($public, $this->json('GET', '/api/events')['events'], 'visitors see the same events');
            $sale = (int) $db->fetchOne("SELECT id FROM event_occurrences WHERE type = 'garage_sale'");
            $db->executeStatement("UPDATE event_occurrences SET state = 'hidden' WHERE id = ?", [$sale]);
            $address = $this->addAddress('11', 'Pumpkin Ln', 40.0041, -75.005, 'west');
            $user = (int) $db->fetchOne("INSERT INTO users (email_normalized, verified_at, created_at) VALUES ('ready@example.test', NOW(), NOW()) RETURNING id");
            $db->executeStatement('INSERT INTO claims (address_id, user_id, claimed_at, updated_at) VALUES (?, ?, NOW(), NOW())', [$address, $user]);
            $db->executeStatement("INSERT INTO event_listings (event_id, address_id, details, status, note, published, updated_at) VALUES (?, ?, '{}', 'active', 'Getting ready', TRUE, NOW())", [$sale, $address]);
            try {
                $db->executeStatement("INSERT INTO event_occurrences (type, name, starts_on, ends_on, default_start_local, default_end_local, state, created_at)
                    VALUES ('garage_sale', 'Second', NULL, NULL, '08:00', '14:00', 'upcoming', NOW())");
                self::fail('a hidden event is the current one of its type');
            } catch (UniqueConstraintViolationException) {
            }

            $this->migrateTo(self::V016);
            $db = $this->db();
            self::assertSame(['draft', false], [$db->fetchOne('SELECT state FROM event_occurrences WHERE id = ?', [$sale]), (bool) $db->fetchOne('SELECT is_default FROM event_occurrences WHERE id = ?', [$sale])],
                'down: a hidden event becomes a draft, never public');
            self::assertSame('Getting ready', $db->fetchOne('SELECT note FROM event_listings WHERE event_id = ?', [$sale]), 'its listings are kept');
            self::assertSame($events, $db->fetchAllAssociative(self::EVENTS), 'every other event as before');
        } finally {
            $this->migrateTo('latest');
        }
        $this->repo()->reset();
        self::assertSame('draft', $this->db()->fetchOne("SELECT state FROM event_occurrences WHERE type = 'garage_sale'"), 'up again keeps the draft');
        self::assertNotFalse($this->db()->fetchOne("SELECT 1 FROM pg_indexes WHERE indexname = 'uniq_current_event_per_type' AND indexdef LIKE '%hidden%'"));
    }
}
