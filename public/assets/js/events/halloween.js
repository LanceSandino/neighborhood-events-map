/**
 * @module assets/js/events/halloween
 * @description The Halloween event type in the browser (mirrors src/Event/Type/HalloweenType.php): its filter
 *     toggles and predicates, search words, card tags, pin symbol and class, status labels and display rules, route notes, copy,
 *     hour choices and the listing form fields of templates/event/halloween/. Core modules (lib/filters,
 *     lib/render, lib/time, home, register, me, route-panel) ask this module and never branch on a type key.
 * @dependencies lib/clock.js
 */
import { time12, toMinutes } from '../lib/clock.js';

export const key = 'halloween';

/** Saved homes and routes of 0.10 were kept per season year; the active Halloween event takes them over once. */
export const migratesYearStore = true;

/** Filter toggles, in the order of the saved and shared filter object; quick ones are icon buttons on the map. */
export const filters = [
  { key: 'candy', quick: true }, { key: 'lowSensory', quick: true }, { key: 'noScares', quick: false },
  { key: 'noFlashing', quick: false }, { key: 'noLoud', quick: false }, { key: 'nonfood', quick: false },
  { key: 'adult', quick: true }, { key: 'stroller', quick: false },
];

/** Hour choices of the forms and the "Visit at" filter, [first, last] hour (HalloweenType::hourChoices). */
export const hours = { start: [15, 22], end: [15, 23], visit: [15, 22] };

/** Jump scares or an intense setup: one "big scares" idea for visitors. */
export const isBigScare = (h) => h.jump_scares || h.scare_level === 'intense';
export const isLowSensory = (h) => h.low_noise && !h.flashing_lights && !h.loud_audio && !isBigScare(h);

const PREDICATES = {
  candy: (h) => h.candy, lowSensory: isLowSensory, noScares: (h) => !isBigScare(h), noFlashing: (h) => !h.flashing_lights,
  noLoud: (h) => !h.loud_audio, nonfood: (h) => h.nonfood, adult: (h) => h.adult_drinks, stroller: (h) => h.stroller_friendly,
};

/** Every toggle of this type that is on matches the home. */
export function matches(h, f) {
  return filters.every(({ key: k }) => !f[k] || Boolean(PREDICATES[k](h)));
}

/** Filters saved or shared by older versions (separate jump/intense filters) become the combined one. */
export function upgradeFilters(raw, out) {
  if (raw.noJumps === true || raw.noIntense === true) out.noScares = true;
}

/** Words the search box finds a home by, besides its address, note and zone. */
export function searchWords(h) {
  return [h.candy ? 'candy' : '', h.nonfood ? 'non-food teal' : '', h.adult_drinks ? '21+ adult drinks' : '',
    h.low_noise ? 'quiet low noise gentle sensory' : '', h.stroller_friendly ? 'kid friendly stroller' : '', isBigScare(h) ? 'big scares spooky' : '', h.scare_level];
}

/** Handing out treats now or later tonight; paused, out of candy and done homes are not. */
export const isOpen = (h) => h.status === 'active';
/** A home that is done for the night matches no "Visit at" time. */
export const offForTheDay = (h) => h.status === 'done';

const STORED = { paused: ['paused', 'Paused'], out_of_candy: ['out', 'Out of candy'], done: ['done', 'Done for tonight'] };

/** @returns {{key: string, label: string}} the home's status at ctx's moment on the event day's clock */
export function statusOf(home, ctx) {
  if (Object.hasOwn(STORED, home.status)) { const [k, label] = STORED[home.status]; return { key: k, label }; }
  if (!ctx || ctx.today !== ctx.eventDate) return { key: 'later', label: `${time12(home.start)}–${time12(home.end)}` };
  const now = ctx.nowMinutes;
  if (now < toMinutes(home.start)) return { key: 'later', label: `Starts ${time12(home.start)}` };
  if (now >= toMinutes(home.end)) return { key: 'done', label: 'Done for tonight' };
  return { key: 'open', label: 'Handing out' };
}

/**
 * How visitors see a status (lib/schedule.js, HalloweenType::displayRules): the words of statusOf above — open, later,
 * done, paused, out — from the stored status and the home's window on the event day.
 */
export const displayRules = Object.freeze({
  statuses: {
    active: { day_scoped: false, display: null },
    paused: { day_scoped: false, display: 'paused' },
    out_of_candy: { day_scoped: false, display: 'out' },
    done: { day_scoped: false, display: 'done' },
  },
  phases: { inside: 'open', later_today: 'later', ended_today: 'done', not_today: 'later' },
});

