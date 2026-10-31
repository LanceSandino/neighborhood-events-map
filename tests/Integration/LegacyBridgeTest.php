<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @module tests/Integration/LegacyBridgeTest
 * @description Rollback safety (D-20261031-06): while the active event is a one-day Halloween event, the legacy
 *   `listings` table equals the event tables for it after every flow — claim by email link, owner edit, status,
 *   organizer hide/unhide, pending review and its approval, release, account erasure — so the 0.9 image shows the
 *   same homes. In the other direction, rows the 0.9 image writes (insert, update, a move to another day, a delete)
 *   reach the active event's listings, and the app's own mirror writes are not copied back.
 * @dependencies tests/Support/AppTestCase
 */
final class LegacyBridgeTest extends AppTestCase
{
    /** The active event's listings in the legacy row's terms. */
    private const EVENT_SIDE = 'SELECT el.address_id, w.local_date AS event_date, w.start_local, w.end_local, el.details, el.note, el.status, el.published,
            el.moderation_hidden, el.moderation_reason, el.published_at, el.updated_at
        FROM event_listings el JOIN event_occurrences o ON o.id = el.event_id AND o.state = \'active\'
        JOIN event_listing_windows w ON w.listing_id = el.id ORDER BY el.address_id';
    private const LEGACY_SIDE = 'SELECT l.address_id, l.event_date, l.start_local, l.end_local, l.details, l.note, l.status, l.published,
            l.moderation_hidden, l.moderation_reason, l.published_at, l.updated_at
        FROM listings l JOIN event_occurrences o ON o.state = \'active\' AND o.starts_on = l.event_date ORDER BY l.address_id';
    private const FROM_COLUMNS = "jsonb_build_object('candy', candy, 'nonfood', nonfood, 'adult_drinks', adult_drinks, 'low_noise', low_noise,
        'jump_scares', jump_scares, 'flashing_lights', flashing_lights, 'loud_audio', loud_audio, 'scare_level', scare_level, 'stroller_friendly', stroller_friendly)";

    public function testEveryFlowKeepsTheLegacyTableEqualToTheActiveEvent(): void
    {
        $this->configureCommunity(['registration' => ['review_new_addresses' => true]]);
        $a = $this->listHome('11', 'alice@example.test', self::listing(['nonfood' => true]));
        $b = $this->listHome('13', 'bob@example.test', self::listing(['start' => '18:00', 'end' => '21:30', 'adult_drinks' => true]));
        $this->assertBridged('claims by email link');

        $this->signIn('alice@example.test');
        $csrf = $this->csrf();
        $this->json('PATCH', "/api/me/homes/$a", ['start' => '16:30', 'end' => '19:00', 'note' => 'New note', 'details' => ['loud_audio' => true, 'scare_level' => 'intense']], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        $this->assertBridged('owner edit');
        $this->json('PATCH', "/api/me/homes/$a", ['status' => 'out_of_candy'], ['X-CSRF-Token' => $csrf]);
        $this->json('PATCH', "/api/me/homes/$a", ['published' => false], ['X-CSRF-Token' => $csrf]);
        $this->assertBridged('status and visibility');
        self::assertSame(['out_of_candy', false], array_values($this->db()->fetchAssociative('SELECT status, published FROM listings WHERE address_id = ?', [$a]) ?: []));

        // A self-entered home waits for review: hidden with the reason, on both sides.
        $this->client->getCookieJar()->clear();
        $this->confirm($this->requestLink('new@example.test', ['address' => ['house_number' => '104', 'street' => 'Lantern Way', 'lat' => 40.0065, 'lon' => -74.9965], 'listing' => self::listing()]));
        $new = (int) $this->db()->fetchOne("SELECT id FROM eligible_addresses WHERE normalized_address = '104 LANTERN WAY'");
        self::assertSame('pending_review', $this->db()->fetchOne('SELECT moderation_reason FROM listings WHERE address_id = ?', [$new]));
        $this->assertBridged('pending review');

        $this->client->getCookieJar()->clear();
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('org@example.test', TRUE, now())");
        $this->signIn('org@example.test');
        $csrf = $this->csrf();
        $this->json('POST', "/api/admin/listings/$b/hide", ['reason' => 'Offensive note'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(200, $this->code());
        $this->assertBridged('organizer hide');
        self::assertTrue((bool) $this->db()->fetchOne('SELECT moderation_hidden FROM listings WHERE address_id = ?', [$b]));
        $this->json('POST', "/api/admin/listings/$new/unhide", [], ['X-CSRF-Token' => $csrf]);
        $this->json('POST', "/api/admin/listings/$b/unhide", [], ['X-CSRF-Token' => $csrf]);
        $this->assertBridged('approval and unhide');

        $this->client->getCookieJar()->clear();
        $this->signIn('bob@example.test');
        $this->json('DELETE', "/api/me/homes/$b", ['confirm' => 'RELEASE'], ['X-CSRF-Token' => $this->csrf()]);
        self::assertSame(200, $this->code());
        $this->assertBridged('release');
        self::assertFalse($this->db()->fetchOne('SELECT 1 FROM listings WHERE address_id = ?', [$b]));

        $tester = new CommandTester((new Application(static::$kernel ?? static::bootKernel()))->find('app:user:delete'));
        self::assertSame(0, $tester->execute(['email' => 'alice@example.test', '--confirm' => true]), $tester->getDisplay());
        $this->assertBridged('account erasure');
        self::assertSame([$new], array_map('intval', $this->db()->fetchFirstColumn('SELECT address_id FROM listings')));
        self::assertSame([$new], array_map('intval', $this->db()->fetchFirstColumn('SELECT address_id FROM event_listings')));
    }

    public function testRowsThe09ImageWritesReachTheActiveEvent(): void
    {
        $this->configureCommunity();
        $a = $this->listHome('21', 'carol@example.test', self::listing());
        $user = (int) $this->db()->fetchOne('SELECT user_id FROM claims WHERE address_id = ?', [$a]);
        // 0.9 after an image-only rollback: an owner edit (columns only, as 0.9 does), a new claim and listing.
        $this->db()->executeStatement("UPDATE listings SET note = 'Edited on 0.9', candy = FALSE, nonfood = TRUE, start_local = '18:30', status = 'paused', updated_at = NOW() WHERE address_id = ?", [$a]);
        $other = $this->addAddress('23', 'Pumpkin Ln', 40.006, -75.004, 'west');
        $this->db()->executeStatement('INSERT INTO claims (address_id, user_id, claimed_at, updated_at) VALUES (?, ?, NOW(), NOW())', [$other, $user]);
        $this->db()->executeStatement(
            "INSERT INTO listings (address_id, event_date, start_local, end_local, candy, nonfood, adult_drinks, low_noise, jump_scares, flashing_lights,
                loud_audio, scare_level, stroller_friendly, note, status, published, published_at, updated_at, moderation_hidden)
             VALUES (?, '2026-10-31', '17:00', '20:00', TRUE, FALSE, TRUE, FALSE, FALSE, FALSE, FALSE, 'gentle', FALSE, 'Listed on 0.9', 'active', TRUE, NOW(), NOW(), FALSE)",
            [$other],
        );
        $this->assertBridged('0.9 writes');
        $homes = array_column($this->json('GET', '/api/homes')['homes'], null, 'id');
        self::assertSame(['start' => '18:30', 'candy' => false, 'nonfood' => true, 'note' => 'Edited on 0.9', 'status' => 'paused'],
            array_intersect_key($homes[$a], ['note' => 1, 'start' => 1, 'candy' => 1, 'nonfood' => 1, 'status' => 1]));
        self::assertTrue($homes[$other]['adult_drinks']);
        self::assertSame([], array_map('intval', $this->db()->fetchFirstColumn('SELECT address_id FROM listings WHERE details IS DISTINCT FROM '.self::FROM_COLUMNS)), 'details still follow the columns');

        // 0.9 moves a row off the event day (it never does; manual SQL might) or deletes one: it leaves the event.
        $this->db()->executeStatement("UPDATE listings SET event_date = '2025-10-31' WHERE address_id = ?", [$a]);
        $this->db()->executeStatement('DELETE FROM listings WHERE address_id = ?', [$other]);
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        $this->assertBridged('moved and deleted');
        self::assertNotFalse($this->db()->fetchOne('SELECT 1 FROM claims WHERE address_id = ?', [$other]), 'claims are never touched by the bridge');
    }

    public function testTheAppsOwnMirrorIsNotCopiedBackAndAnotherDaysRowsStayOut(): void
    {
        $this->configureCommunity();
        $a = $this->listHome('31', 'dave@example.test', self::listing());
        $id = (int) $this->db()->fetchOne('SELECT id FROM event_listings WHERE address_id = ?', [$a]);
        // A row the old release would write for another day is not this event's.
        $this->db()->executeStatement("UPDATE listings SET event_date = '2027-10-31', note = 'Next year' WHERE address_id = ?", [$a]);
        self::assertFalse($this->db()->fetchOne('SELECT 1 FROM event_listings WHERE address_id = ?', [$a]));
        // The app writes its mirror with nhm.bridge_writer set: the trigger skips it (no second copy, no new id).
        $this->db()->executeStatement("UPDATE listings SET event_date = '2026-10-31' WHERE address_id = ?", [$a]);
        $copied = (int) $this->db()->fetchOne('SELECT id FROM event_listings WHERE address_id = ?', [$a]);
        self::assertNotSame($id, $copied, 'a foreign write back onto the event day is a new copy');
        $this->db()->transactional(function ($db) use ($a): void {
            $db->executeQuery("SELECT set_config('nhm.bridge_writer', 'events', true)");
            $db->executeStatement("UPDATE listings SET note = 'App mirror only' WHERE address_id = ?", [$a]);
        });
        self::assertSame('Next year', $this->db()->fetchOne('SELECT note FROM event_listings WHERE address_id = ?', [$a]), 'the guarded write is not copied back');
    }

    private function assertBridged(string $step): void
    {
        self::assertEquals($this->db()->fetchAllAssociative(self::EVENT_SIDE), $this->db()->fetchAllAssociative(self::LEGACY_SIDE), "$step: the legacy rows equal the active event's listings");
        self::assertSame(
            (int) $this->db()->fetchOne("SELECT COUNT(*) FROM event_listings el JOIN event_occurrences o ON o.id = el.event_id AND o.state = 'active'"),
            (int) $this->db()->fetchOne("SELECT COUNT(*) FROM listings l JOIN event_occurrences o ON o.state = 'active' AND o.starts_on = l.event_date"),
            "$step: one legacy row per listing",
        );
    }

    /** @param array<string,mixed> $listing */
    private function listHome(string $house, string $email, array $listing): int
    {
        $id = $this->addAddress($house, 'Pumpkin Ln', 40.004 + (int) $house / 100000, -75.005, 'west');
        $this->confirm($this->requestLink($email, ['address' => $this->byId($id), 'listing' => $listing]));
        self::assertSame(303, $this->code());
        $this->client->getCookieJar()->clear();

        return $id;
    }
}
