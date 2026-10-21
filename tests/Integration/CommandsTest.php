<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Audit\AuditLog;
use App\Command\AdminGrantCommand;
use App\Security\EmailNormalizer;
use App\Security\UserRepository;
use App\Tests\Support\AppTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Traceability: BLUEPRINT §4 (organizer role only via CLI), §6 (bootstrap/migrations/backups ops),
 * §7 (reviewed CSV import, no invented coordinates), §10 (season archive), demo isolation.
 */
final class CommandsTest extends AppTestCase
{
    private function cmd(string $name, array $input = []): CommandTester
    {
        $app = new Application(static::$kernel ?? static::bootKernel());
        $tester = new CommandTester($app->find($name));
        $tester->execute($input);

        return $tester;
    }

    public function testAdminGrantAndRevoke(): void
    {
        $t = $this->cmd('app:admin:grant', ['email' => 'Boss@Example.test']);
        self::assertSame(0, $t->getStatusCode());
        self::assertStringContainsString('Granted organizer role for boss@example.test', $t->getDisplay());
        self::assertTrue((bool) $this->db()->fetchOne("SELECT is_admin FROM users WHERE email_normalized = 'boss@example.test'"));
        $this->cmd('app:admin:grant', ['email' => 'boss@example.test', '--revoke' => true]);
        self::assertFalse((bool) $this->db()->fetchOne("SELECT is_admin FROM users WHERE email_normalized = 'boss@example.test'"));
        self::assertSame(1, $this->cmd('app:admin:grant', ['email' => 'not-an-email'])->getStatusCode());
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE source = 'cli'"));
    }

    public function testRevokingAnOrganizerListedInTheEnvironmentFailsLoudly(): void
    {
        // Audit run-1 lead 8: ORGANIZER_EMAILS keeps the role, so "Revoked" would be a false success.
        $users = new UserRepository($this->db(), $this->clock, 'boss@example.test');
        $this->cmd('app:admin:grant', ['email' => 'boss@example.test']);
        $t = new CommandTester(new AdminGrantCommand($users, new EmailNormalizer(), static::getContainer()->get(AuditLog::class)));
        $t->execute(['email' => 'boss@example.test', '--revoke' => true]);
        self::assertSame(1, $t->getStatusCode());
        self::assertStringContainsString('still an organizer because ORGANIZER_EMAILS lists it', $t->getDisplay());
        self::assertStringNotContainsString('Revoked organizer role', $t->getDisplay());
        self::assertFalse((bool) $this->db()->fetchOne("SELECT is_admin FROM users WHERE email_normalized = 'boss@example.test'"), 'the database grant is gone');
        self::assertTrue($users->isOrganizerEmail('boss@example.test'), 'and the environment still grants the role');
    }

    public function testBootstrapExportAndReimportRoundTrip(): void
    {
        $t = $this->cmd('app:community:bootstrap', ['file' => 'examples/ventura-reserve/community.yaml']);
        self::assertStringContainsString('pending organizer approval', $t->getDisplay());
        self::assertStringNotContainsString('overlap', $t->getDisplay(), 'the recorded plats do not overlap');
        self::assertStringContainsString('already configured', $this->cmd('app:community:bootstrap', ['file' => 'examples/demo/community.yaml'])->getDisplay());
        self::assertSame(1, $this->cmd('app:community:bootstrap', ['file' => 'missing.yaml', '--force' => true])->getStatusCode());

        $dir = sys_get_temp_dir().'/export-'.bin2hex(random_bytes(3));
        self::assertSame(0, $this->cmd('app:community:export', ['directory' => $dir])->getStatusCode());
        $before = (string) file_get_contents($dir.'/geometry.geojson');
        self::assertStringContainsString('phase-3', $before);
        $t = $this->cmd('app:community:bootstrap', ['file' => $dir.'/community.yaml', '--force' => true]);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        $this->cmd('app:community:export', ['directory' => $dir]);
        self::assertSame($before, (string) file_get_contents($dir.'/geometry.geojson'), 'exported config reproduces the same geometry');
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }

