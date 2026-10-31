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
 *   always removes it, and checks the Halloween's custom branding on both sides of the image rollback (ADR 0012); the
 *   runbook names both paths and is linked from the release and upgrade runbooks. The drill's
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
