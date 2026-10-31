// The garage sale event type in the browser (events/garage_sale.js): its rules equal the shared fixture and
// GarageSaleType, every shared garage_sale display-status case through the module's clock, never "Open now" outside a
// home's hours, sold out and paused hold for their own day only, closed, the filters (categories, open now, open on each
// sale day, multi-family, early birds) and search over categories and notes, card hours per day (different Saturday
// and Sunday hours), tags with words (never colour only), pins, the season of a sale with its own days, the listing
// form's details and per-day windows, escaping of resident text, and the theme's token blocks.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as sale from '../../public/assets/js/events/garage_sale.js';
import { eventType } from '../../public/assets/js/events/index.js';
import { defaultFilters, normalizeFilters, matches, activeFilterCount } from '../../public/assets/js/lib/filters.js';
import { cardHtml, selectedHtml, pinHtml, tagsHtml } from '../../public/assets/js/lib/render.js';
import { nowInZone } from '../../public/assets/js/lib/clock.js';
import { seasonOf } from '../../public/assets/js/lib/schedule.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/schedule/display-status.json', import.meta.url), 'utf8'));
const EVENT = { id: 9, type: 'garage_sale', starts_on: '2027-04-10', ends_on: '2027-04-11', days: ['2027-04-10', '2027-04-11'], timezone: 'America/Chicago' };
const SAT = '2027-04-10';
const SUN = '2027-04-11';
const ctxAt = (today, hhmm, extra = {}) => {
  const [h, m] = hhmm.split(':').map(Number);
  return { today, nowMinutes: h * 60 + m, zoneLabels: { north: 'North' }, saved: [], areas: [], type: sale, event: EVENT, ...extra };
};
const w = (date, start = '08:00', end = '14:00') => ({ date, start, end });
const home = (id, details = {}, extra = {}) => ({
  id, address: `${id} Juniper Ct`, zone: 'north', lat: 29.2, lon: -81.68, start: '08:00', end: '14:00', note: '', status: 'active', status_date: null,
  details: { categories: ['toys'], multi_family: false, early_birds_allowed: false, payment_types: [], ...details },
  windows: [w(SAT), w(SUN)], ...extra,
});

test('the registry offers the garage sale; its rules are the shared fixture\'s', () => {
  assert.equal(eventType('garage_sale'), sale);
  assert.deepEqual(JSON.parse(JSON.stringify(sale.displayRules)), fixture.rules.garage_sale);
  assert.deepEqual(sale.filters.filter((f) => f.quick).map((f) => f.key), ['kidsToys', 'furniture', 'tools'], 'GarageSaleType::filters() quick ones');
  assert.deepEqual(sale.filters.filter((f) => !f.quick).map((f) => f.key), ['household', 'electronics', 'free', 'openNow', 'day1', 'day2', 'day3', 'multiFamily', 'earlyBirds']);
  assert.equal(sale.migratesYearStore, false);
  assert.deepEqual(sale.hours, { start: [6, 17], end: [7, 20], visit: [6, 19] });
  assert.deepEqual(Object.keys(sale.CATEGORIES), ['kids_baby', 'toys', 'furniture', 'clothing', 'tools', 'books_media', 'electronics', 'sports', 'household', 'free']);
});

test('every shared garage_sale display-status case through the module\'s clock', () => {
  const cases = fixture.cases.filter((c) => c.type === 'garage_sale');
  assert.ok(cases.length >= 13);
  for (const c of cases) {
    const n = nowInZone(c.timezone, new Date(c.now));
    assert.equal(sale.displayKey({ status: c.status, status_date: c.status_date, windows: c.windows }, { today: n.date, nowMinutes: n.minutes }), c.expected, c.name);
  }
});

