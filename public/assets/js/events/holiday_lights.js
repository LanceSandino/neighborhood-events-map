/**
 * @module assets/js/events/holiday_lights
 * @description The Christmas lights event type in the browser (mirrors src/Event/Type/HolidayLightsType.php): filter
 *     toggles (drive-by, no flashing reported, music, walk-up, open tonight) and choices (one night of the season, a
 *     display style), search words, card tags, pin symbol and class, the status on the visitor's clock from the
 *     home's nights (lib/schedule.js, the shared rules: "Lights on" only inside tonight's window), card hours, route
 *     notes, copy, hour choices and the listing form (nights as windows, styles, viewing mode, music and FM station,
 *     flashing warning, treats, cocoa) of templates/event/holiday_lights/. Icons are decorative and never animated;
 *     "No flashing reported" is never a safety promise.
 * @dependencies lib/clock.js, lib/schedule.js
 */
import { time12, toMinutes } from '../lib/clock.js';
import { effectiveStatus, phase, seasonOf, eventDates } from '../lib/schedule.js';

export const key = 'holiday_lights';

/** Only Halloween takes over a 0.10 year store. */
export const migratesYearStore = false;

/** Filter toggles, in the order of the saved and shared filter object (HolidayLightsType::filters). */
export const filters = [
  { key: 'driveBy', quick: true }, { key: 'noFlashing', quick: true }, { key: 'music', quick: true },
  { key: 'walkUp', quick: false }, { key: 'openTonight', quick: false },
];

export const STYLES = { classic: ['✨', 'Classic'], animated: ['🌟', 'Animated'], synchronized_music: ['🎵', 'Light show to music'], inflatables: ['⛄', 'Inflatables'], walkthrough: ['🎄', 'Walk-through'] };

const isDate = (v) => /^\d{4}-\d{2}-\d{2}$/.test(v);
/** Filter choices besides the toggles: one night of the season ('' = any) and a display style. */
export const choices = [
  { key: 'night', default: '', valid: (v) => v === '' || isDate(v) },
  { key: 'style', default: 'any', valid: (v) => v === 'any' || Object.hasOwn(STYLES, v) },
];

/** Hour choices of the forms, [first, last] hour (HolidayLightsType::hourChoices). */
export const hours = { start: [15, 22], end: [16, 23], visit: [16, 23] };

/** How visitors see a status (HolidayLightsType::displayRules, the shared fixture's holiday_lights rules). */
export const displayRules = Object.freeze({
  statuses: {
    active: { day_scoped: false, display: null },
    off_tonight: { day_scoped: true, display: 'off_tonight' },
    finished: { day_scoped: false, display: 'finished' },
  },
  phases: { inside: 'lights_on', later_today: 'later_tonight', ended_today: 'not_tonight', not_today: 'not_tonight' },
});

const d = (h) => h.details || {};
const hm = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
export const isMusic = (h) => Boolean(d(h).music_enabled) || (d(h).styles || []).includes('synchronized_music');
const windowOn = (h, date) => (h.windows || []).find((w) => w.date === date) || null;
const nextAfter = (h, date) => (h.windows || []).find((w) => w.date > date) || null;

/** The display key of a home on ctx's clock (today and minutes in the community's time zone). */
export function displayKey(h, ctx) {
  if (!ctx || !ctx.today) return 'not_tonight';
  const effective = effectiveStatus(displayRules, h.status, h.status_date ?? null, ctx.today);
  const fixed = displayRules.statuses[effective] ? displayRules.statuses[effective].display : null;
  return fixed || displayRules.phases[phase(h.windows, ctx.today, hm(ctx.nowMinutes))];
}

/** Lights on that night: a window on that date, not done for the season, not off that night. */
export function onNight(h, date) {
  if (h.status === 'finished' || !windowOn(h, date)) return false;
  return !(h.status === 'off_tonight' && h.status_date === date);
}

const PREDICATES = {
  driveBy: (h) => d(h).viewing_mode === 'drive_by_only',
  noFlashing: (h) => !d(h).flashing_warning,
  music: isMusic,
  walkUp: (h) => d(h).viewing_mode === 'walk_up_welcome',
  openTonight: (h, ctx) => ['lights_on', 'later_tonight'].includes(displayKey(h, ctx)),
};

/** Every toggle that is on, the chosen night and the chosen style match the home. */
export function matches(h, f, ctx) {
  if (!filters.every(({ key: k }) => !f[k] || Boolean(PREDICATES[k](h, ctx)))) return false;
  if (f.night && !onNight(h, f.night)) return false;
  return !f.style || f.style === 'any' || (d(h).styles || []).includes(f.style);
}

/** No older filters to upgrade. */
export function upgradeFilters() {}

