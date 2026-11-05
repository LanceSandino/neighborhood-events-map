<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Deploy/RollbackDrillTest
 * @description The rollback path for event-scoped listings is scripted and documented (D-20261031-06): bin/restore.sh
 *   replaces the whole schema in one transaction (a pre-upgrade backup restores cleanly after newer tables with
 *   foreign keys into it were added, and a failed restore changes nothing); bin/drill-event-upgrade.sh runs the
 *   published 0.9.0-beta.1 images, the upgrade, both rollback paths and a second upgrade on a disposable project and
 *   always removes it, checks the Halloween's custom branding on both sides of the image rollback (ADR 0012) and the
 *   automatic close of a past-dated Halloween on the upgrade production takes (0.18); the runbook names both paths and is linked from the release and upgrade runbooks. The drill's
 *   real output is in docs/quality/test-report.md.
 * @dependencies bin/restore.sh, bin/drill-event-upgrade.sh, docs/runbooks/*.md
 */
final class RollbackDrillTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3).'/'.$path);
    }

    public function testRestoreReplacesTheWholeSchemaInOneTransaction(): void
    {
        $restore = self::read('bin/restore.sh');
        self::assertStringContainsString("echo 'DROP SCHEMA public CASCADE;'", $restore);
        self::assertStringContainsString('--single-transaction', $restore);
        self::assertStringContainsString('ON_ERROR_STOP=1', $restore);
        self::assertStringNotContainsString('pg_restore --clean', $restore, 'a --clean restore cannot drop tables that newer tables reference');
        self::assertLessThan(strpos($restore, 'docker compose "$@" stop nginx maintenance app'), strpos($restore, 'app:ledger:export'), 'the ledger is saved before anything stops');
    }

    public function testTheDrillUsesThePublishedPreviousReleaseAndAlwaysCleansUp(): void
    {
        $root = \dirname(__DIR__, 3);
        self::assertTrue(is_executable($root.'/bin/drill-event-upgrade.sh'));
        $drill = self::read('bin/drill-event-upgrade.sh');
        foreach (['ghcr.io/lancesandino/neighborhood-events-map', 'old_tag=0.9.0-beta.1', 'trap cleanup EXIT', 'down -v --remove-orphans', 'bin/backup-now.sh', 'bin/restore.sh',
            'scripts/drill_listing.py', 'project="nhm-drill-$$"'] as $needle) {
            self::assertStringContainsString($needle, $drill);
        }
        foreach (['path (a)', 'path (b)', 'forward again', 'upgrades again'] as $step) {
            self::assertStringContainsString($step, $drill);
        }
    }

    /** Per-event branding (ADR 0012): the 0.9 page still shows the Halloween's custom headline after an image rollback. */
    public function testTheDrillChecksTheCustomHalloweenBrandingAcrossTheRollback(): void
    {
        $drill = self::read('bin/drill-event-upgrade.sh');
        foreach (['Version20261031000004', 'custom="Spooky streets. Sweet treats."', 'open-event --type holiday_lights', 'brand --event-id "$closed_id"',
            'does not show the Halloween\'s custom headline after the image rollback', 'the lights show Halloween\'s branding',
            'the 0.9 keys did not follow the Halloween\'s branding'] as $needle) {
            self::assertStringContainsString($needle, $drill);
        }
        self::assertLessThan(strpos($drill, 'step "5. path (a)'), strpos($drill, 'brand --event-id'), 'the headline changes on the new release, before the rollback');
        self::assertLessThan(strpos($drill, 'custom headline after the image rollback'), strpos($drill, 'step "5. path (a)'));
    }

    /** Hidden events (D-20261101-01): next year's is hidden after closing, stays hidden across the rollback, then is announced. */
    public function testTheDrillChecksNextYearsEventStaysHiddenUntilAnnounced(): void
    {
        $drill = self::read('bin/drill-event-upgrade.sh');
        foreach (['Version20261101000001', '= "hidden 2027-10-31 true"', 'next year\'s hidden Halloween is listed for visitors', 'the hidden event answers the public API',
            'the thank-you page names the hidden event', 'announce --event-id "$next_id"', '= "open,closed,upcoming"'] as $needle) {
            self::assertStringContainsString($needle, $drill);
        }
        self::assertLessThan(strpos($drill, 'announce --event-id'), strpos($drill, 'step "6. forward again'), 'announced only after the rollback and the forward again');
    }

    /** The automatic close (D-20261104-01): the upgrade production takes closes a past-dated Halloween; 0.9 still shows it. */
    public function testTheDrillChecksTheAutomaticCloseAcrossTheRollback(): void
    {
        $drill = self::read('bin/drill-event-upgrade.sh');
        foreach (['EVENTS_AUTO_CLOSE=${4:-0}', 'images "$new_app" "$new_nginx" "$new_tag" 1', 'Closed “Halloween”', '= "cli cli"', 'Past events does not list the closed Halloween',
            'does not show the same homes after the automatic close', 'does not show the Halloween\'s headline after the automatic close', 'the event closed twice',
            'the maintenance loop did not run the automatic close', 'the default did not move from the closed Halloween to the open lights'] as $needle) {
            self::assertStringContainsString($needle, $drill);
        }
        self::assertLessThan(strpos($drill, 'step "9. path (a) after the automatic close'), strpos($drill, 'step "8. the restored database upgrades again as production does'));
        self::assertLessThan(strpos($drill, 'step "10. forward again: nothing closes twice'), strpos($drill, 'step "9. path (a) after the automatic close'));
        foreach (['--access gated_guests', '--access gated_open', 'does not show the community\'s own access'] as $needle) {
            self::assertStringContainsString($needle, $drill, 'an event\'s own access leaves what 0.9 reads alone');
        }
    }

    public function testTheRunbookNamesBothPathsAndIsLinked(): void
    {
        $runbook = self::read('docs/runbooks/event-types-migration.md');
        foreach (['## Path (a): image-only rollback, database kept', '## Path (b): full restore of the pre-upgrade backup', 'bin/drill-event-upgrade.sh', 'Limits of path (a)',
            "doctrine:migrations:migrate 'DoctrineMigrations\\Version20261031000001'"] as $needle) {
            self::assertStringContainsString($needle, $runbook);
        }
        self::assertStringContainsString('event-types-migration.md', self::read('docs/runbooks/release.md'));
        self::assertStringContainsString('event-types-migration.md', self::read('docs/runbooks/upgrade-rollback.md'));
    }
}
