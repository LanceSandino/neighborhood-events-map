/**
 * @module assets/js/lib/schedule
 * @description The display status of a listing in the browser, by the same rules as src/Event/Schedule/DisplayStatus.php
 *     (D-20261031-08): a day-scoped status holds only on its own local date, a status with its own display key shows it,
 *     and "active" shows today's window phase in the community's time zone (inside, later today, ended today, no window
 *     today) — never "open" outside a window. The event type module supplies the rules (displayRules). Tested on the
 *     shared fixtures in tests/fixtures/schedule/display-status.json. Also the dates of an event and where a season
 *     stands on a given day (before, during, after).
 * @dependencies lib/clock.js
 */
import { nowInZone } from './clock.js';

export const PHASES = ['inside', 'later_today', 'ended_today', 'not_today'];

const hm = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

/** The stored status if it holds on `today` (day-scoped ones only on their own date), else "active". */
export function effectiveStatus(rules, status, statusDate, today) {
  const s = Object.hasOwn(rules.statuses, status) ? rules.statuses[status] : null;
  if (!s) return 'active';
  if (s.day_scoped && statusDate !== today) return 'active';
  return status;
}

/** Where `nowHm` sits against today's window (start inclusive, end exclusive). */
export function phase(windows, today, nowHm) {
  const w = (windows || []).find((x) => x.date === today);
  if (!w) return 'not_today';
  if (nowHm < w.start) return 'later_today';
  return nowHm >= w.end ? 'ended_today' : 'inside';
}

/** @returns {string} the display key for a listing {status, status_date, windows} at `now` in `timeZone` */
export function displayStatus(rules, listing, now, timeZone) {
  const n = nowInZone(timeZone, now);
  const effective = effectiveStatus(rules, listing.status, listing.status_date ?? null, n.date);
  const fixed = rules.statuses[effective] ? rules.statuses[effective].display : null;
  if (fixed) return fixed;
  return rules.phases[phase(listing.windows, n.date, hm(n.minutes))];
}

/** Where an event's season stands on `today`: before its first day, during it, or after its last day. */
export function seasonOf(event, today) {
  if (today < event.starts_on) return 'before';
  return today > event.ends_on ? 'after' : 'during';
}

/** Every local date of an event, first to last (dates as YYYY-MM-DD; calendar arithmetic in UTC, no time zone). */
export function eventDates(event) {
  const out = [];
  const d = new Date(`${event.starts_on}T00:00:00Z`);
  const end = new Date(`${event.ends_on}T00:00:00Z`);
  for (let i = 0; d <= end && i < 400; i++) {
    out.push(d.toISOString().slice(0, 10));
    d.setUTCDate(d.getUTCDate() + 1);
  }
  return out;
}
