/**
 * @module assets/js/lib/filters
 * @description Conjunctive family filters shared by the map pins and the list (pure, testable).
 * @input home (public API shape), filter state, context {today, eventDate, nowMinutes, zoneLabels, saved, areas}
 *     zone filter: 'all', a zone key, or 'area:<n>' for walking area n (ctx.areas[n] = its zone keys)
 */
import { statusOf, toMinutes } from './time.js';

export const DEFAULT_FILTERS = {
  q: '', zone: 'all', time: 'any', candy: false, lowSensory: false, noScares: false, noFlashing: false,
  noLoud: false, nonfood: false, adult: false, stroller: false, saved: false,
};

/** Jump scares or an intense setup: one "big scares" idea for visitors. */
export const isBigScare = (h) => h.jump_scares || h.scare_level === 'intense';
export const isLowSensory = (h) => h.low_noise && !h.flashing_lights && !h.loud_audio && !isBigScare(h);

/**
 * Filters saved or shared by older versions (separate jump/intense filters) become the combined one. A share
 * link is untrusted input: only known filters of the right type are kept (a {"q":5} would break filtering and
 * be remembered on this device), checked as own keys so names like "constructor" never count.
 */
export function normalizeFilters(f) {
  if (!f || typeof f !== 'object' || Array.isArray(f)) return {};
  const out = {};
  for (const [k, v] of Object.entries(f)) {
    if (!Object.hasOwn(DEFAULT_FILTERS, k)) continue;
    if (typeof DEFAULT_FILTERS[k] === 'boolean') {
      if (typeof v === 'boolean') out[k] = v;
    } else if (typeof v === 'string') {
      out[k] = v.slice(0, 80);
    }
  }
  if (f.noJumps === true || f.noIntense === true) out.noScares = true;
  return out;
}

function inZone(h, zone, ctx) {
  if (!zone || zone === 'all') return true;
  if (zone.startsWith('area:')) return ((ctx.areas || [])[Number(zone.slice(5))] || []).includes(h.zone);
  return h.zone === zone;
}

export function matches(h, f, ctx) {
  if (!inZone(h, f.zone, ctx)) return false;
  if (f.candy && !h.candy) return false;
  if (f.lowSensory && !isLowSensory(h)) return false;
  if (f.noScares && isBigScare(h)) return false;
  if (f.noFlashing && h.flashing_lights) return false;
  if (f.noLoud && h.loud_audio) return false;
  if (f.nonfood && !h.nonfood) return false;
  if (f.adult && !h.adult_drinks) return false;
  if (f.stroller && !h.stroller_friendly) return false;
  if (f.saved && !(ctx.saved || []).includes(h.id)) return false;
  if (f.time === 'now') {
    if (statusOf(h, ctx).key !== 'open') return false;
  } else if (f.time && f.time !== 'any') {
    const t = toMinutes(f.time);
    if (h.status === 'done' || t < toMinutes(h.start) || t >= toMinutes(h.end)) return false;
  }
  const q = (f.q || '').trim().toLowerCase();
  if (q) {
    const zoneLabel = (ctx.zoneLabels || {})[h.zone] || '';
    const hay = [h.address, h.note, zoneLabel, h.candy ? 'candy' : '', h.nonfood ? 'non-food teal' : '', h.adult_drinks ? '21+ adult drinks' : '',
      h.low_noise ? 'quiet low noise gentle sensory' : '', h.stroller_friendly ? 'kid friendly stroller' : '', isBigScare(h) ? 'big scares spooky' : '', h.scare_level].join(' ').toLowerCase();
    if (!q.split(/\s+/).every((w) => hay.includes(w))) return false;
  }
  return true;
}

export const activeFilterCount = (f) => Object.entries(f).filter(([k, v]) => (k === 'zone' || k === 'time') ? v !== 'all' && v !== 'any' : (k === 'q' ? !!v.trim() : v === true)).length;

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
