<?php

declare(strict_types=1);

namespace App\About;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @module About/ChangelogReader
 * @description Reads the latest releases from CHANGELOG.md (Keep a Changelog format) for the public
 *     "What's new" dialog and page. Markdown is reduced to plain text (links keep their label); Twig
 *     escapes the result. The empty "Unreleased" section is skipped.
 *
 * @input path to CHANGELOG.md
 * @output list<array{version:string, date:?string, sections:list<array{title:string, items:list<string>}>}>
 * @dependencies none
 */
final class ChangelogReader
{
    /** @var array<string, list<array{version:string, date:?string, sections:list<array{title:string, items:list<string>}>}>> */
    private array $memo = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/CHANGELOG.md')]
        private readonly string $file,
    ) {
    }

    /** @return list<array{version:string, date:?string, sections:list<array{title:string, items:list<string>}>}> */
    public function latest(int $limit = 3): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $key = (string) filemtime($this->file);
        $this->memo[$key] ??= self::parse((string) file_get_contents($this->file));

        return \array_slice($this->memo[$key], 0, max(1, $limit));
    }

    /** @return list<array{version:string, date:?string, sections:list<array{title:string, items:list<string>}>}> */
    public static function parse(string $markdown): array
    {
        $releases = [];
        foreach (preg_split('~^(?=##\s)~m', $markdown) ?: [] as $block) {
            if (!preg_match('~\A##\s+\[?([^\]\s]+)\]?(?:\s+-\s+(\d{4}-\d{2}-\d{2}))?~', $block, $h)) {
                continue;
            }
            $sections = self::sections($block);
            if ('Unreleased' !== $h[1] || [] !== $sections) {
                $releases[] = ['version' => $h[1], 'date' => $h[2] ?? null, 'sections' => $sections];
            }
        }

        return $releases;
    }

    /** @return list<array{title:string, items:list<string>}> */
    private static function sections(string $release): array
    {
        $sections = [];
        foreach (preg_split('~^(?=###\s)~m', $release) ?: [] as $part) {
            if (!preg_match('~\A###\s+(.+)$~m', $part, $t)) {
                continue;
            }
            $items = [];
            foreach (preg_split('~\R~', $part) ?: [] as $line) {
                if (preg_match('~^[-*]\s+(.+)$~', $line, $m)) {
                    $items[] = self::plain($m[1]);
                } elseif ([] !== $items && preg_match('~^\s{2,}(\S.*)$~', $line, $m)) {
                    $items[\count($items) - 1] .= ' '.self::plain($m[1]);
                }
            }
            $sections[] = ['title' => trim($t[1]), 'items' => $items];
        }

        return $sections;
    }

    private static function plain(string $text): string
    {
        $text = (string) preg_replace('~\[([^\]]+)\]\([^)]*\)~', '$1', $text);

        return trim(str_replace(['**', '`'], '', $text));
    }
}
