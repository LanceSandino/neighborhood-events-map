<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Deploy/LockedSecurityFloorsTest
 * @description Locked PHP packages never fall back below a release that fixed a published advisory. composer audit
 *   (CI and the daily check) finds advisories that are known today; this pins the fixes already taken, so a lock
 *   file regenerated from an older branch or cache cannot bring a fixed flaw back.
 * @dependencies composer.lock
 */
#[CoversNothing]
final class LockedSecurityFloorsTest extends TestCase
{
    /**
     * Package => first release with the fixes, and what they fixed.
     * twig/twig 3.31.1 (23–24 November 2026): CVE-2026-133763 to CVE-2026-133768 and CVE-2026-133770 (sandbox
     * policy and allow-list bypasses), CVE-2026-133771 (missing autoescaping for a variable named like a macro
     * import) and CVE-2026-133772.
     */
    private const FLOORS = [
        'twig/twig' => '3.31.1',
    ];

    public function testLockedPackagesCarryTheirSecurityFixes(): void
    {
        $lock = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/composer.lock'), true, flags: \JSON_THROW_ON_ERROR);
        $installed = [];
        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            $installed[$package['name']] = ltrim($package['version'], 'v');
        }
        foreach (self::FLOORS as $name => $floor) {
            self::assertArrayHasKey($name, $installed, "$name is locked");
            self::assertTrue(version_compare($installed[$name], $floor, '>='), "$name {$installed[$name]} is older than $floor");
        }
    }
}