/**
 * One vocabulary everywhere (pins, legend, cards, filters): 👻 Spooky, 🧩 Sensory-friendly, 🍺 21+, 🍬 Treats, in that
 * order. With a quick filter on (focus: the filters object), the homes that match show that filter's symbol, so the
 * map shows what the visitor picked.
 */
const KINDS = { spooky: ['👻', 'scary'], sensory: ['🧩', 'quiet'], adult: ['🍺', 'adult'], treats: ['🍬', ''] };
function kindOf(h, focus = {}) {
  if (focus.adult && h.adult_drinks) return 'adult';
  if (focus.lowSensory && isLowSensory(h)) return 'sensory';
  if (focus.candy && h.candy) return 'treats';
  return isBigScare(h) ? 'spooky' : isLowSensory(h) ? 'sensory' : h.adult_drinks ? 'adult' : 'treats';
}
export const symbol = (h, focus) => KINDS[kindOf(h, focus)][0];
export const pinClass = (h, focus) => KINDS[kindOf(h, focus)][1];

/** Card tags in display order; the icon is decorative and the words always present (never colour only). */
export function tags(h) {
  const t = [];
  if (h.candy) t.push({ icon: '🍬', text: 'Candy', cls: 'candy' });
  if (isLowSensory(h)) t.push({ icon: '🧩', text: 'Sensory-friendly', cls: 'quiet' });
  if (h.nonfood) t.push({ icon: '🎃', text: 'Non-food', cls: 'teal', iconCls: 'teal-pumpkin' }); // Teal Pumpkin Project: non-food treats
  // Visitors see one "big scares" idea for jump scares or an intense setup.
  if (isBigScare(h)) t.push({ icon: '😱', text: 'Big scares', cls: 'spooky' });
  else if (h.scare_level === 'mild') t.push({ icon: '🎃', text: 'Mild scares', cls: 'spooky' });
  if (h.flashing_lights) t.push({ icon: '🔦', text: 'Flashing lights', cls: 'strobe' });
  if (h.loud_audio) t.push({ icon: '🔊', text: 'Loud audio', cls: 'strobe' });
  if (h.adult_drinks) t.push({ icon: '🍺', text: '21+', cls: 'drinks' });
  if (h.stroller_friendly) t.push({ icon: '👶', text: 'Kid-friendly', cls: 'quiet' });
  return t;
}

/** The card's hours: the home's own hours on the event day (the 0.9 card line). */
export const hoursLabel = (h) => `${time12(h.start)}–${time12(h.end)}`;

/** The status chip is left out when it would only repeat the hours (the 0.9 card and selected-home panel). */
export const statusChipShown = (st) => !(st.key === 'later' && !st.label.startsWith('Starts'));

/** A pin is greyed for a home that is not handing out (paused, out of candy, done). */
export const inactive = (h) => h.status !== 'active';

/** What a stop line of the route adds after the hours. */
export const routeNote = (h) => `${isLowSensory(h) ? ' · 🧩 Sensory-friendly' : ''}${isBigScare(h) ? ' · 👻 Big scares' : ''}`;

/** Plain-text words the core modules show for this type (they escape them). */
export const copy = {
  notOpen: 'not handing out right now',
  noneOpen: 'No matching homes are handing out right now.',
  routeEvery: 'Every home handing out that matches your filters. Skip any with ✕.',
  routeShowingAll: 'Showing every home handing out.',
  routeNotOpen: 'not handing out now',
  shareText: 'My trick-or-treat stops',
  published: 'Thanks for handing out treats!',
};

const FLAGS = ['candy', 'nonfood', 'adult_drinks', 'low_noise', 'jump_scares', 'flashing_lights', 'loud_audio', 'stroller_friendly'];

/** The listing details from the form fields (a chip the community does not offer reads as off). */
export function formDetails(root) {
  const checked = (n) => !!root.querySelector(`input[name="${n}"]`)?.checked;
  const scare = root.querySelector('#regScare, select[name="scare_level"]');
  return { ...Object.fromEntries(FLAGS.map((f) => [f, checked(f)])), scare_level: scare ? scare.value : 'gentle' };
}

/** Halloween's form needs no set-up (one evening, no nights to pick). */
export function initForm() {}

/** Nothing besides the hours and the details: a one-day listing takes the event's day. */
export const formExtras = () => ({});

/** Puts a listing's details into the form fields. */
export function fillForm(form, details) {
  if (form.scare_level) form.scare_level.value = details.scare_level;
  for (const k of FLAGS) if (form[k]) form[k].checked = !!details[k];
}

/** Field errors the form shows before sending (the server checks the same and more). */
export function validateDetails(d) {
  return !d.candy && !d.nonfood && !d.adult_drinks ? { offerings: 'Choose at least one: candy, non-food treats or adult refreshments.' } : {};
}
