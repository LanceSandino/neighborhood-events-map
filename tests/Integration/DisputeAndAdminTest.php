<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use Symfony\Component\Mime\Email;

/**
 * Traceability: ACCEPTANCE #9, #10, BLUEPRINT §4 (dispute form, admin mail, manual transfer/hide with
 * audit of old/new account ids, organizer role only out-of-band, CSV export).
 */
final class DisputeAndAdminTest extends AppTestCase
{
    private function reason(): string
    {
        return str_repeat('I live here and have the closing documents to show the organizer. ', 4);
    }

    private function claimed(): int
    {
        $this->configureCommunity();
        $id = $this->addAddress('61', 'Lantern Way', 40.005, -74.994, 'east');
        $this->confirm($this->requestLink('squatter@example.test', ['address' => ['address_id' => $id], 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();

        return $id;
    }

    private function admin(): string
    {
        $this->db()->executeStatement("INSERT INTO users (email_normalized, is_admin, created_at) VALUES ('organizer@example.test', TRUE, now()) ON CONFLICT (email_normalized) DO UPDATE SET is_admin = TRUE");
        $this->client->getCookieJar()->clear();
        $this->signIn('organizer@example.test');

        return $this->csrf();
    }

    public function testDisputeNeedsPowStoresTicketAndEmailsOrganizerWithoutRevealingOwner(): void
    {
        $id = $this->claimed();
        $this->json('POST', '/api/disputes', ['address_id' => $id, 'reporter_email' => 'real@example.test', 'reason' => $this->reason()]);
        self::assertSame(400, $this->code());

        $short = $this->json('POST', '/api/disputes', ['address_id' => $id, 'reporter_email' => 'real@example.test', 'reason' => 'too short', 'pow' => $this->solvePow('dispute')]);
        self::assertSame(422, $this->code());
        self::assertArrayHasKey('reason', $short['error']['fields']);

        $r = $this->json('POST', '/api/disputes', ['address_id' => $id, 'reporter_email' => 'Real@Example.test', 'reason' => $this->reason(), 'pow' => $this->solvePow('dispute')]);
        self::assertSame(201, $this->code(), json_encode($r));
        self::assertSame('D-000001', $r['ticket']);
        self::assertStringNotContainsString('squatter', json_encode($r));
        $mail = self::getMailerMessages();
        self::assertCount(1, $mail);
        \assert($mail[0] instanceof Email);
        self::assertSame('organizer@example.test', $mail[0]->getTo()[0]->getAddress());
        self::assertSame('real@example.test', $mail[0]->getReplyTo()[0]->getAddress());
        self::assertStringContainsString('61 Lantern Way', (string) $mail[0]->getSubject());
        self::assertNotNull($this->db()->fetchOne('SELECT notified_at FROM disputes WHERE id = 1'));
    }

    public function testDisputeLimitsPerIp(): void
    {
        $id = $this->claimed();
        for ($i = 0; $i < 3; ++$i) {
            $this->json('POST', '/api/disputes', ['address_id' => $id, 'reporter_email' => "r{$i}@example.test", 'reason' => $this->reason(), 'pow' => $this->solvePow('dispute')]);
            self::assertSame(201, $this->code());
        }
        $this->json('POST', '/api/disputes', ['address_id' => $id, 'reporter_email' => 'r9@example.test', 'reason' => $this->reason(), 'pow' => $this->solvePow('dispute')]);
        self::assertSame(429, $this->code());
    }

    public function testUnclaimedAddressCannotBeDisputed(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('63', 'Lantern Way', 40.005, -74.993, 'east');
        $this->json('POST', '/api/disputes', ['address_id' => $id, 'reporter_email' => 'r@example.test', 'reason' => $this->reason(), 'pow' => $this->solvePow('dispute')]);
        self::assertSame(422, $this->code());
    }

    public function testOrganizerTransfersHidesResolvesAndEverythingIsAudited(): void
    {
        $id = $this->claimed();
        $this->json('POST', '/api/disputes', ['address_id' => $id, 'reporter_email' => 'real@example.test', 'reason' => $this->reason(), 'pow' => $this->solvePow('dispute')]);
        $oldOwner = (int) $this->db()->fetchOne('SELECT user_id FROM claims WHERE address_id = ?', [$id]);
        $csrf = $this->admin();

        $list = $this->json('GET', '/api/admin/disputes?status=open');
        self::assertSame('D-000001', $list['disputes'][0]['ticket']);
        self::assertSame('squatter@example.test', $list['disputes'][0]['owner_email'], 'organizer sees the owner privately');

        $this->json('POST', "/api/admin/claims/$id/transfer", ['email' => 'real@example.test'], ['X-CSRF-Token' => $csrf]);
        self::assertSame(422, $this->code(), 'explicit confirmation required');
        $t = $this->json('POST', "/api/admin/claims/$id/transfer", ['email' => 'real@example.test', 'confirm' => true, 'dispute_id' => 1], ['X-CSRF-Token' => $csrf]);
        self::assertSame($oldOwner, $t['transfer']['from_user_id']);
        $newOwner = (int) $this->db()->fetchOne("SELECT id FROM users WHERE email_normalized = 'real@example.test'");
        self::assertSame($newOwner, $t['transfer']['to_user_id']);

        $this->json('POST', "/api/admin/listings/$id/hide", ['reason' => 'reported'], ['X-CSRF-Token' => $csrf]);
        self::assertSame([], $this->json('GET', '/api/homes')['homes']);
        $this->json('POST', "/api/admin/listings/$id/unhide", [], ['X-CSRF-Token' => $csrf]);
        self::assertCount(1, $this->json('GET', '/api/homes')['homes']);

        $this->json('POST', '/api/admin/disputes/1', ['status' => 'resolved', 'note' => 'Transferred after reviewing documents.'], ['X-CSRF-Token' => $csrf]);
        self::assertSame('resolved', $this->db()->fetchOne('SELECT status FROM disputes WHERE id = 1'));

        $audit = $this->json('GET', '/api/admin/audit')['events'];
        $actions = array_column($audit, 'action');
        foreach (['claim.transferred', 'listing.hidden', 'listing.unhidden', 'dispute.resolved', 'dispute.opened', 'claim.published'] as $a) {
            self::assertContains($a, $actions);
        }
        $transfer = array_values(array_filter($audit, fn ($e) => 'claim.transferred' === $e['action']))[0];
        $details = json_decode($transfer['details'], true);
        self::assertEquals(['from_user_id' => $oldOwner, 'to_user_id' => $newOwner, 'dispute_id' => 1], $details);

        $this->client->request('GET', '/api/admin/export/claims.csv');
        self::assertSame(200, $this->code());
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('real@example.test', $csv);
        self::assertStringStartsWith('address_id,address,zone', $csv);

        $this->json('POST', "/api/admin/claims/$id/unclaim", ['confirm' => true, 'reason' => 'test'], ['X-CSRF-Token' => $csrf]);
        self::assertSame([false], $this->claimedFlags('61 Lantern Way'), 'unclaimed home is available again');
    }

    public function testOrganizerRoleCannotBeSelfAssigned(): void
    {
        $this->configureCommunity();
        $this->signIn('wannabe@example.test');
        $csrf = $this->csrf();
        $this->json('PUT', '/api/admin/community', ['settings' => ['community' => ['name' => 'Hacked']]], ['X-CSRF-Token' => $csrf]);
        self::assertSame(403, $this->code());
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM users WHERE is_admin'));
    }
}
