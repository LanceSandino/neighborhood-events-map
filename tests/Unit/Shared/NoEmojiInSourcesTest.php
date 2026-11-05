<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Tests\Support\Glyphs;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @module tests/Unit/Shared/NoEmojiInSourcesTest
 * @description Everything the product draws is its own art (D-20261105-01): no emoji or decorative glyph in a template, a
 *   browser module, a stylesheet's content or a server string; comments are left out. The one exception is listed with its
 *   reason: each type's built-in emoji, kept only for the previous release's image, never shown by this one. Text people
 *   write themselves is data, not source: NoEmojiOnPagesTest shows it is left alone.
 * @dependencies tests/Support/Glyphs
 */
#[CoversNothing]
final class NoEmojiInSourcesTest extends TestCase
{
    /** Server strings that keep a glyph on purpose, by file. */
    private const ALLOWED = [
        // The emoji the 0.9 image shows after an image rollback (Community/EventBlockMirror, D-20261031-49): never drawn here.
        'src/Event/Type/HalloweenCopy.php' => ['🎃'],
        'src/Event/Type/HolidayLightsCopy.php' => ['✨'],
        'src/Event/Type/GarageSaleCopy.php' => ['🏷️'],
    ];

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** @return list<string> repository paths under $dir with the extension */
    private static function files(string $dir, string $ext): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/'.$dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = substr((string) $file, \strlen(self::root()) + 1);
            if (str_ends_with($path, $ext)) {
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents(self::root().'/'.$path);
    }

    /** @param array<string,string> $sources path => source without its comments */
    private static function assertClean(array $sources): void
    {
        $found = [];
        foreach ($sources as $path => $source) {
            foreach (explode("\n", $source) as $i => $line) {
                if ([] !== Glyphs::in($line)) {
                    $found[] = sprintf('%s:%d: %s', $path, $i + 1, trim(mb_substr($line, 0, 120)));
                }
            }
        }
        self::assertSame([], $found, "Use a drawn icon (public/assets/icons) instead of an emoji or a glyph:\n".implode("\n", $found));
    }

    public function testTemplatesDrawNoEmoji(): void
    {
        $sources = [];
        foreach (self::files('templates', '.twig') as $path) {
            $sources[$path] = (string) preg_replace_callback('~\{#.*?#\}~s', static fn (array $m) => str_repeat("\n", substr_count($m[0], "\n")), self::read($path));
        }
        self::assertClean($sources);
    }

    public function testBrowserModulesAndStylesheetsDrawNoEmoji(): void
    {
        $sources = [];
        foreach (self::files('public/assets/js', '.js') as $path) {
            $code = (string) preg_replace('~/\*.*?\*/~s', '', self::read($path));
            $sources[$path] = (string) preg_replace('~^\s*//.*$~m', '', $code);
        }
        foreach (self::files('public/assets/css', '.css') as $path) {
            $sources[$path] = (string) preg_replace('~/\*.*?\*/~s', '', self::read($path));
        }
        self::assertClean($sources);
    }

    public function testServerStringsCarryNoEmoji(): void
    {
        $found = [];
        foreach (self::files('src', '.php') as $path) {
            foreach (token_get_all(self::read($path)) as $token) {
                if (!\is_array($token) || !\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $left = str_replace(self::ALLOWED[$path] ?? [], '', $token[1]);
                if ([] !== Glyphs::in($left)) {
                    $found[] = $path.': '.mb_substr($token[1], 0, 100);
                }
            }
        }
        self::assertSame([], $found, "A drawn icon's id or plain words, not an emoji:\n".implode("\n", $found));
    }

    /** Every icon a type names exists in the sprite, so a typo can never draw an empty box. */
    public function testEveryIconNamedInTheModulesExistsInItsSprite(): void
    {
        preg_match_all('~<symbol id="([a-z0-9-]+)"~', self::read('public/assets/icons/icons.svg').self::read('public/assets/icons/brand.svg').self::read('public/assets/icons/heroes.svg'), $m);
        $sprite = array_flip($m[1]);
        $missing = [];
        foreach ([...self::files('templates', '.twig'), ...self::files('public/assets/js', '.js'), ...self::files('src', '.php')] as $path) {
            preg_match_all('~[\'"](i-[a-z0-9-]+|brand-(?:halloween|lights|sale|community)|hero-(?:halloween|lights|sale))[\'"]~', self::read($path), $names);
            foreach ($names[1] as $name) {
                if (!isset($sprite[$name])) {
                    $missing[] = "$path: $name";
                }
            }
        }
        self::assertSame([], array_values(array_unique($missing)));
    }
}