/** Words the search box finds a home by, besides its address, note and zone. */
export function searchWords(h) {
  const x = d(h);
  return [...(x.styles || []).map((s) => (STYLES[s] ? STYLES[s][1] : '')), x.viewing_mode === 'walk_up_welcome' ? 'walk-up walk up' : 'drive-by drive by',
    x.flashing_warning ? 'flashing strobe' : 'no flashing steady', isMusic(h) ? `music radio fm ${x.fm_frequency || ''}` : '', x.treats ? 'treats' : '', x.cocoa ? 'cocoa hot chocolate' : ''];
}

/** On the list and the route: not done for the season and with a night left (tonight or later). */
export const isOpen = (h, ctx) => h.status !== 'finished' && (h.windows || []).some((w) => !ctx || !ctx.today || w.date >= ctx.today);
export const offForTheDay = (h) => h.status === 'finished';
/** A pin is greyed when its lights are not on tonight (off tonight, done for the season or no window tonight). */
export const inactive = (h, ctx) => displayKey(h, ctx) !== 'lights_on' && displayKey(h, ctx) !== 'later_tonight';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
/** "Fri, Dec 5" for a local date (calendar arithmetic only, no time zone). */
export function dayLabel(date) {
  const t = new Date(`${date}T00:00:00Z`);
  return `${DAYS[t.getUTCDay()]}, ${MONTHS[t.getUTCMonth()]} ${t.getUTCDate()}`;
}
/** "6–10 PM", "6:30–9 PM", "11 AM–1 PM": one suffix when both times share it. */
export function span(start, end) {
  const [a, b] = [time12(start), time12(end)];
  const same = (toMinutes(start) < 720) === (toMinutes(end) < 720);
  return same ? `${a.replace(/ (AM|PM)$/, '')}–${b}` : `${a}–${b}`;
}

/** Tonight's hours, else the next night with its hours, else the last night (a home whose nights are over). */
export function hoursLabel(h, ctx) {
  const today = ctx && ctx.today;
  const tonight = today ? windowOn(h, today) : null;
  if (tonight && displayKey(h, ctx) !== 'off_tonight') return `Tonight ${span(tonight.start, tonight.end)}`;
  const next = today ? nextAfter(h, today) : (h.windows || [])[0];
  if (next) return `${dayLabel(next.date)} · ${span(next.start, next.end)}`;
  const last = (h.windows || [])[(h.windows || []).length - 1];
  return last ? `Last night ${dayLabel(last.date)}` : span(h.start, h.end);
}

const LABELS = { lights_on: 'Lights on', later_tonight: 'Later tonight', not_tonight: 'Not tonight', off_tonight: 'Off tonight', finished: 'Done for the season' };

/** @returns {{key: string, label: string}} the home's status on ctx's clock; never "Lights on" outside a window */
export function statusOf(home, ctx) {
  const k = displayKey(home, ctx);
  if (k === 'not_tonight' && ctx && ctx.today && !nextAfter(home, ctx.today) && !windowOn(home, ctx.today)) return { key: 'finished', label: 'No more nights' };
  return { key: k, label: LABELS[k] };
}

/** The status chip shows once the season is on (before it, every home is simply "not tonight"). */
export function statusChipShown(st, ctx) {
  if (st.key !== 'not_tonight') return true;
  return Boolean(ctx && ctx.event && ctx.today && seasonOf(ctx.event, ctx.today) === 'during');
}

/**
 * One vocabulary for pins, legend and cards: 🎵 Music, 🎄 Walk-through, ⛄ Inflatables, ✨ Lights, in that order. With
 * the Music filter on, music homes show 🎵 first.
 */
const KINDS = { music: ['🎵', 'music'], walkthrough: ['🎄', 'walkthrough'], inflatable: ['⛄', 'inflatable'], lights: ['✨', 'lights'] };
function kindOf(h) {
  const styles = d(h).styles || [];
  if (isMusic(h)) return 'music';
  if (styles.includes('walkthrough')) return 'walkthrough';
  return styles.includes('inflatables') ? 'inflatable' : 'lights';
}
export const symbol = (h) => KINDS[kindOf(h)][0];
export const pinClass = (h) => KINDS[kindOf(h)][1];

/** Card tags in display order; the icon is decorative and the words always present (never colour only). */
export function tags(h) {
  const x = d(h);
  const t = (x.styles || []).filter((s) => STYLES[s]).map((s) => ({ icon: STYLES[s][0], text: STYLES[s][1], cls: 'lights' }));
  t.push(x.viewing_mode === 'walk_up_welcome' ? { icon: '🚶', text: 'Walk-up', cls: 'quiet' } : { icon: '🚗', text: 'Drive-by', cls: 'drive' });
  t.push(x.flashing_warning ? { icon: '⚡', text: 'Flashing lights', cls: 'strobe' } : { icon: '🕯️', text: 'No flashing reported', cls: 'candy' });
  if (x.music_enabled) t.push({ icon: '🎵', text: x.fm_frequency ? `Music ${x.fm_frequency} FM` : 'Music', cls: 'music' });
  if (x.treats) t.push({ icon: '🍬', text: 'Treats', cls: 'drinks' });
  if (x.cocoa) t.push({ icon: '☕', text: 'Cocoa', cls: 'drinks' });
  return t;
}

