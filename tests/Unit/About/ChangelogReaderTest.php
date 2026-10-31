<?php

declare(strict_types=1);

namespace App\Tests\Unit\About;

use App\About\ChangelogReader;
use PHPUnit\Framework\TestCase;

/**
 * Traceability: "What's new" dialog — release notes come from CHANGELOG.md as plain text (newest first,
 * empty Unreleased skipped, markdown links reduced to their label, a plain summary paragraph per release); the real
 * changelog parses and every release it shows has a summary.
 */
final class ChangelogReaderTest extends TestCase
{
    public function testParsesReleasesSectionsAndContinuationLines(): void
    {
        $md = "# Changelog\n\nIntro.\n\n## [Unreleased]\n\n### Added\n- Not released yet.\n\n## [1.2.0] - 2026-10-01\n\n### Added\n- **Bold** feature with `code` and [a link](https://example.org).\n- Long item\n  continued here.\n\n### Fixed\n- A bug.\n\n## [1.1.0] - 2026-09-01\n\n### Changed\n- Older change.\n";
        $r = ChangelogReader::parse($md);
        self::assertSame(['1.2.0', '1.1.0'], array_column($r, 'version'));
        self::assertSame('2026-10-01', $r[0]['date']);
        self::assertSame(['Added', 'Fixed'], array_column($r[0]['sections'], 'title'));
        self::assertSame(['Bold feature with code and a link.', 'Long item continued here.'], $r[0]['sections'][0]['items']);
    }

    public function testEachReleaseMayOpenWithAPlainSummaryParagraph(): void
    {
        $md = "## [2.0.0] - 2026-11-01\n\nEach event has its own look.\nHalloween looks as before.\n\n### Added\n- One.\n\n## [1.0.0] - 2026-10-01\n\n### Fixed\n- Two.\n";
        $r = ChangelogReader::parse($md);
        self::assertSame('Each event has its own look. Halloween looks as before.', $r[0]['summary'], 'the paragraph before the first section, joined into one line');
        self::assertSame('', $r[1]['summary'], 'a release without one');
        self::assertSame([['title' => 'Added', 'items' => ['One.']]], $r[0]['sections']);
    }

    public function testRealChangelogStartsWithTheCurrentVersion(): void
    {
        $root = \dirname(__DIR__, 3);
        $latest = (new ChangelogReader($root.'/CHANGELOG.md'))->latest(2);
        self::assertSame(trim((string) file_get_contents($root.'/VERSION')), $latest[0]['version']);
        self::assertNotEmpty($latest[0]['sections']);
        self::assertCount(2, $latest);
        foreach ((new ChangelogReader($root.'/CHANGELOG.md'))->latest(6) as $release) {
            self::assertNotSame('', $release['summary'], $release['version'].' has a plain summary for visitors and organizers');
        }
        self::assertSame([], (new ChangelogReader($root.'/missing.md'))->latest());
    }
}
