<?php

declare(strict_types=1);

namespace App\Twig;

use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @module Twig/AppExtension
 * @description Presentation helpers: zone summary chip ("6 phases · 1–3, 5A, 5B, 8"), event badge
 *     text and date ranges, a sale's days in words ("Sat–Sun, April 10–11, 2027") and their weekday names, the season
 *     of a date (by hemisphere), today's local date on the app clock, 12-hour time labels, access facts, and XSS-safe
 *     JSON for non-executable <script type="application/json">.
 *
 * @dependencies Twig, PSR clock
 */
final class AppExtension extends AbstractExtension
{
    public function __construct(private readonly ?ClockInterface $clock = null)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('local_today', [$this, 'localToday']),
            new TwigFunction('zone_summary', [$this, 'zoneSummary']),
            new TwigFunction('event_badge', [$this, 'eventBadge']),
            new TwigFunction('date_range', [$this, 'dateRange']),
            new TwigFunction('access_info', [$this, 'accessInfo']),
            new TwigFunction('zone_areas', [$this, 'zoneAreas']),
            new TwigFunction('day_list', [$this, 'dayList']),
            new TwigFunction('day_names', [$this, 'dayNames']),
            new TwigFunction('season_of', [$this, 'seasonOf']),
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
            new TwigFilter('time_span', [$this, 'timeSpan']),
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

    /** Today's date (YYYY-MM-DD) in a time zone, on the app's clock (e.g. where a season stands). */
    public function localToday(string $timezone): string
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
    }

    /** "OCT 31, 2026" for a one-day event; "DEC 1 – JAN 3" for an event of several days. */
    public function eventBadge(string $date, ?string $endsOn = null): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $e = null === $endsOn || $endsOn === $date ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);
        if (false === $d) {
            return $date;
        }

        return strtoupper(false === $e ? $d->format('M j, Y') : $d->format('M j').' – '.$e->format('M j'));
    }

    /** "Dec 1, 2026 – Jan 3, 2027", "Dec 1 – 24, 2026" or one day "Oct 31, 2026" (plain text; Twig escapes it). */
    public function dateRange(string $from, ?string $to = null): string
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $b = null === $to || $to === $from ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if (false === $a) {
            return $from;
        }
        if (false === $b) {
            return $a->format('M j, Y');
        }
        if ($a->format('Y') !== $b->format('Y')) {
            return $a->format('M j, Y').' – '.$b->format('M j, Y');
        }

        return $a->format('M') === $b->format('M') ? $a->format('M j').' – '.$b->format('j, Y') : $a->format('M j').' – '.$b->format('M j, Y');
    }

    /**
     * Days of an event in words: one day spelled out, consecutive days as a range ("Sat–Sun, April 10–11, 2027"),
     * other days one by one ("Sat, April 10 and Sat, April 17, 2027").
     *
     * @param list<string> $days sorted local dates
     */
    public function dayList(array $days): string
    {
        $d = array_values(array_filter(array_map(static fn ($v) => \is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false, $days)));
        if ([] === $d) {
            return '';
        }
        $first = $d[0];
        $last = $d[\count($d) - 1];
        if (1 === \count($d)) {
            return $first->format('l, F j, Y');
        }
        $consecutive = (int) $first->diff($last)->days === \count($d) - 1;
        if ($consecutive) {
            $span = match (true) {
                $first->format('Y') !== $last->format('Y') => $first->format('F j, Y').' – '.$last->format('F j, Y'),
                $first->format('m') !== $last->format('m') => $first->format('F j').' – '.$last->format('F j, Y'),
                default => $first->format('F j').'–'.$last->format('j, Y'),
            };

            return $first->format('D').'–'.$last->format('D').', '.$span;
        }
        $sameYear = $first->format('Y') === $last->format('Y');
        $parts = array_map(static fn (\DateTimeImmutable $x) => $x->format($sameYear ? 'D, F j' : 'D, F j, Y'), $d);
        $end = array_pop($parts);

        return implode(', ', $parts).' and '.$end.($sameYear ? $last->format(', Y') : '');
    }

    /**
     * Weekday names of days ("Saturday"); when a weekday repeats, every name carries its date ("Saturday, Apr 10").
     *
     * @param list<string> $days
     *
     * @return list<string>
     */
    public function dayNames(array $days): array
    {
        $d = array_values(array_filter(array_map(static fn ($v) => \is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false, $days)));
        $names = array_map(static fn (\DateTimeImmutable $x) => $x->format('l'), $d);
        if (\count(array_unique($names)) === \count($names)) {
            return $names;
        }

        return array_map(static fn (\DateTimeImmutable $x) => $x->format('l, M j'), $d);
    }

    /** The season of a date (spring, summer, fall, winter) by month; south of the equator the other way round. */
    public function seasonOf(string $date, ?float $latitude = null): string
    {
        $month = max(1, min(12, (int) substr($date, 5, 2)));
        $north = [1 => 'winter', 'winter', 'spring', 'spring', 'spring', 'summer', 'summer', 'summer', 'fall', 'fall', 'fall', 'winter'];
        $flip = ['winter' => 'summer', 'summer' => 'winter', 'spring' => 'fall', 'fall' => 'spring'];

        return null !== $latitude && $latitude < 0 ? $flip[$north[$month]] : $north[$month];
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

    /** "6–10 PM" when both times share AM or PM, else "11 AM–1 PM" (events/holiday_lights.js span() says the same). */
    public function timeSpan(string $start, string $end): string
    {
        $a = $this->time12($start);
        $b = $this->time12($end);

        return (str_ends_with($a, 'AM') === str_ends_with($b, 'AM') ? substr($a, 0, -3) : $a).'–'.$b;
    }

    public function bootJson(mixed $data): string
    {
        return json_encode($data, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
