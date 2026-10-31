<?php

declare(strict_types=1);

namespace App\Tests\Unit\About;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/About/ReleaseConsistencyTest
 * @description The README's status line names the release in VERSION, so the front page never drifts behind the
 *   actual version again (it said 0.8.0-beta.1 while 0.9.0-beta.1 was released).
 * @dependencies README.md, VERSION
 */
#[CoversNothing]
final class ReleaseConsistencyTest extends TestCase
{
    public function testTheReadmeStatusNamesTheCurrentVersion(): void
    {
        $root = \dirname(__DIR__, 3);
        $version = trim((string) file_get_contents($root.'/VERSION'));
        $readme = (string) file_get_contents($root.'/README.md');
        self::assertMatchesRegularExpression('~^\*\*Status:\*\* `([^`]+)`~m', $readme, 'the README has a status line');
        preg_match('~^\*\*Status:\*\* `([^`]+)`~m', $readme, $m);
        self::assertSame($version, $m[1], 'README status line and VERSION agree');
    }
}