    public function testCsvImportRequiresCoordinatesAndRespectsBoundaryAndZones(): void
    {
        $this->configureCommunity();
        $csv = sys_get_temp_dir().'/addr-'.bin2hex(random_bytes(3)).'.csv';
        file_put_contents($csv, "\u{FEFF}address,latitude,longitude,zone_key\n"
            ."10 Pumpkin Lane,40.005,-75.005,\n"           // zone inferred: west
            ."12 Pumpkin Ln,40.005,-74.995,east\n"
            ."14 Pumpkin Ln,,,west\n"                       // missing coordinates
            ."16 Pumpkin Ln,40.5,-75.005,west\n"            // outside boundary
            ."18 Pumpkin Ln,40.005,-75.005,east\n"          // zone mismatch
            ."20 Pumpkin Ln,40.005,-75.004,phase-5\n"       // unknown zone
            ."Pumpkin Ln,40.005,-75.004,\n"                 // no house number
            ."10 Pumpkin Ln,40.006,-75.006,west\n");        // duplicate (unchanged without --update)
        $t = $this->cmd('app:addresses:import', ['file' => $csv, '--dry-run' => true]);
        self::assertStringContainsString('[dry run] imported 2, updated 0, unchanged 0, skipped 6', $t->getDisplay());
        self::assertStringContainsString('line 9: Duplicate of line 2 in this file.', $t->getDisplay());
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM eligible_addresses'));
        $t = $this->cmd('app:addresses:import', ['file' => $csv]);
        self::assertStringContainsString('imported 2, updated 0, unchanged 0, skipped 6', $t->getDisplay());
        foreach (['Latitude/longitude missing', 'outside the neighborhood boundary', 'does not contain this point', 'Unknown zone "phase-5"', 'house number'] as $reason) {
            self::assertStringContainsString($reason, $t->getDisplay());
        }
        self::assertSame('west', $this->db()->fetchOne("SELECT zone_key FROM eligible_addresses WHERE normalized_address = '10 PUMPKIN LN'"));
        self::assertStringContainsString('imported 0, updated 0, unchanged 2', $this->cmd('app:addresses:import', ['file' => $csv])->getDisplay(), 'idempotent re-run');
        file_put_contents($csv, "address,latitude,longitude,zone_key\n10 Pumpkin Ln,40.006,-75.006,west\n");
        self::assertStringContainsString('unchanged 1', $this->cmd('app:addresses:import', ['file' => $csv])->getDisplay(), 'no silent overwrite');
        $t = $this->cmd('app:addresses:import', ['file' => $csv, '--update' => true]);
        self::assertStringContainsString('updated 1', $t->getDisplay());
        self::assertSame(40.006, (float) $this->db()->fetchOne("SELECT latitude FROM eligible_addresses WHERE normalized_address = '10 PUMPKIN LN'"));
        unlink($csv);
        self::assertSame(1, $this->cmd('app:addresses:import', ['file' => '/nope.csv'])->getStatusCode());
    }

    public function testSeasonArchiveAndPurgeAndPing(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('1', 'Pumpkin Ln', 40.005, -75.005, 'west');
        $this->confirm($this->requestLink('season@example.test', ['address' => $this->byId($id), 'listing' => self::listing()]));
        $this->db()->executeStatement("UPDATE listings SET event_date = '2025-10-31'");
        self::assertStringContainsString('Unpublished 1 listing', $this->cmd('app:season:archive')->getDisplay());
        self::assertFalse((bool) $this->db()->fetchOne('SELECT published FROM listings'));
        $this->clock->sleep(3 * 86400);
        $this->db()->insert('sessions', ['sess_id' => 'expired-session', 'sess_data' => 'x', 'sess_lifetime' => time() - 10, 'sess_time' => time() - 2000], ['sess_data' => 'blob']);
        $this->db()->insert('sessions', ['sess_id' => 'live-session', 'sess_data' => 'x', 'sess_lifetime' => time() + 1800, 'sess_time' => time()], ['sess_data' => 'blob']);
        $this->db()->insert('route_cache', ['fingerprint' => str_repeat('a', 64), 'provider' => 'ors', 'profile' => 'foot-walking', 'response' => '{}',
            'expires_at' => $this->clock->now()->modify('-2 days')->format('Y-m-d H:i:s.uP')]);
        self::assertMatchesRegularExpression('~Purged: \d+ tokens, \d+ PoW challenges, \d+ claim intents, \d+ throttle events, 1 expired sessions, 1 route-cache entries~', $this->cmd('app:maintenance:purge')->getDisplay());
        self::assertSame(['live-session'], $this->db()->fetchFirstColumn("SELECT sess_id FROM sessions WHERE sess_id LIKE '%-session'"));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM auth_link_tokens'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM pow_challenges'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM throttle_events'));
        self::assertSame("ok\n", $this->cmd('app:db:ping')->getDisplay());
    }

