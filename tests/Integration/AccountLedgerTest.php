<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\AppTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Traceability: public-readiness hardening note "a restore brings back deleted accounts and revoked organizers".
 * A restore is simulated by copying rows aside before the deletion and the revocation (the "backup") and putting
 * them back afterwards, with the backup's older (empty) ledger; bin/restore.sh then merges the live ledger and
 * replays it.
 */
final class AccountLedgerTest extends AppTestCase
{
    private const COPIED = ['users', 'claims', 'listings', 'disputes'];

    private function cmd(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel ?? static::bootKernel()))->find($name));
        $tester->execute($input);

        return $tester;
    }

    private function rows(string $sql): int
    {
        return (int) $this->db()->fetchOne($sql);
    }

    protected function tearDown(): void
    {
        foreach (self::COPIED as $t) {
            $this->db()->executeStatement("DROP TABLE IF EXISTS backup_$t");
        }
        parent::tearDown();
    }

    public function testARestoreCannotBringBackADeletedAccountOrARevokedOrganizer(): void
    {
        $this->configureCommunity();
        $home = $this->addAddress('2', 'Pumpkin Ln', 40.005, -75.004, 'west');
        $this->confirm($this->requestLink('leaver@example.test', ['address' => $this->byId($home), 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $this->json('POST', '/api/disputes', $this->byId($home) + ['reporter_email' => 'leaver@example.test',
            'reason' => str_repeat('A note about this home and its listing. ', 6), 'pow' => $this->solvePow('dispute')]);
        self::assertSame(201, $this->code());
        $this->cmd('app:admin:grant', ['email' => 'helper@example.test']);
        $leaver = (int) $this->db()->fetchOne("SELECT id FROM users WHERE email_normalized = 'leaver@example.test'");
        $helper = (int) $this->db()->fetchOne("SELECT id FROM users WHERE email_normalized = 'helper@example.test'");

        // The nightly backup: everything above, and a ledger holding only the grant.
        foreach (self::COPIED as $t) {
            $this->db()->executeStatement("CREATE TABLE backup_$t AS SELECT * FROM $t");
        }
        $backupLedger = $this->cmd('app:ledger:export')->getDisplay();

        $this->clock->sleep(3600);
        self::assertSame(0, $this->cmd('app:user:delete', ['email' => 'leaver@example.test', '--confirm' => true])->getStatusCode());
        $this->cmd('app:admin:grant', ['email' => 'helper@example.test', '--revoke' => true]);
        self::assertSame(['admin.granted', 'user.deleted', 'review.deleted', 'admin.revoked'],
            $this->db()->fetchFirstColumn('SELECT action FROM account_ledger ORDER BY id'));
        $live = $this->cmd('app:ledger:export')->getDisplay();
        self::assertStringNotContainsString('leaver', $live, 'the ledger holds ids and times, no email');

        // The restore: the backup's rows and its own ledger come back.
        $this->db()->executeStatement('TRUNCATE account_ledger');
        foreach (self::COPIED as $t) {
            $this->db()->executeStatement("INSERT INTO $t SELECT * FROM backup_$t b ON CONFLICT DO NOTHING");
        }
        $this->db()->executeStatement('UPDATE users SET is_admin = TRUE WHERE id = ?', [$helper]);
        $this->db()->executeStatement("INSERT INTO account_ledger (id, action, subject_id, at) SELECT (e->>'id')::bigint, e->>'action', (e->>'subject_id')::bigint, (e->>'at')::timestamptz FROM jsonb_array_elements(?::jsonb -> 'ledger') e",
            [trim($backupLedger)]);
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM users WHERE email_normalized = 'leaver@example.test'"), 'the deleted account is back');

        $file = tempnam(sys_get_temp_dir(), 'ledger');
        file_put_contents($file, $live);
        $replay = $this->cmd('app:ledger:replay', ['--merge' => $file]);
        unlink($file);
        self::assertSame(0, $replay->getStatusCode(), $replay->getDisplay());
        self::assertStringContainsString('3 entries merged, 1 account(s) and 1 review request(s) erased again, 1 organizer role(s) corrected', $replay->getDisplay());
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM users WHERE email_normalized = 'leaver@example.test'"));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM claims WHERE user_id = '.$leaver));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM listings'));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM disputes WHERE reporter_email = 'leaver@example.test'"));
        self::assertFalse((bool) $this->db()->fetchOne('SELECT is_admin FROM users WHERE id = ?', [$helper]), 'the revocation holds');
        self::assertSame(4, $this->rows('SELECT COUNT(*) FROM account_ledger'));

        // New entries continue after the merged ones, and replaying again changes nothing.
        $this->cmd('app:admin:grant', ['email' => 'helper@example.test']);
        self::assertSame(5, $this->rows('SELECT MAX(id) FROM account_ledger'));
        $this->cmd('app:admin:grant', ['email' => 'helper@example.test', '--revoke' => true]);
        self::assertStringContainsString('0 entries merged, 0 account(s) and 0 review request(s) erased again, 0 organizer role(s) corrected',
            $this->cmd('app:ledger:replay')->getDisplay());
    }

    public function testAnEntryNeverTouchesARowCreatedAfterIt(): void
    {
        $this->configureCommunity();
        $this->cmd('app:admin:grant', ['email' => 'gone@example.test']);
        $id = (int) $this->db()->fetchOne("SELECT id FROM users WHERE email_normalized = 'gone@example.test'");
        $this->cmd('app:user:delete', ['email' => 'gone@example.test', '--confirm' => true]);
        // After an older restore the id sequence starts lower, so a later sign-up can get the deleted account's id.
        $this->clock->sleep(60);
        $this->db()->executeStatement('INSERT INTO users (id, email_normalized, created_at) VALUES (?, ?, ?)', [$id, 'newcomer@example.test', $this->clock->now()->format('Y-m-d H:i:sP')]);
        $this->cmd('app:ledger:replay');
        self::assertSame('newcomer@example.test', $this->db()->fetchOne('SELECT email_normalized FROM users WHERE id = ?', [$id]));
    }

    public function testAMalformedSavedLedgerChangesNothing(): void
    {
        $this->configureCommunity();
        $this->cmd('app:admin:grant', ['email' => 'helper@example.test']);
        foreach (['{"ledger":[{"id":"1; DROP TABLE users","action":"user.deleted","subject_id":1,"at":"2026-10-31"}]}',
            '{"ledger":[{"id":9,"action":"user.promoted","subject_id":1,"at":"2026-10-31"}]}', 'not json'] as $bad) {
            $file = tempnam(sys_get_temp_dir(), 'ledger');
            file_put_contents($file, $bad);
            $t = $this->cmd('app:ledger:replay', ['--merge' => $file]);
            unlink($file);
            self::assertSame(1, $t->getStatusCode(), $bad);
            self::assertStringContainsString('not usable', $t->getDisplay());
        }
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM account_ledger'));
        self::assertTrue((bool) $this->db()->fetchOne("SELECT is_admin FROM users WHERE email_normalized = 'helper@example.test'"));
    }
}
