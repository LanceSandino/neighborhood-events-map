/**
 * @module assets/js/lib/filters
 * @description Conjunctive family filters shared by the map pins and the list (pure, testable).
 * @input home (public API shape), filter state, context {today, eventDate, nowMinutes, zoneLabels, saved}
 */
import { statusOf, toMinutes } from './time.js';

export const DEFAULT_FILTERS = {
  q: '', zone: 'all', time: 'any', candy: false, lowSensory: false, noJumps: false, noFlashing: false,
  noLoud: false, noIntense: false, nonfood: false, adult: false, stroller: false, saved: false,
};

export const isLowSensory = (h) => h.low_noise && !h.flashing_lights && !h.jump_scares && !h.loud_audio && h.scare_level !== 'intense';

export function matches(h, f, ctx) {
  if (f.zone && f.zone !== 'all' && h.zone !== f.zone) return false;
  if (f.candy && !h.candy) return false;
  if (f.lowSensory && !isLowSensory(h)) return false;
  if (f.noJumps && h.jump_scares) return false;
  if (f.noFlashing && h.flashing_lights) return false;
  if (f.noLoud && h.loud_audio) return false;
  if (f.noIntense && h.scare_level === 'intense') return false;
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
      h.low_noise ? 'quiet low noise gentle' : '', h.scare_level].join(' ').toLowerCase();
    if (!q.split(/\s+/).every((w) => hay.includes(w))) return false;
  }
  return true;
}

export const activeFilterCount = (f) => Object.entries(f).filter(([k, v]) => (k === 'zone' || k === 'time') ? v !== 'all' && v !== 'any' : (k === 'q' ? !!v.trim() : v === true)).length;