    public function testUserDeletionReleasesHomesAndForgetsTheEmail(): void
    {
        $this->configureCommunity();
        $id = $this->addAddress('2', 'Pumpkin Ln', 40.005, -75.004, 'west');
        $this->confirm($this->requestLink('forget-me@example.test', ['address' => $this->byId($id), 'listing' => self::listing()]));
        $this->requestLink('forget-me@example.test'); // a pending token as well
        self::assertStringContainsString('Re-run with --confirm', $this->cmd('app:user:delete', ['email' => 'forget-me@example.test'])->getDisplay());
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claims'));
        $t = $this->cmd('app:user:delete', ['email' => 'forget-me@example.test', '--confirm' => true]);
        self::assertStringContainsString('released 1 home', $t->getDisplay());
        foreach (['claims', 'listings', 'users', 'auth_link_tokens', 'claim_intents'] as $table) {
            self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM $table"), $table);
        }
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_events WHERE details::text LIKE '%forget-me%'"));
        self::assertSame(1, $this->cmd('app:user:delete', ['email' => 'forget-me@example.test'])->getStatusCode());
    }

    public function testDeletionAlsoErasesSomeoneWhoOnlyAskedForAReview(): void
    {
        // Audit run-1 lead 6: a review request needs no account, so its reporter has no users row.
        $this->configureCommunity();
        $id = $this->addAddress('2', 'Pumpkin Ln', 40.005, -75.004, 'west');
        $this->confirm($this->requestLink('owner@example.test', ['address' => $this->byId($id), 'listing' => self::listing()]));
        $this->client->getCookieJar()->clear();
        $this->json('POST', '/api/disputes', $this->byId($id) + ['reporter_email' => 'reporter@example.test',
            'reason' => str_repeat('This is my house and I live here. ', 7), 'pow' => $this->solvePow('dispute')]);
        self::assertSame(201, $this->code());
        $this->requestLink('reporter@example.test'); // a link they never used
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM users WHERE email_normalized = 'reporter@example.test'"));

        $dry = $this->cmd('app:user:delete', ['email' => 'reporter@example.test']);
        self::assertSame(0, $dry->getStatusCode(), $dry->getDisplay());
        self::assertStringContainsString('1 review request', $dry->getDisplay());
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM disputes'));
        $t = $this->cmd('app:user:delete', ['email' => 'reporter@example.test', '--confirm' => true]);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM disputes WHERE reporter_email = 'reporter@example.test'"));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM auth_link_tokens WHERE email_normalized = 'reporter@example.test'"));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claims'), 'the owner keeps their home');
        self::assertSame(1, $this->cmd('app:user:delete', ['email' => 'nobody@example.test'])->getStatusCode(), 'nothing stored: still an error');
    }

    public function testDemoSeedRefusesOutsideDemoMode(): void
    {
        $this->configureCommunity();
        $t = $this->cmd('app:demo:seed');
        self::assertSame(1, $t->getStatusCode());
        self::assertStringContainsString('Refusing', $t->getDisplay());
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM claims'));
    }

    public function testZoneNamesCanBeMovedFromTheCommandLineAndReachThePublicMap(): void
    {
        $this->configureCommunity();
        $t = $this->cmd('app:zone:label', ['zone' => 'park', 'lat' => '40.0051', 'lon' => '-74.9952']);
        self::assertSame(0, $t->getStatusCode(), $t->getDisplay());
        $park = array_values(array_filter($this->json('GET', '/api/community')['zones'], fn ($z) => 'park' === $z['key']))[0];
        self::assertSame([-74.9952, 40.0051], $park['label_at']);
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT count(*) FROM audit_events WHERE action = 'community.zone_label_moved'"));

        $t = $this->cmd('app:zone:label', ['zone' => 'park', '--reset' => true]);
        self::assertSame(0, $t->getStatusCode());
        $park = array_values(array_filter($this->json('GET', '/api/community')['zones'], fn ($z) => 'park' === $z['key']))[0];
        self::assertNull($park['label_at'], 'reset: the map picks the spot again');

        self::assertSame(1, $this->cmd('app:zone:label', ['zone' => 'nowhere', 'lat' => '40', 'lon' => '-75'])->getStatusCode(), 'unknown zone');
        self::assertSame(1, $this->cmd('app:zone:label', ['zone' => 'park', 'lat' => '400', 'lon' => '-75'])->getStatusCode(), 'invalid point');
        self::assertSame(1, $this->cmd('app:zone:label', ['zone' => 'park'])->getStatusCode(), 'a point or --reset is required');
    }
}
