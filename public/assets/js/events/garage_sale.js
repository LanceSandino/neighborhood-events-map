/**
 * @module assets/js/events/garage_sale
 * @description The garage sale event type in the browser (mirrors src/Event/Type/GarageSaleType.php): filter toggles
 *     (kids & toys, furniture, tools, household, electronics, free items, open now, open on each sale day, multi-family,
 *     early birds), search words, card tags (category icons with their words, never colour only), pin symbol and class,
 *     the status on the visitor's clock from the home's sale days (lib/schedule.js, the shared rules: "Open now" only
 *     inside today's hours; sold out and paused hold for their own day), card hours per day, route notes, copy, hour
 *     choices and the listing form (sale days with their own hours as windows, categories, multi-family, early birds,
 *     payment kinds) of templates/event/garage_sale/. No prices, photos or contact details.
 * @dependencies lib/clock.js, lib/schedule.js
 */
import { time12, toMinutes } from '../lib/clock.js';
import { effectiveStatus, phase, seasonOf } from '../lib/schedule.js';

export const key = 'garage_sale';

/** The neighborhood outline on the map: teal (Halloween's is orange). */
export const mapColor = '#178d80';

/** Only Halloween takes over a 0.10 year store. */
export const migratesYearStore = false;

/** Filter toggles, in the order of the saved and shared filter object (GarageSaleType::filters, then a sale's days). */
export const filters = [
  { key: 'kidsToys', quick: true }, { key: 'furniture', quick: true }, { key: 'tools', quick: true },
  { key: 'household', quick: false }, { key: 'electronics', quick: false }, { key: 'free', quick: false },
  { key: 'openNow', quick: false }, { key: 'day1', quick: false }, { key: 'day2', quick: false }, { key: 'day3', quick: false },
  { key: 'multiFamily', quick: false }, { key: 'earlyBirds', quick: false },
];

/** Item categories in canonical order: [icon, words] (GarageSaleCopy::CATEGORIES). */
export const CATEGORIES = {
  kids_baby: ['🍼', 'Kids & baby'], toys: ['🧸', 'Toys'], furniture: ['🪑', 'Furniture'], clothing: ['👕', 'Clothing'], tools: ['🛠️', 'Tools'],
  books_media: ['📚', 'Books & media'], electronics: ['🔌', 'Electronics'], sports: ['⚽', 'Sports'], household: ['🧺', 'Household'], free: ['🆓', 'Free items'],
};
export const PAYMENTS = { cash: 'Cash', card: 'Card', apps: 'Apps' };

/** Hour choices of the forms, [first, last] hour (GarageSaleType::hourChoices). */
export const hours = { start: [6, 17], end: [7, 20], visit: [6, 19] };

/** How visitors see a status (GarageSaleType::displayRules, the shared fixture's garage_sale rules). */
export const displayRules = Object.freeze({
  statuses: {
    active: { day_scoped: false, display: null },
    paused: { day_scoped: true, display: 'paused' },
    sold_out: { day_scoped: true, display: 'sold_out' },
    closed: { day_scoped: false, display: 'closed' },
  },
  phases: { inside: 'open', later_today: 'opens_at', ended_today: 'not_today', not_today: 'not_today' },
});

const d = (h) => h.details || {};
const has = (h, c) => (d(h).categories || []).includes(c);
const hm = (minutes) => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
const windowOn = (h, date) => (h.windows || []).find((w) => w.date === date) || null;
const nextAfter = (h, date) => (h.windows || []).find((w) => w.date > date) || null;
const OPEN = ['open', 'opens_at'];

/** The display key of a home on ctx's clock (today and minutes in the community's time zone). */
export function displayKey(h, ctx) {
  if (!ctx || !ctx.today) return 'not_today';
  const effective = effectiveStatus(displayRules, h.status, h.status_date ?? null, ctx.today);
  const fixed = displayRules.statuses[effective] ? displayRules.statuses[effective].display : null;
  return fixed || displayRules.phases[phase(h.windows, ctx.today, hm(ctx.nowMinutes))];
}

/** Open on that sale day: hours that day, not closed, not paused or sold out for that day. */
export function openOn(h, date) {
  if (h.status === 'closed' || !windowOn(h, date)) return false;
  return !(['paused', 'sold_out'].includes(h.status) && h.status_date === date);
}

const saleDay = (ctx, n) => ((ctx && ctx.event && ctx.event.days) || [])[n - 1];
const onDay = (n) => (h, ctx) => { const day = saleDay(ctx, n); return Boolean(day) && openOn(h, day); };

const PREDICATES = {
  kidsToys: (h) => has(h, 'kids_baby') || has(h, 'toys'),
  furniture: (h) => has(h, 'furniture'),
  tools: (h) => has(h, 'tools'),
  household: (h) => has(h, 'household'),
  electronics: (h) => has(h, 'electronics'),
  free: (h) => has(h, 'free'),
  openNow: (h, ctx) => displayKey(h, ctx) === 'open',
  day1: onDay(1),
  day2: onDay(2),
  day3: onDay(3),
  multiFamily: (h) => Boolean(d(h).multi_family),
  earlyBirds: (h) => Boolean(d(h).early_birds_allowed),
};

