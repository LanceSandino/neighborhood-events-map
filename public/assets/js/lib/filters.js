/**
 * @module assets/js/lib/filters
 * @description Conjunctive family filters shared by the map pins and the list (pure, testable). The search, zone,
 *     time and saved filters are shared; the toggles, choices (e.g. a night of the season), their predicates and
 *     search words belong to the event type (ctx.type, a module from events/index.js; Halloween when absent).
 * @input home (public API shape), filter state, context {today, eventDate, nowMinutes, zoneLabels, saved, areas, type}
 *     zone filter: 'all', a zone key, or 'area:<n>' for walking area n (ctx.areas[n] = its zone keys)
 * @dependencies lib/time.js, events/index.js
 */
import { statusOf, toMinutes } from './time.js';
import { eventType } from '../events/index.js';

export { isBigScare, isLowSensory } from '../events/halloween.js';

/** The type's filter choices besides its toggles (lights: a night and a display style); Halloween has none. */
const choicesOf = (type) => type.choices || [];

/** The filter object of an event type: search, zone and time first, its toggles, saved, then its choices. */
export function defaultFilters(type = eventType()) {
  return { q: '', zone: 'all', time: 'any', ...Object.fromEntries(type.filters.map((f) => [f.key, false])), saved: false,
    ...Object.fromEntries(choicesOf(type).map((c) => [c.key, c.default])) };
}

export const DEFAULT_FILTERS = defaultFilters();

const typeOf = (ctx) => (ctx && ctx.type) || eventType();

/** "Visit at" choices: any time, open now, or a clock time HH:MM. */
const isTimeChoice = (v) => v === 'any' || v === 'now' || /^([01]\d|2[0-3]):[0-5]\d$/.test(v);

/**
 * A share link is untrusted input: only known filters of the right type are kept (a {"q":5} would break filtering and
 * be remembered on this device), checked as own keys so names like "constructor" never count. "Visit at" must be
 * any, now or HH:MM, and a zone key longer than 60 characters is dropped. The event type upgrades filters saved by
 * older versions.
 */
export function normalizeFilters(f, type = eventType()) {
  if (!f || typeof f !== 'object' || Array.isArray(f)) return {};
  const defaults = defaultFilters(type);
  const out = {};
  for (const [k, v] of Object.entries(f)) {
    if (!Object.hasOwn(defaults, k)) continue;
    if (typeof defaults[k] === 'boolean') {
      if (typeof v === 'boolean') out[k] = v;
    } else if (typeof v === 'string') {
      const choice = choicesOf(type).find((c) => c.key === k);
      if (k === 'q') out[k] = v.slice(0, 80);
      else if (choice) { if (choice.valid(v)) out[k] = v; }
      else if (k === 'time' ? isTimeChoice(v) : v.length <= 60) out[k] = v;
    }
  }
  type.upgradeFilters(f, out);
  return out;
}

function inZone(h, zone, ctx) {
  if (!zone || zone === 'all') return true;
  if (zone.startsWith('area:')) return ((ctx.areas || [])[Number(zone.slice(5))] || []).includes(h.zone);
  return h.zone === zone;
}

export function matches(h, f, ctx) {
  const type = typeOf(ctx);
  if (!inZone(h, f.zone, ctx)) return false;
  if (!type.matches(h, f, ctx)) return false;
  if (f.saved && !(ctx.saved || []).includes(h.id)) return false;
  if (f.time === 'now') {
    if (statusOf(h, ctx).key !== 'open') return false;
  } else if (f.time && f.time !== 'any') {
    const t = toMinutes(f.time);
    if (type.offForTheDay(h) || t < toMinutes(h.start) || t >= toMinutes(h.end)) return false;
  }
  const q = (f.q || '').trim().toLowerCase();
  if (q) {
    const zoneLabel = (ctx.zoneLabels || {})[h.zone] || '';
    const hay = [h.address, h.note, zoneLabel, ...type.searchWords(h)].join(' ').toLowerCase();
    if (!q.split(/\s+/).every((w) => hay.includes(w))) return false;
  }
  return true;
}

export const activeFilterCount = (f, type = eventType()) => Object.entries(f).filter(([k, v]) => {
  const choice = choicesOf(type).find((c) => c.key === k);
  if (choice) return v !== choice.default;
  return (k === 'zone' || k === 'time') ? v !== 'all' && v !== 'any' : (k === 'q' ? !!v.trim() : v === true);
}).length;

/**
 * How many homes each choice would show, given the other active filters: a toggle's count is the result
 * with that toggle on; a zone/area option's count is the result with that zone chosen.
 */
export function choiceCounts(homes, f, ctx, { toggles = [], zones = [], keep = () => true } = {}) {
  const count = (g) => homes.filter((h) => keep(h) && matches(h, g, ctx)).length;
  return {
    toggles: Object.fromEntries(toggles.map((k) => [k, count({ ...f, [k]: true })])),
    zones: Object.fromEntries(zones.map((z) => [z, count({ ...f, zone: z })])),
  };
}
