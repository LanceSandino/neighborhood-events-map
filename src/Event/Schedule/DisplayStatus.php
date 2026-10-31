<?php

declare(strict_types=1);

namespace App\Event\Schedule;

/**
 * @module Event/Schedule/DisplayStatus
 * @description The status a visitor sees, derived from the stored status, its status_date, the listing's windows
 *     and "now" in the community's time zone (D-20261031-08). A day-scoped status (lights "off tonight", a sale's
 *     "sold out") holds only on the local date it was set; on any other day the effective status is "active". A
 *     status with its own display key shows that key; "active" shows the window phase of today: inside the window,
 *     later today, ended today, or no window today — never "open" outside a window. Each event type supplies the
 *     rule table (EventType::displayRules()); public/assets/js/lib/schedule.js applies the same rules and both are
 *     tested on the shared fixtures in tests/fixtures/schedule/display-status.json.
 *
 * @dependencies none
 */
final class DisplayStatus
{
    public const PHASES = ['inside', 'later_today', 'ended_today', 'not_today'];

    /**
     * @param array{statuses: array<string, array{day_scoped:bool, display:?string}>, phases: array<string,string>} $rules
     * @param list<array{date:string,start:string,end:string}>                                                   $windows
     */
    public static function derive(array $rules, string $status, ?string $statusDate, array $windows, \DateTimeImmutable $now, \DateTimeZone $tz): string
    {
        $local = $now->setTimezone($tz);
        $today = $local->format('Y-m-d');
        $effective = self::effective($rules, $status, $statusDate, $today);
        $fixed = $rules['statuses'][$effective]['display'] ?? null;
        if (null !== $fixed) {
            return $fixed;
        }

        return $rules['phases'][self::phase($windows, $today, $local->format('H:i'))];
    }

    /** The stored status if it holds on $today (day-scoped statuses only on their own date), else "active". */
    public static function effective(array $rules, string $status, ?string $statusDate, string $today): string
    {
        if (!isset($rules['statuses'][$status])) {
            return 'active';
        }
        if ($rules['statuses'][$status]['day_scoped'] && $statusDate !== $today) {
            return 'active';
        }

        return $status;
    }

    /**
     * Where "now" sits against today's window: inside it (start inclusive, end exclusive), before it, after it, or
     * there is no window today.
     *
     * @param list<array{date:string,start:string,end:string}> $windows
     */
    public static function phase(array $windows, string $today, string $nowHm): string
    {
        foreach ($windows as $w) {
            if ($w['date'] !== $today) {
                continue;
            }
            if ($nowHm < $w['start']) {
                return 'later_today';
            }

            return $nowHm >= $w['end'] ? 'ended_today' : 'inside';
        }

        return 'not_today';
    }
}
