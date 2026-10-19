// Unit tests for the DOM-free browser libraries (filters, time, route order, store).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { matches, DEFAULT_FILTERS, isLowSensory, activeFilterCount } from '../../public/assets/js/lib/filters.js';
import { statusOf, time12, nowInZone } from '../../public/assets/js/lib/time.js';
import { planStops, orderGroup, haversine, applyOrder, moveStop } from '../../public/assets/js/lib/route.js';
import { createStore } from '../../public/assets/js/lib/store.js';
import { zoneStyle } from '../../public/assets/js/lib/render.js';

const home = (o) => ({ id: 1, address: '1 Pumpkin Ln', zone: 'oak', lat: 40.03, lon: -74.99, start: '17:00', end: '21:00', candy: true, nonfood: false,
  adult_drinks: false, low_noise: true, jump_scares: false, flashing_lights: false, loud_audio: false, scare_level: 'gentle', stroller_friendly: false, note: '', status: 'active', ...o });
const ctx = { today: '2026-10-31', eventDate: '2026-10-31', nowMinutes: 18 * 60, saved: [2], zoneLabels: { oak: 'Oak' } };

test('filters are conjunctive across categories', () => {
  const f = { ...DEFAULT_FILTERS, candy: true, lowSensory: true };
  assert.equal(matches(home(), f, ctx), true);
  assert.equal(matches(home({ flashing_lights: true }), f, ctx), false);
  assert.equal(matches(home({ candy: false, nonfood: true }), f, ctx), false);
  assert.equal(matches(home(), { ...DEFAULT_FILTERS, zone: 'elm' }, ctx), false);
  assert.equal(matches(home(), { ...DEFAULT_FILTERS, saved: true }, ctx), false);
  assert.equal(matches(home({ id: 2 }), { ...DEFAULT_FILTERS, saved: true }, ctx), true);
  assert.equal(matches(home({ scare_level: 'intense' }), { ...DEFAULT_FILTERS, noIntense: true }, ctx), false);
  assert.equal(isLowSensory(home({ loud_audio: true })), false);
  assert.equal(activeFilterCount({ ...DEFAULT_FILTERS, candy: true, zone: 'x', q: ' ' }), 2);
});

test('search matches address, note, zone label and offering words', () => {
  assert.equal(matches(home({ note: 'Allergy friendly' }), { ...DEFAULT_FILTERS, q: 'allergy oak' }, ctx), true);
  assert.equal(matches(home(), { ...DEFAULT_FILTERS, q: 'pumpkin candy' }, ctx), true);
  assert.equal(matches(home(), { ...DEFAULT_FILTERS, q: 'haunted' }, ctx), false);
});

test('time filters: open now uses event-day clock; visit-at uses the hours', () => {
  assert.equal(matches(home(), { ...DEFAULT_FILTERS, time: 'now' }, ctx), true);
  assert.equal(matches(home({ start: '19:00' }), { ...DEFAULT_FILTERS, time: 'now' }, ctx), false);
  assert.equal(matches(home(), { ...DEFAULT_FILTERS, time: 'now' }, { ...ctx, today: '2026-10-30' }), false, 'not event day');
  assert.equal(matches(home({ status: 'paused' }), { ...DEFAULT_FILTERS, time: 'now' }, ctx), false);
  assert.equal(matches(home(), { ...DEFAULT_FILTERS, time: '21:00' }, ctx), false, 'end is exclusive');
  assert.equal(matches(home(), { ...DEFAULT_FILTERS, time: '20:00' }, ctx), true);
});

test('status labels', () => {
  assert.deepEqual(statusOf(home(), ctx), { key: 'open', label: 'Handing out' });
  assert.equal(statusOf(home({ start: '18:30' }), ctx).label, 'Starts 6:30 PM');
  assert.equal(statusOf(home({ end: '18:00' }), ctx).key, 'done');
  assert.equal(statusOf(home({ status: 'out_of_candy' }), ctx).label, 'Out of candy');
  assert.equal(statusOf(home(), { ...ctx, today: '2026-10-20' }).label, '5 PM–9 PM');
  assert.equal(time12('00:30'), '12:30 AM');
  const n = nowInZone('America/New_York', new Date('2026-10-31T22:15:00Z'));
  assert.deepEqual(n, { date: '2026-10-31', minutes: 18 * 60 + 15 });
});

test('stop planner never mixes separate groups and keeps every stop', () => {
  const stops = [
    { id: 1, zone: 'oak', lat: 40.040, lon: -75.000 }, { id: 2, zone: 'elm', lat: 40.041, lon: -74.992 },
    { id: 3, zone: 'lake', lat: 40.030, lon: -74.995 }, { id: 4, zone: 'ridge-c', lat: 40.027, lon: -74.982 },
    { id: 5, zone: 'ridge-a', lat: 40.030, lon: -74.988 }, { id: 6, zone: 'oak', lat: 40.036, lon: -75.001 },
  ];
  const groups = [['oak', 'elm', 'ash'], ['lake'], ['ridge-a', 'ridge-b', 'ridge-c']];
  const plan = planStops(stops, { groups, zonesEnabled: true });
  assert.equal(plan.length, 3);
  for (const g of plan) {
    const gi = groups.findIndex((gr) => gr.includes(g.stops[0].zone));
    assert.ok(g.stops.every((s) => groups[gi].includes(s.zone)), 'no cross-group stop');
  }
  assert.deepEqual(plan.flatMap((g) => g.stops.map((s) => s.id)).sort(), [1, 2, 3, 4, 5, 6]);
  const withStart = planStops(stops, { groups, zonesEnabled: true, start: stops[3] });
  assert.equal(withStart[0].stops[0].id, 4, 'start group first, start stop first');
  // Unlisted zones form their own excursion; no zones => single group.
  assert.equal(planStops([{ id: 9, zone: 'other', lat: 0, lon: 0 }], { groups, zonesEnabled: true })[0].key, 'zone-other');
  assert.equal(planStops(stops, { zonesEnabled: false }).length, 1);
});