/** Every toggle that is on matches the home. */
export function matches(h, f, ctx) {
  return filters.every(({ key: k }) => !f[k] || Boolean(PREDICATES[k](h, ctx)));
}

/** No older filters to upgrade. */
export function upgradeFilters() {}

/** Words the search box finds a home by, besides its address, note and zone: its categories, kind of sale and payment. */
export function searchWords(h) {
  const x = d(h);
  return [...(x.categories || []).map((c) => (CATEGORIES[c] ? `${CATEGORIES[c][1]} ${c.replace('_', ' ')}` : '')), x.multi_family ? 'multi-family multi family' : '',
    x.early_birds_allowed ? 'early birds' : '', ...(x.payment_types || []).map((p) => PAYMENTS[p] || '')];
}

/** On the list and the route: not closed and with sale hours left (today's still ahead or open, or a later day). */
export function isOpen(h, ctx) {
  if (h.status === 'closed') return false;
  if (!ctx || !ctx.today) return (h.windows || []).length > 0;
  return (h.windows || []).some((w) => w.date > ctx.today || (w.date === ctx.today && OPEN.includes(displayKey(h, ctx))));
}
export const offForTheDay = (h) => h.status === 'closed';

/** A pin is greyed for a closed sale, once the sale is over, and on a sale day while the home is not open or opening. */
export function inactive(h, ctx) {
  if (h.status === 'closed') return true;
  const season = ctx && ctx.event && ctx.today ? seasonOf(ctx.event, ctx.today) : 'before';
  if (season === 'after') return true;
  return season === 'during' && !OPEN.includes(displayKey(h, ctx));
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const utc = (date) => new Date(`${date}T00:00:00Z`);
/** "8 AM–2 PM", "8–11 AM": one suffix when both times share it. */
export function span(start, end) {
  const [a, b] = [time12(start), time12(end)];
  const same = (toMinutes(start) < 720) === (toMinutes(end) < 720);
  return same ? `${a.replace(/ (AM|PM)$/, '')}–${b}` : `${a}–${b}`;
}
/** "Sat", or "Sat Apr 10" when the sale has the same weekday twice (calendar arithmetic only, no time zone). */
function dayWord(date, ctx) {
  const days = (ctx && ctx.event && ctx.event.days) || [];
  const weekday = DAYS[utc(date).getUTCDay()];
  const twice = days.filter((x) => DAYS[utc(x).getUTCDay()] === weekday).length > 1;
  return twice ? `${weekday} ${MONTHS[utc(date).getUTCMonth()]} ${utc(date).getUTCDate()}` : weekday;
}
const nextDay = (a, b) => (utc(b) - utc(a)) === 86400000;

/** The home's days and hours: "Sat–Sun 8 AM–2 PM" when they share their hours, else "Sat 7 AM–12 PM · Sun 9 AM–1 PM". */
export function hoursLabel(h, ctx) {
  const ws = h.windows || [];
  if (!ws.length) return span(h.start, h.end);
  const same = ws.every((w) => w.start === ws[0].start && w.end === ws[0].end);
  if (!same) return ws.map((w) => `${dayWord(w.date, ctx)} ${span(w.start, w.end)}`).join(' · ');
  const consecutive = ws.every((w, i) => i === 0 || nextDay(ws[i - 1].date, w.date));
  const words = ws.length > 1 && consecutive ? `${dayWord(ws[0].date, ctx)}–${dayWord(ws[ws.length - 1].date, ctx)}` : ws.map((w) => dayWord(w.date, ctx)).join(' & ');
  return `${words} ${span(ws[0].start, ws[0].end)}`;
}

const LABELS = { open: 'Open now', paused: 'Paused today', sold_out: 'Sold out today', closed: 'Closed' };

/** @returns {{key: string, label: string}} the home's status on ctx's clock; never "Open now" outside its hours */
export function statusOf(home, ctx) {
  const k = displayKey(home, ctx);
  if (LABELS[k]) return { key: k, label: LABELS[k] };
  const today = ctx && ctx.today;
  if (k === 'opens_at') return { key: k, label: `Opens ${time12(windowOn(home, today).start)}` };
  const next = today ? nextAfter(home, today) : (home.windows || [])[0];
  return next ? { key: 'not_today', label: `Opens ${dayWord(next.date, ctx)} ${time12(next.start)}` } : { key: 'ended', label: 'No more sale hours' };
}

/** The status chip shows from the first sale day to the last (before and after, the sale row says it all). */
export function statusChipShown(st, ctx) {
  if (!['not_today', 'ended'].includes(st.key)) return true;
  const season = ctx && ctx.event && ctx.today ? seasonOf(ctx.event, ctx.today) : 'before';
  return season === 'during' || season === 'between';
}

/** The pin and card symbol: the first category's icon; with a quick filter on, the homes that match show its icon. */
const FOCUS = { kidsToys: ['🧸', (h) => PREDICATES.kidsToys(h)], furniture: ['🪑', (h) => has(h, 'furniture')], tools: ['🛠️', (h) => has(h, 'tools')] };
export function symbol(h, focus = {}) {
  for (const [k, [icon, test]] of Object.entries(FOCUS)) if (focus && focus[k] && test(h)) return icon;
  const first = Object.keys(CATEGORIES).find((c) => has(h, c));
  return first ? CATEGORIES[first][0] : '🏷️';
}
export const pinClass = () => 'sale';

/** Card tags in display order; the icon is decorative and the words always present (never colour only). */
export function tags(h) {
  const x = d(h);
  const t = Object.keys(CATEGORIES).filter((c) => has(h, c)).map((c) => ({ icon: CATEGORIES[c][0], text: CATEGORIES[c][1], cls: 'cat' }));
  if (x.multi_family) t.push({ icon: '🏘️', text: 'Multi-family', cls: 'family' });
  if (x.early_birds_allowed) t.push({ icon: '🐦', text: 'Early birds welcome', cls: 'early' });
  const pay = (x.payment_types || []).filter((p) => PAYMENTS[p]).map((p) => PAYMENTS[p]);
  if (pay.length) t.push({ icon: '💵', text: pay.join(' · '), cls: 'pay' });
  return t;
}

/** What a stop line of the route adds after the hours. */
export const routeNote = (h) => `${d(h).multi_family ? ' · 🏘️ Multi-family' : ''}${d(h).early_birds_allowed ? ' · 🐦 Early birds' : ''}`;

/** Plain-text words the core modules show for this type (they escape them). */
export const copy = {
  notOpen: 'with no sale hours left',
  noneOpen: 'No matching sales have hours left.',
  routeEvery: 'Every sale that matches your filters, in a suggested order. Skip any with ✕.',
  routeShowingAll: 'Showing every sale with hours left.',
  routeNotOpen: 'with no sale hours left',
  shareText: 'My garage sale stops',
  published: 'Thanks for holding a sale!',
  noDays: 'Choose at least one sale day.',
  drivingTour: '🚗 Drive between sales',
  walking: '🚶 Walk',
};

const FLAGS = ['multi_family', 'early_birds_allowed'];
const dayRows = (root) => [...root.querySelectorAll('input[name="sale_day"]')];
const select = (root, name, date) => root.querySelector(`select[name="${name}"][data-day="${date}"]`);
/** A day's hours are off while the day is unticked. */
function syncDay(root, box) {
  for (const n of ['day_start', 'day_end']) { const s = select(root, n, box.value); if (s) s.disabled = !box.checked; }
}

/** Wires each sale day's tick box to its hours (the rows come from templates/event/garage_sale/_days.html.twig). */
export function initForm(root) {
  for (const box of dayRows(root)) {
    if (box.dataset.ready) continue;
    box.dataset.ready = '1';
    box.addEventListener('change', () => syncDay(root, box));
    syncDay(root, box);
  }
}

/** The listing details from the form fields. */
export function formDetails(root) {
  const checked = (n) => !!root.querySelector(`input[name="${n}"]`)?.checked;
  return {
    categories: [...root.querySelectorAll('input[name="categories"]:checked')].map((i) => i.value),
    ...Object.fromEntries(FLAGS.map((f) => [f, checked(f)])),
    payment_types: [...root.querySelectorAll('input[name="payment_types"]:checked')].map((i) => i.value),
  };
}

/** The listing's sale days as windows, each with its own hours. */
export function formExtras(root) {
  return { windows: dayRows(root).filter((b) => b.checked).map((b) => ({ date: b.value, start: select(root, 'day_start', b.value)?.value, end: select(root, 'day_end', b.value)?.value })) };
}

/** Puts a listing's details and days (with their hours) into the form fields. */
export function fillForm(form, details, listing) {
  for (const i of form.querySelectorAll('input[name="categories"]')) i.checked = (details.categories || []).includes(i.value);
  for (const f of FLAGS) if (form[f]) form[f].checked = !!details[f];
  for (const i of form.querySelectorAll('input[name="payment_types"]')) i.checked = (details.payment_types || []).includes(i.value);
  if (!listing) return;
  for (const box of dayRows(form)) {
    const w = (listing.windows || []).find((x) => x.date === box.value);
    box.checked = Boolean(w);
    if (w) { select(form, 'day_start', w.date).value = w.start; select(form, 'day_end', w.date).value = w.end; }
    syncDay(form, box);
  }
}

/** Field errors the form shows before sending (the server checks the same and more, including the note). */
export function validateDetails(x) {
  return (x.categories || []).length ? {} : { categories: 'Choose at least one category.' };
}