test('never "Open now" outside a home\'s hours; different Saturday and Sunday hours', () => {
  const h = home(1, {}, { windows: [w(SAT, '07:00', '12:00'), w(SUN, '09:00', '13:00')] });
  for (const day of [SAT, SUN, '2027-04-12']) {
    for (let m = 0; m < 24 * 60; m += 5) {
      const hhmm = `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
      const win = h.windows.find((x) => x.date === day);
      const inside = Boolean(win && hhmm >= win.start && hhmm < win.end);
      assert.equal(sale.statusOf(h, ctxAt(day, hhmm)).key === 'open', inside, `${day} ${hhmm}`);
    }
  }
  assert.deepEqual(sale.statusOf(h, ctxAt(SAT, '06:30')), { key: 'opens_at', label: 'Opens 7 AM' });
  assert.deepEqual(sale.statusOf(h, ctxAt(SAT, '12:30')), { key: 'not_today', label: 'Opens Sun 9 AM' }, 'after Saturday\'s hours: Sunday\'s');
  assert.deepEqual(sale.statusOf(h, ctxAt(SUN, '08:59')), { key: 'opens_at', label: 'Opens 9 AM' });
  assert.deepEqual(sale.statusOf(h, ctxAt(SUN, '13:00')), { key: 'ended', label: 'No more sale hours' }, 'the end minute is not open');
  assert.equal(sale.hoursLabel(h, ctxAt(SAT, '09:00')), 'Sat 7 AM–12 PM · Sun 9 AM–1 PM');
  assert.equal(sale.hoursLabel(home(2), ctxAt(SAT, '09:00')), 'Sat–Sun 8 AM–2 PM');
  assert.equal(sale.hoursLabel(home(3, {}, { windows: [w(SUN, '08:00', '11:00')] }), ctxAt(SAT, '09:00')), 'Sun 8–11 AM');
  const twoSaturdays = { ...EVENT, ends_on: '2027-04-17', days: [SAT, '2027-04-17'] };
  assert.equal(sale.hoursLabel(home(4, {}, { windows: [w(SAT), w('2027-04-17')] }), ctxAt(SAT, '09:00', { event: twoSaturdays })), 'Sat Apr 10 & Sat Apr 17 8 AM–2 PM');
});

test('sold out and paused hold for their own day; closed holds for the sale', () => {
  const soldSat = home(1, {}, { status: 'sold_out', status_date: SAT });
  assert.deepEqual(sale.statusOf(soldSat, ctxAt(SAT, '10:00')), { key: 'sold_out', label: 'Sold out today' }, 'sold out suppresses "open now"');
  assert.deepEqual(sale.statusOf(soldSat, ctxAt(SUN, '10:00')), { key: 'open', label: 'Open now' }, 'open again on Sunday');
  assert.equal(sale.isOpen(soldSat, ctxAt(SAT, '10:00')), true, 'still has Sunday');
  assert.equal(sale.isOpen(home(2, {}, { status: 'sold_out', status_date: SUN }), ctxAt(SUN, '10:00')), false, 'sold out on its last day');
  const paused = home(3, {}, { status: 'paused', status_date: SUN });
  assert.equal(sale.statusOf(paused, ctxAt(SAT, '10:00')).key, 'open', 'paused for Sunday does not hold on Saturday');
  assert.deepEqual(sale.statusOf(paused, ctxAt(SUN, '10:00')), { key: 'paused', label: 'Paused today' });
  const closed = home(4, {}, { status: 'closed' });
  assert.deepEqual(sale.statusOf(closed, ctxAt(SAT, '10:00')), { key: 'closed', label: 'Closed' });
  assert.equal(sale.isOpen(closed, ctxAt(SAT, '07:00')), false);
  assert.equal(sale.offForTheDay(closed), true);
  assert.equal(sale.inactive(soldSat, ctxAt(SAT, '10:00')), true, 'a sold-out pin is greyed');
  assert.equal(sale.inactive(soldSat, ctxAt(SUN, '10:00')), false);
  assert.equal(sale.inactive(home(5), ctxAt('2027-03-01', '10:00')), false, 'before the sale every pin is ready');
  assert.equal(sale.inactive(home(5), ctxAt('2027-04-12', '10:00')), true, 'the sale is over');
  assert.equal(sale.inactive(closed, ctxAt('2027-03-01', '10:00')), true);
});

test('filters: categories, open now, open on each sale day, multi-family, early birds; search over categories and notes', () => {
  const homes = [
    home(1, { categories: ['kids_baby', 'clothing'], multi_family: true }, { note: 'Stroller and crib sheets' }),
    home(2, { categories: ['toys', 'electronics'], early_birds_allowed: true }, { windows: [w(SAT, '10:00', '16:00')] }),
    home(3, { categories: ['furniture', 'household'] }, { windows: [w(SUN, '09:00', '14:00')] }),
    home(4, { categories: ['tools', 'free'] }, { status: 'sold_out', status_date: SAT }),
    home(5, { categories: ['books_media', 'free'] }, { status: 'closed' }),
    home(6, { categories: ['sports', 'tools'], payment_types: ['card'] }, { status: 'paused', status_date: SUN, windows: [w(SUN, '08:00', '11:00')] }),
  ];
  const base = defaultFilters(sale);
  assert.deepEqual(Object.keys(base), ['q', 'zone', 'time', 'kidsToys', 'furniture', 'tools', 'household', 'electronics', 'free', 'openNow', 'day1', 'day2', 'day3',
    'multiFamily', 'earlyBirds', 'saved']);
  const ids = (f, ctx = ctxAt(SAT, '09:00')) => homes.filter((h) => matches(h, { ...base, ...f }, ctx)).map((h) => h.id);
  assert.deepEqual(ids({ kidsToys: true }), [1, 2], 'kids & baby or toys');
  assert.deepEqual(ids({ furniture: true }), [3]);
  assert.deepEqual(ids({ tools: true }), [4, 6]);
  assert.deepEqual(ids({ household: true }), [3]);
  assert.deepEqual(ids({ electronics: true }), [2]);
  assert.deepEqual(ids({ free: true }), [4, 5]);
  assert.deepEqual(ids({ openNow: true }), [1], '9 AM Saturday: not 2 (opens at 10), not 4 (sold out today), not 5 (closed)');
  assert.deepEqual(ids({ openNow: true }, ctxAt(SAT, '10:30')), [1, 2]);
  assert.deepEqual(ids({ openNow: true }, ctxAt(SUN, '09:30')), [1, 3, 4], 'Sunday: 4 is back, 6 is paused today');
  assert.deepEqual(ids({ day1: true }), [1, 2], 'open Saturday: not the home sold out on Saturday or the closed one');
  assert.deepEqual(ids({ day2: true }), [1, 3, 4], 'open Sunday: not the home paused on Sunday');
  assert.deepEqual(ids({ day3: true }), [], 'a two-day sale has no third day');
  assert.deepEqual(ids({ day1: true, day2: true }), [1]);
  assert.deepEqual(ids({ multiFamily: true }), [1]);
  assert.deepEqual(ids({ earlyBirds: true }), [2]);
  assert.deepEqual(ids({ q: 'stroller' }), [1], 'notes');
  assert.deepEqual(ids({ q: 'books' }), [5], 'category words');
  assert.deepEqual(ids({ q: 'kids' }), [1]);
  assert.deepEqual(ids({ q: 'free items' }), [4, 5]);
  assert.deepEqual(ids({ q: 'card' }), [6], 'payment kinds');
  assert.deepEqual(ids({ q: 'multi-family' }), [1]);
  assert.equal(activeFilterCount({ ...base, day2: true, tools: true }, sale), 2);
  assert.deepEqual(normalizeFilters({ day1: true, tools: true, driveBy: true, candy: true, night: '2027-04-10' }, sale), { day1: true, tools: true }, 'other types\' filters are dropped');
});

test('tags carry words, never only colour; pins show the first category or the quick filter picked', () => {
  const h = home(1, { categories: ['free', 'kids_baby'], multi_family: true, early_birds_allowed: true, payment_types: ['cash', 'apps'] });
  assert.deepEqual(sale.tags(h).map((x) => `${x.icon} ${x.text}`), ['🍼 Kids & baby', '🆓 Free items', '🏘️ Multi-family', '🐦 Early birds welcome', '💵 Cash · Apps']);
  assert.equal(sale.symbol(h), '🍼', 'the first category in canonical order');
  assert.equal(sale.symbol(home(2, { categories: ['clothing', 'tools'] }), { tools: true }), '🛠️', 'the Tools filter is on');
  assert.equal(sale.symbol(home(3, { categories: [] })), '🏷️');
  assert.equal(sale.pinClass(h), 'sale');
  for (const word of Object.values(sale.copy)) assert.doesNotMatch(word, /\$\d|price|buy now|checkout/i, 'no commerce words');
});

test('cards, the selected home and pins: status chips from the first sale day, resident text escaped', () => {
  const evil = home(9, {}, { note: '<img src=x onerror=alert(1)>', address: '9 <b>Juniper</b> Ct' });
  const card = cardHtml(evil, ctxAt(SAT, '10:00'), { saved: false, active: false, focus: {} });
  assert.doesNotMatch(card, /<img src=x/);
  assert.match(card, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.match(card, /9 &lt;b&gt;Juniper&lt;\/b&gt; Ct/);
  assert.match(card, /card-status open">Open now</);
  assert.match(card, /North · <span class="card-hours">Sat–Sun 8 AM–2 PM<\/span>/);
  assert.doesNotMatch(cardHtml(evil, ctxAt('2027-03-20', '10:00'), { saved: false, active: false, focus: {} }), /card-status/, 'before the sale no chip says "opens Sat"');
  assert.match(cardHtml(home(2, {}, { status: 'sold_out', status_date: SAT }), ctxAt(SAT, '10:00'), { saved: false, active: false, focus: {} }), /card-status sold_out">Sold out today</);
  assert.match(selectedHtml(evil, ctxAt(SAT, '10:00'), false), /♡ Save this stop/);
  assert.match(pinHtml(evil, { focus: {}, type: sale, ctx: ctxAt(SAT, '10:00') }), /class="pin sale"/);
  assert.match(pinHtml(home(3, {}, { status: 'closed' }), { focus: {}, type: sale, ctx: ctxAt(SAT, '10:00') }), /class="pin sale inactive"/);
  assert.doesNotMatch(tagsHtml(home(1, { categories: ['<script>', 'toys'] }), sale), /<script>/i);
});

test('the season of a sale with its own days: before, during, between, after', () => {
  assert.equal(seasonOf(EVENT, '2027-04-09'), 'before');
  assert.equal(seasonOf(EVENT, SAT), 'during');
  assert.equal(seasonOf(EVENT, SUN), 'during');
  assert.equal(seasonOf(EVENT, '2027-04-12'), 'after');
  const twoSaturdays = { starts_on: SAT, ends_on: '2027-04-17', days: [SAT, '2027-04-17'] };
  assert.equal(seasonOf(twoSaturdays, '2027-04-13'), 'between', 'a weekday between two sale Saturdays');
  assert.equal(seasonOf({ starts_on: '2026-12-01', ends_on: '2027-01-03' }, '2026-12-25'), 'during', 'an event without days (lights) is unchanged');
});

/** A tiny stand-in for a form: named inputs and selects with value/checked/disabled and the selectors the module uses. */
function fakeForm(inputs) {
  const all = inputs.map((i) => ({ checked: false, value: '', disabled: false, dataset: {}, addEventListener() {}, ...i }));
  const match = (sel) => {
    const m = sel.match(/^(input|select)\[name="([^"]+)"\](?:\[data-day="([^"]+)"\])?(:checked)?$/);
    return m ? all.filter((i) => (i.tag || 'input') === m[1] && i.name === m[2] && (!m[3] || i.day === m[3]) && (!m[4] || i.checked)) : [];
  };
  const form = { querySelector: (sel) => match(sel)[0] || null, querySelectorAll: (sel) => match(sel) };
  for (const i of all) if (['multi_family', 'early_birds_allowed'].includes(i.name)) form[i.name] = i;
  return form;
}

test('the listing form: categories, flags, payment kinds and the sale days with their own hours', () => {
  const form = fakeForm([
    { name: 'categories', value: 'toys', checked: true }, { name: 'categories', value: 'tools', checked: true }, { name: 'categories', value: 'free' },
    { name: 'multi_family', checked: true }, { name: 'early_birds_allowed' },
    { name: 'payment_types', value: 'cash', checked: true }, { name: 'payment_types', value: 'card' }, { name: 'payment_types', value: 'apps', checked: true },
    { name: 'sale_day', value: SAT, checked: true }, { name: 'sale_day', value: SUN, checked: true },
    { tag: 'select', name: 'day_start', day: SAT, value: '07:00' }, { tag: 'select', name: 'day_end', day: SAT, value: '12:00' },
    { tag: 'select', name: 'day_start', day: SUN, value: '09:00' }, { tag: 'select', name: 'day_end', day: SUN, value: '13:00' },
  ]);
  sale.initForm(form);
  assert.deepEqual(sale.formDetails(form), { categories: ['toys', 'tools'], multi_family: true, early_birds_allowed: false, payment_types: ['cash', 'apps'] });
  assert.deepEqual(sale.formExtras(form).windows, [w(SAT, '07:00', '12:00'), w(SUN, '09:00', '13:00')], 'different hours each day');
  form.querySelectorAll('input[name="sale_day"]')[0].checked = false;
  assert.deepEqual(sale.formExtras(form).windows, [w(SUN, '09:00', '13:00')], 'only the days ticked');
  sale.fillForm(form, { categories: ['free'], multi_family: false, early_birds_allowed: true, payment_types: ['card'] }, { windows: [w(SAT, '10:00', '16:00')] });
  assert.deepEqual(sale.formDetails(form), { categories: ['free'], multi_family: false, early_birds_allowed: true, payment_types: ['card'] });
  assert.deepEqual(sale.formExtras(form).windows, [w(SAT, '10:00', '16:00')], 'My house fills the days and hours it chose');
  assert.equal(form.querySelector(`select[name="day_start"][data-day="${SUN}"]`).disabled, true, 'an unticked day\'s hours are off');
  assert.deepEqual(sale.validateDetails({ categories: [] }), { categories: 'Choose at least one category.' });
  assert.deepEqual(sale.validateDetails({ categories: ['toys'] }), {});
});

test('the sale theme: both dark blocks identical, every light token also set in dark, organizer colours never set', () => {
  const css = readFileSync(new URL('../../public/assets/css/events/garage_sale.css', import.meta.url), 'utf8');
  const light = css.match(/\n:root\[data-event-type="garage_sale"\] \{\n([\s\S]*?)\n\}/);
  const system = css.match(/@media \(prefers-color-scheme: dark\) \{\n {2}:root\[data-event-type="garage_sale"\]:not\(\[data-theme="light"\]\) \{\n([\s\S]*?)\n {2}\}\n\}/);
  const chosen = css.match(/\n:root\[data-event-type="garage_sale"\]\[data-theme="dark"\] \{\n([\s\S]*?)\n\}/);
  assert.ok(light && system && chosen, 'the three token blocks exist');
  const norm = (s) => s.split('\n').map((l) => l.trim()).filter(Boolean).join('\n');
  assert.equal(norm(chosen[1]), norm(system[1]));
  const names = (s) => [...s.matchAll(/(--[a-z0-9-]+):/g)].map((m) => m[1]).sort();
  assert.deepEqual(names(system[1]), names(light[1]), 'a light value never leaks into dark mode');
  assert.doesNotMatch(css, /--brand(-accent|-ink|-text)?\s*:/, '/theme.css (the organizer\'s colours) keeps winning');
  assert.doesNotMatch(css, /animation|@keyframes/, 'nothing moves');
  assert.match(light[1], /#178d80/i, 'teal');
  assert.match(light[1], /#e77c5d/i, 'coral');
});
