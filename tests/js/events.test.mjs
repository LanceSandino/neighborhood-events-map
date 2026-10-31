// Event-type modules in the browser (ROADMAP Stage 1): the registry by key, and Halloween parity — filters, tags,
// symbols, pins, cards, statuses and filter normalization through the Halloween module give exactly what the 0.9
// libraries gave (frozen in tests/js/legacy/) for 400 seeded random homes, filters and moments.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { eventType, eventTypeKeys, DEFAULT_EVENT_TYPE } from '../../public/assets/js/events/index.js';
import * as halloween from '../../public/assets/js/events/halloween.js';
import { matches, DEFAULT_FILTERS, defaultFilters, normalizeFilters, choiceCounts } from '../../public/assets/js/lib/filters.js';
import { statusOf } from '../../public/assets/js/lib/time.js';
import { tagsHtml, symbolOf, pinClass, pinHtml, cardHtml, selectedHtml } from '../../public/assets/js/lib/render.js';
import * as old from './legacy/filters.js';
import * as oldTime from './legacy/time.js';
import * as oldRender from './legacy/render.js';

/** A small seeded generator (mulberry32), so every run checks the same cases. */
function rng(seed) {
  let a = seed >>> 0;
  return () => { a = (a + 0x6D2B79F5) >>> 0; let t = a; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}
const rand = rng(20261031);
const pick = (xs) => xs[Math.floor(rand() * xs.length)];
const coin = (p = 0.5) => rand() < p;

const TIMES = ['15:00', '16:30', '17:00', '18:00', '18:45', '20:00', '21:00', '22:30'];
const randomHome = (id) => {
  const [start, end] = [pick(TIMES), pick(TIMES)].sort();
  return {
    id, address: `${id} ${pick(['Pumpkin Ln', 'Cobweb Ct', 'Lantern Way'])}`, zone: pick(['oak', 'elm', 'lake', null]), lat: 40 + rand() / 100, lon: -75 + rand() / 100,
    start, end: end === start ? '23:00' : end, candy: coin(), nonfood: coin(0.3), adult_drinks: coin(0.3), low_noise: coin(), jump_scares: coin(0.2),
    flashing_lights: coin(0.2), loud_audio: coin(0.2), scare_level: pick(['gentle', 'mild', 'intense']), stroller_friendly: coin(0.4),
    note: pick(['', 'Allergy friendly bin', 'Glow sticks <b>at</b> the gate', 'Quiet porch']), status: pick(['active', 'active', 'active', 'paused', 'out_of_candy', 'done']),
  };
};
const randomFilters = () => ({
  ...DEFAULT_FILTERS,
  ...Object.fromEntries(Object.keys(DEFAULT_FILTERS).filter((k) => typeof DEFAULT_FILTERS[k] === 'boolean').map((k) => [k, coin(0.2)])),
  zone: pick(['all', 'all', 'oak', 'area:0', 'area:1']), time: pick(['any', 'any', 'now', '17:00', '19:30']), q: pick(['', '', 'candy', 'quiet oak', 'big scares', 'teal', 'kid', 'pumpkin allergy']),
});
const randomCtx = () => ({ today: pick(['2026-10-31', '2026-10-30']), eventDate: '2026-10-31', nowMinutes: Math.floor(rand() * 24 * 60), saved: [1, 3, 5], zoneLabels: { oak: 'Oak', elm: 'Elm' }, areas: [['oak', 'elm'], ['lake']] });

test('the registry knows Halloween by its key and refuses unknown keys', () => {
  assert.equal(DEFAULT_EVENT_TYPE, 'halloween');
  assert.deepEqual(eventTypeKeys(), ['halloween']);
  assert.equal(eventType(), halloween);
  assert.equal(eventType('halloween'), halloween);
  assert.equal(halloween.key, 'halloween');
  for (const bad of ['holiday_lights', 'garage_sale', 'constructor', '__proto__', 'Halloween']) assert.throws(() => eventType(bad), /Unknown event type/);
});

test('Halloween filters: the 0.9 filter object, its quick toggles and hours', () => {
  assert.equal(JSON.stringify(defaultFilters(halloween)), JSON.stringify(old.DEFAULT_FILTERS), 'same keys in the same order (share links stay identical)');
  assert.equal(JSON.stringify(DEFAULT_FILTERS), JSON.stringify(old.DEFAULT_FILTERS));
  assert.deepEqual(halloween.filters.filter((f) => f.quick).map((f) => f.key), ['candy', 'lowSensory', 'adult'], 'the quick filters of HalloweenType::filters()');
  assert.deepEqual(halloween.hours, { start: [15, 22], end: [15, 23], visit: [15, 22] });
  assert.deepEqual(halloween.validateDetails({ candy: false, nonfood: false, adult_drinks: false }), { offerings: 'Choose at least one: candy, non-food treats or adult refreshments.' });
  assert.deepEqual(halloween.validateDetails({ nonfood: true }), {});
});

test('400 seeded homes: filters, statuses, symbols, tags, pins and cards match 0.9 exactly through the Halloween module', () => {
  let shown = 0;
  for (let i = 1; i <= 400; i++) {
    const h = randomHome(i);
    const f = randomFilters();
    const ctx = randomCtx();
    const typed = { ...ctx, type: halloween };
    const focus = coin() ? f : {};
    assert.equal(matches(h, f, typed), old.matches(h, f, ctx), `matches #${i} ${JSON.stringify({ h, f, ctx })}`);
    assert.equal(matches(h, f, ctx), old.matches(h, f, ctx), 'Halloween when the context names no type');
    if (old.matches(h, f, ctx)) shown += 1;
    assert.deepEqual(statusOf(h, typed), oldTime.statusOf(h, ctx));
    assert.deepEqual(statusOf(h), oldTime.statusOf(h));
    assert.equal(symbolOf(h, focus, halloween), oldRender.symbolOf(h, focus));
    assert.equal(pinClass(h, focus, halloween), oldRender.pinClass(h, focus));
    assert.equal(tagsHtml(h, halloween), oldRender.tagsHtml(h));
    const pin = { dim: coin(), number: pick([undefined, 3]), focus };
    assert.equal(pinHtml(h, { ...pin, type: halloween }), oldRender.pinHtml(h, pin));
    const opts = { saved: coin(), active: coin(), focus };
    assert.equal(cardHtml(h, typed, opts), oldRender.cardHtml(h, ctx, opts), `card #${i}`);
    assert.equal(selectedHtml(h, typed, opts.saved), oldRender.selectedHtml(h, ctx, opts.saved), `selected #${i}`);
  }
  assert.ok(shown > 20 && shown < 400, `the matrix shows some homes and hides others (${shown})`);
});

test('pins match 0.9 for every dim, number and focus', () => {
  for (let i = 1; i <= 100; i++) {
    const h = randomHome(i);
    for (const dim of [false, true]) for (const number of [undefined, 7]) for (const focus of [{}, { adult: true }, { lowSensory: true }, { candy: true }]) {
      assert.equal(pinHtml(h, { dim, number, focus, type: halloween }), oldRender.pinHtml(h, { dim, number, focus }));
      assert.equal(pinHtml(h, { dim, number, focus }), oldRender.pinHtml(h, { dim, number, focus }));
    }
  }
});

test('stored and shared filters normalize exactly as in 0.9, old jump/intense filters included', () => {
  const raws = [null, [], 'x', {}, { noJumps: true }, { noIntense: true, candy: true }, { noIntense: false }, { q: 5, zone: 7 }, { time: '18:30', zone: 'area:1', stroller: true },
    { constructor: 'x', candy: 'yes', saved: true }, { q: 'x'.repeat(120), time: 'soon', adult: true, bogus: 1 }];
  for (let i = 0; i < 60; i++) raws.push(Object.fromEntries(Object.keys(DEFAULT_FILTERS).filter(() => coin(0.4)).map((k) => [k, pick([true, false, 'x', 3, 'now', '07:45'])])));
  for (const raw of raws) {
    assert.deepEqual(normalizeFilters(raw, halloween), old.normalizeFilters(raw), JSON.stringify(raw));
    assert.deepEqual(normalizeFilters(raw), old.normalizeFilters(raw));
  }
});

test('choice counts per toggle and zone are the 0.9 counts', () => {
  const homes = Array.from({ length: 60 }, (_, i) => randomHome(i + 1));
  const ctx = randomCtx();
  const toggles = halloween.filters.map((f) => f.key).concat('saved');
  for (let i = 0; i < 20; i++) {
    const f = randomFilters();
    const keep = (h) => h.status === 'active';
    assert.deepEqual(choiceCounts(homes, f, { ...ctx, type: halloween }, { toggles, zones: ['all', 'oak', 'area:0'], keep }),
      old.choiceCounts(homes, f, ctx, { toggles, zones: ['all', 'oak', 'area:0'], keep }));
  }
});

test('route notes, open and visit-at rules come from the module', () => {
  assert.equal(halloween.routeNote({ low_noise: true, flashing_lights: false, loud_audio: false, jump_scares: false, scare_level: 'gentle' }), ' · 🧩 Sensory-friendly');
  assert.equal(halloween.routeNote({ low_noise: false, jump_scares: false, scare_level: 'intense' }), ' · 👻 Big scares');
  assert.equal(halloween.isOpen({ status: 'active' }), true);
  assert.equal(halloween.isOpen({ status: 'out_of_candy' }), false);
  assert.equal(halloween.offForTheDay({ status: 'done' }), true);
  assert.equal(halloween.offForTheDay({ status: 'paused' }), false, 'a paused home still matches a visit-at time (as in 0.9)');
  assert.deepEqual(halloween.statusOf({ status: 'constructor', start: '17:00', end: '21:00' }, null), { key: 'later', label: '5 PM–9 PM' }, 'an unexpected status is not looked up on the prototype');
});
