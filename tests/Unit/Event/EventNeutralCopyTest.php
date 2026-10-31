<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Event/EventNeutralCopyTest
 * @description The product is event-neutral (AGENTS.md, D-20261021-18): words of one kind of event (Halloween,
 *   trick-or-treat, candy, treats, scares, pumpkins, ghosts, costumes, Christmas, holiday, rummage or garage sale) appear
 *   only in that event type's module (src/Event/Type, templates/event/<key>, public/assets/js/events,
 *   public/assets/css/events). Everywhere else — legal pages, emails, page titles and meta, the map, My house and the
 *   organizer console — the words come from the event type or name the event (event.name). Checked: the text and
 *   attribute values of every other template (Twig comments and class names left out), every string literal of
 *   src/ and every string of the browser modules outside events/ (comments left out). The few persisted identifiers
 *   that keep a holiday's name (the immutable type keys, the legacy listings columns, the name of settings saved
 *   before event.name existed, D-20261021-18) are listed below with their reason.
 * @dependencies none (reads the repository's files)
 */
#[CoversNothing]
final class EventNeutralCopyTest extends TestCase
{
    private const WORDS = '~\b(halloween|trick[- ]or[- ]treat\w*|candy|candies|treats?|spooky|scary|scares?|pumpkins?|ghosts?|costumes?|christmas|xmas|holidays?|rummage|garage sale|yard sale)\b~i';

    /** String literals that keep a holiday's name on purpose, by file: persisted identifiers, never shown as copy. */
    private const ALLOWED = [
        // the immutable type keys stored with every event and listing (D-20261031-01)
        'src/Event/EventTypeRegistry.php' => ['halloween'],
        // the name of an event saved before event.name existed (D-20261021-18): those events were all Halloween
        'src/Community/CommunitySchema.php' => ['Halloween'],
        // the legacy listings table's Halloween columns, kept for an image-only rollback (D-20261031-03)
        'src/Listing/LegacyListingColumns.php' => ['candy', 'The listings table keeps the Halloween columns; details without "%s" cannot be stored there.', 'candy, nonfood, adult_drinks, low_noise, jump_scares, flashing_lights, loud_audio, scare_level, stroller_friendly',
            ':candy, :nonfood, :adult_drinks, :low_noise, :jump_scares, :flashing_lights, :loud_audio, :scare_level, :stroller_friendly'],
        // filters.js re-exports two Halloween helpers for older imports
        'public/assets/js/lib/filters.js' => ['../events/halloween.js'],
    ];

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** @return list<string> files under $dir with the extension, as repository paths, outside the excluded folders */
    private static function files(string $dir, string $ext, array $excluded): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/'.$dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = substr((string) $file, \strlen(self::root()) + 1);
            if (str_ends_with($path, $ext) && [] === array_filter($excluded, static fn (string $x) => str_starts_with($path, $x))) {
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<string> "path: text" for each text naming a holiday that is not allowed in that file
     */
    private static function offences(string $path, array $texts): array
    {
        $allowed = self::ALLOWED[$path] ?? [];

        return array_values(array_map(static fn (string $t) => $path.': '.$t, array_filter($texts, static fn (string $t) => 1 === preg_match(self::WORDS, $t) && !\in_array($t, $allowed, true))));
    }

    public function testTemplatesOutsideTheTypeModulesNameNoHoliday(): void
    {
        $found = [];
        foreach (self::files('templates', '.twig', ['templates/event/']) as $path) {
            $twig = (string) file_get_contents(self::root().'/'.$path);
            $twig = preg_replace(['~\{#.*?#\}~s', '~\sclass="[^"]*"~'], ['', ''], $twig) ?? '';
            $found = [...$found, ...self::offences($path, array_map('trim', explode("\n", $twig)))];
        }
        self::assertSame([], $found, "Holiday words belong to the event type's copy or fragments:\n".implode("\n", $found));
    }

    public function testServerStringsOutsideTheTypeModulesNameNoHoliday(): void
    {
        $found = [];
        foreach (self::files('src', '.php', ['src/Event/Type/']) as $path) {
            $strings = [];
            foreach (token_get_all((string) file_get_contents(self::root().'/'.$path)) as $token) {
                if (\is_array($token) && \in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                    $strings[] = trim($token[1], '\'"');
                }
            }
            $found = [...$found, ...self::offences($path, $strings)];
        }
        self::assertSame([], $found, "Holiday words belong to the event type:\n".implode("\n", $found));
    }

    public function testBrowserStringsOutsideTheTypeModulesNameNoHoliday(): void
    {
        $found = [];
        foreach (self::files('public/assets/js', '.js', ['public/assets/js/events/']) as $path) {
            $js = (string) file_get_contents(self::root().'/'.$path);
            $js = preg_replace(['~/\*.*?\*/~s', '~(?<![:\'"\\\\])//[^\n]*~'], ['', ''], $js) ?? '';
            preg_match_all('~\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`~s', $js, $m);
            $found = [...$found, ...self::offences($path, array_map(static fn (string $s) => substr($s, 1, -1), $m[0]))];
        }
        self::assertSame([], $found, "Holiday words belong to the event type's module (events/<key>.js):\n".implode("\n", $found));
    }

    public function testTheCheckFindsAHolidayWordWhereItDoesNotBelong(): void
    {
        self::assertSame(['x.twig: Bring your costume'], self::offences('x.twig', ['Bring your costume', 'A neighborhood map']));
        self::assertSame([], self::offences('x.twig', ['holiday_lights', 'Spooky_tokens_no']), 'identifiers with an underscore are not words');
        self::assertSame(['src/Event/EventTypeRegistry.php: Halloween night'], self::offences('src/Event/EventTypeRegistry.php', ['halloween', 'Halloween night']), 'only the listed literal is allowed');
    }
}