test('2-opt produces a path no longer than nearest-neighbour on a zig-zag', () => {
  const pts = [[0, 0], [0, 2], [0, 1], [0, 3], [0, 0.5]].map(([lat, lon], i) => ({ id: i, lat: 40 + lat / 1000, lon: -75 + lon / 1000 }));
  const route = orderGroup(pts);
  const len = route.slice(1).reduce((s, p, i) => s + haversine(route[i], p), 0);
  assert.ok(len <= haversine(pts[0], pts[3]) + 1e-6, `monotone path expected, got ${route.map((r) => r.id)}`);
  const truncated = planStops(Array.from({ length: 25 }, (_, i) => ({ id: i, lat: 28 + i / 1e4, lon: -81 })), { maxPerGroup: 20 });
  assert.equal(truncated[0].stops.length, 20);
  assert.equal(truncated[0].truncated, true);
});

test('store is namespaced by community and year and survives storage failures', () => {
  const mem = new Map();
  const storage = { getItem: (k) => mem.get(k) ?? null, setItem: (k, v) => mem.set(k, v) };
  const s = createStore('maple-hollow-demo', '2026', storage);
  s.toggleSaved(5); s.toggleSaved(7); s.toggleSaved(5);
  assert.deepEqual(createStore('maple-hollow-demo', '2026', storage).get().saved, [7]);
  assert.deepEqual(createStore('maple-hollow-demo', '2027', storage).get().saved, []);
  const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } };
  const b = createStore('x', '2026', broken);
  b.toggleSaved(1);
  assert.deepEqual(b.get().saved, [1]);
});

test('selecting a zone makes it stand out and the others recede', () => {
  const z = (key, residential = true) => ({ key, residential });
  assert.equal(zoneStyle(z('a'), null).weight, 1);
  assert.equal(zoneStyle(z('a'), 'a').weight, 3);
  assert.equal(zoneStyle(z('a'), 'a').fillOpacity > zoneStyle(z('b'), 'a').fillOpacity, true);
  assert.equal(zoneStyle(z('b'), 'a').opacity, 0.5);
  assert.equal(zoneStyle(z('club', false), null).fill, true, 'non-residential areas are shaded');
});

test('a "coming soon" zone is drawn faintly dotted and never looks selected', () => {
  const soon = { key: 'p7', residential: true, status: 'coming_soon' };
  assert.equal(zoneStyle(soon, null).dashArray, '2 6');
  assert.equal(zoneStyle(soon, 'p7').weight, 1, 'it cannot be highlighted like an active zone');
  assert.equal(zoneStyle(soon, 'p1').opacity < zoneStyle(soon, null).opacity, true);
});

test('starting from the visitor\'s location: nearest trip first, nearest stop first, no stop invented', () => {
  const stops = [
    { id: 1, zone: 'north', lat: 40.050, lon: -75.000 }, { id: 2, zone: 'north', lat: 40.052, lon: -75.000 },
    { id: 3, zone: 'south', lat: 40.010, lon: -75.000 }, { id: 4, zone: 'south', lat: 40.012, lon: -75.000 },
  ];
  const groups = [['north'], ['south']];
  const here = { id: null, lat: 40.013, lon: -75.000 };
  const plan = planStops(stops, { groups, zonesEnabled: true, start: here });
  assert.deepEqual(plan.map((g) => g.stops.map((s) => s.id)), [[4, 3], [1, 2]], 'south trip first (nearest), each trip starts at its stop nearest the location');
  assert.equal(plan.flatMap((g) => g.stops).length, 4, 'the location is a start point, never a stop');
  assert.ok(plan.every((g) => !('near' in g)));
});

test('a visitor can reorder stops within a trip; new stops keep their suggested place', () => {
  const plan = [{ key: 'a', zones: [], stops: [{ id: 1 }, { id: 2 }, { id: 3 }] }, { key: 'b', zones: [], stops: [{ id: 7 }, { id: 8 }] }];
  const order = moveStop(plan, 3, -1);
  assert.deepEqual(order, [1, 3, 2, 7, 8]);
  assert.deepEqual(moveStop(plan, 1, -1), [1, 2, 3, 7, 8], 'cannot move above the first stop');
  assert.deepEqual(moveStop(plan, 3, 1), [1, 2, 3, 7, 8], 'cannot move below the last stop of its trip');
  const replanned = [{ key: 'a', zones: [], stops: [{ id: 2 }, { id: 9 }, { id: 1 }, { id: 3 }] }, { key: 'b', zones: [], stops: [{ id: 8 }, { id: 7 }] }];
  assert.deepEqual(applyOrder(replanned, order).map((g) => g.stops.map((s) => s.id)), [[1, 3, 2, 9], [7, 8]]);
  assert.equal(applyOrder(replanned, null), replanned);
});
