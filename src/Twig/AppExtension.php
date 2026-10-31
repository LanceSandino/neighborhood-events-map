<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @module Twig/AppExtension
 * @description Presentation helpers: zone summary chip ("6 phases · 1–3, 5A, 5B, 8"), event badge
 *     text, 12-hour time labels, access facts, and XSS-safe JSON for non-executable <script type="application/json">.
 *
 * @dependencies Twig
 */
final class AppExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('zone_summary', [$this, 'zoneSummary']),
            new TwigFunction('event_badge', [$this, 'eventBadge']),
            new TwigFunction('access_info', [$this, 'accessInfo']),
            new TwigFunction('zone_areas', [$this, 'zoneAreas']),
        ];
    }

    /**
     * Walking areas for the zone filter: each organizer group of active residential zones, named
     * "Main (1–3)" when the group has a label, otherwise "Phases 1–3". Unnamed one-zone groups are left
     * out (the zone itself is already a choice).
     *
     * @param list<array{key:string,label:string,residential:bool,status?:string}> $zones
     * @param list<list<string>>                                                  $groups
     * @param list<string>                                                        $labels
     *
     * @return list<array{value:string, label:string}>
     */
    public function zoneAreas(array $zones, array $groups, array $labels = []): array
    {
        $active = [];
        foreach ($zones as $z) {
            if ($z['residential'] && 'active' === ($z['status'] ?? 'active')) {
                $active[$z['key']] = $z;
            }
        }
        $areas = [];
        foreach ($groups as $i => $keys) {
            $members = array_values(array_filter(array_map(static fn (string $k) => $active[$k] ?? null, $keys)));
            $label = trim((string) ($labels[$i] ?? ''));
            if ([] === $members || (\count($members) < 2 && '' === $label)) {
                continue;
            }
            $summary = $this->zoneSummary($members);
            $range = $summary['sub'] ?? '';
            $word = ucfirst((string) preg_replace('~^\d+\s+~', '', $summary['title'] ?? ''));
            $areas[] = ['value' => 'area:'.$i, 'label' => '' !== $label ? sprintf('%s (%s)', $label, $range) : trim($word.' '.$range)];
        }

        return $areas;
    }

    /**
     * How visitors may reach the homes; the event type's copy names the "gated, open during the event" choice.
     *
     * @param array<string,mixed> $copy the event type's copy (EventType::copy())
     *
     * @return array{icon:string, title:string, text:string}|null
     */
    public function accessInfo(?string $access, array $copy = []): ?array
    {
        return match ($access) {
            'public' => ['icon' => '🚶', 'title' => 'Open to the public', 'text' => 'Public streets: visitors from anywhere are welcome to walk the neighborhood.'],
            'gated_open' => ['icon' => '🚪', 'title' => (string) ($copy['access_gated_open'] ?? 'Gated — open during the event'), 'text' => 'The gates are opened for visitors during the suggested hours. Follow posted rules and any gate staff.'],
            'gated_guests' => ['icon' => '🔐', 'title' => 'Gated — residents and guests', 'text' => 'Only residents and their invited guests can enter. This map does not grant gate access.'],
            'private' => ['icon' => '🏡', 'title' => 'Residents only', 'text' => 'A private event for residents of the neighborhood.'],
            default => null,
        };
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('boot_json', [$this, 'bootJson'], ['is_safe' => ['html']]),
            new TwigFilter('time12', [$this, 'time12']),
        ];
    }

    /**
     * @param list<array{label:string, residential:bool, status?:string}> $zones active residential zones are counted
     *
     * @return array{title:string, sub:string}|null
     */
    public function zoneSummary(array $zones): ?array
    {
        $labels = array_values(array_map(static fn (array $z) => $z['label'], array_filter($zones, static fn (array $z) => $z['residential'] && 'active' === ($z['status'] ?? 'active'))));
        if ([] === $labels) {
            return null;
        }
        $n = \count($labels);
        $prefix = null;
        if (preg_match('~^(\S+)\s~', $labels[0], $m)) {
            $prefix = $m[1].' ';
            foreach ($labels as $l) {
                if (!str_starts_with($l, $prefix)) {
                    $prefix = null;
                    break;
                }
            }
        }
        if (null === $prefix) {
            return ['title' => $n.' '.(1 === $n ? 'zone' : 'zones'), 'sub' => implode(', ', \array_slice($labels, 0, 4)).($n > 4 ? '…' : '')];
        }
        $word = strtolower(rtrim($prefix));
        $suffixes = array_map(static fn (string $l) => substr($l, \strlen($prefix)), $labels);

        return ['title' => $n.' '.$word.(1 === $n ? '' : (str_ends_with($word, 's') ? 'es' : 's')), 'sub' => self::compressRuns($suffixes)];
    }

    /** "1,2,3,5A,5B,8" → "1–3, 5A, 5B, 8" */
    private static function compressRuns(array $items): string
    {
        $out = [];
        $run = [];
        $flush = static function () use (&$run, &$out): void {
            if (\count($run) >= 3) {
                $out[] = $run[0].'–'.end($run);
            } else {
                array_push($out, ...$run);
            }
            $run = [];
        };
        foreach ($items as $it) {
            if (ctype_digit($it) && [] !== $run && ctype_digit((string) end($run)) && (int) $it === (int) end($run) + 1) {
                $run[] = $it;
                continue;
            }
            $flush();
            $run = [$it];
        }
        $flush();

        return implode(', ', $out);
    }

    public function eventBadge(string $date): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return false === $d ? $date : strtoupper($d->format('M j, Y'));
    }

    public function time12(string $hhmm): string
    {
        if (!preg_match('~^(\d{2}):(\d{2})~', $hhmm, $m)) {
            return $hhmm;
        }
        $h = (int) $m[1];
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = 0 === $h % 12 ? 12 : $h % 12;

        return '00' === $m[2] ? $h12.' '.$suffix : $h12.':'.$m[2].' '.$suffix;
    }

    public function bootJson(mixed $data): string
    {
        return json_encode($data, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