/** What a stop line of the route adds after the hours. */
export const routeNote = (h) => `${d(h).viewing_mode === 'walk_up_welcome' ? ' · 🚶 Walk-up' : ' · 🚗 Drive-by'}${d(h).flashing_warning ? ' · ⚡ Flashing' : ''}`;

/** Plain-text words the core modules show for this type (they escape them). */
export const copy = {
  notOpen: 'with no nights left',
  noneOpen: 'No matching homes have nights left this season.',
  routeEvery: 'Every home that matches your filters, in a suggested order. Skip any with ✕.',
  routeShowingAll: 'Showing every home with nights left.',
  routeNotOpen: 'with no nights left',
  shareText: 'My Christmas lights tour',
  published: 'Thanks for lighting up the neighborhood!',
  noDays: 'Choose at least one night.',
  drivingTour: '🚗 Drive-by tour',
  walking: '🚶 Walk',
};

const FLAGS = ['flashing_warning', 'music_enabled', 'treats', 'cocoa'];
const nightsOf = (root) => root.querySelector('[data-nights]');
const modeOf = (root) => root.querySelector('input[name="nights_mode"]:checked')?.value || 'all';

/** Fills the night chips of a form from the season's dates (grouped by month) and wires "Only some nights". */
export function initForm(root, event) {
  const grid = nightsOf(root);
  if (!grid || !event || !event.starts_on || grid.dataset.ready) return;
  grid.dataset.ready = '1';
  let month = '';
  grid.innerHTML = eventDates(event).map((date) => {
    const t = new Date(`${date}T00:00:00Z`);
    const m = `${['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][t.getUTCMonth()]} ${t.getUTCFullYear()}`;
    const head = m !== month ? `<span class="night-month" aria-hidden="true">${m}</span>` : '';
    month = m;
    return `${head}<label class="check-chip"><input type="checkbox" name="night" value="${date}" checked> <small>${DAYS[t.getUTCDay()]}</small> <span class="visually-hidden">${MONTHS[t.getUTCMonth()]}</span>${t.getUTCDate()}</label>`;
  }).join('');
  for (const r of root.querySelectorAll('input[name="nights_mode"]')) r.addEventListener('change', () => grid.classList.toggle('hide', modeOf(root) !== 'pick'));
}

/** The listing details from the form fields. */
export function formDetails(root) {
  const checked = (n) => !!root.querySelector(`input[name="${n}"]`)?.checked;
  const fm = (root.querySelector('input[name="fm_frequency"]')?.value || '').trim();
  return {
    styles: [...root.querySelectorAll('input[name="styles"]:checked')].map((i) => i.value),
    ...Object.fromEntries(FLAGS.map((f) => [f, checked(f)])),
    fm_frequency: fm || null,
    viewing_mode: root.querySelector('input[name="viewing_mode"]:checked')?.value || 'drive_by_only',
  };
}

/** The listing's nights as windows with the form's hours: every night of the season, or the nights picked. */
export function formExtras(root, { start, end, event }) {
  const dates = modeOf(root) === 'pick'
    ? [...root.querySelectorAll('input[name="night"]:checked')].map((i) => i.value)
    : eventDates(event);
  return { windows: dates.map((date) => ({ date, start, end })) };
}

/** Puts a listing's details and nights into the form fields. */
export function fillForm(form, details, listing, event) {
  for (const i of form.querySelectorAll('input[name="styles"]')) i.checked = (details.styles || []).includes(i.value);
  for (const f of FLAGS) if (form[f]) form[f].checked = !!details[f];
  for (const i of form.querySelectorAll('input[name="viewing_mode"]')) i.checked = i.value === (details.viewing_mode || 'drive_by_only');
  const fm = form.querySelector('input[name="fm_frequency"]');
  if (fm) fm.value = details.fm_frequency || '';
  if (!listing || !event) return;
  initForm(form, event);
  const chosen = new Set((listing.windows || []).map((w) => w.date));
  const all = eventDates(event).every((date) => chosen.has(date));
  for (const r of form.querySelectorAll('input[name="nights_mode"]')) r.checked = r.value === (all ? 'all' : 'pick');
  for (const i of form.querySelectorAll('input[name="night"]')) i.checked = chosen.has(i.value);
  nightsOf(form)?.classList.toggle('hide', all);
}

/** Field errors the form shows before sending (the server checks the same and more). */
export function validateDetails(x) {
  const errors = {};
  if (!(x.styles || []).length) errors.styles = 'Choose at least one display style.';
  if (x.fm_frequency) {
    const ok = /^\d{2,3}\.\d$/.test(x.fm_frequency) && Number(x.fm_frequency) >= 87.5 && Number(x.fm_frequency) <= 108;
    if (!ok) errors.fm_frequency = 'Use an FM station like 88.7 (87.5 to 108.0).';
    else if (!x.music_enabled) errors.fm_frequency = 'Turn on music to add its radio station, or leave the station empty.';
  }
  return errors;
}
