// Saved homes and routes per event (D-20261031-12): the 0.10 year store moves once into the active Halloween event's
// store and is removed; stores of different events never mix; shared links name their event.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createStore, migrateYearStore } from '../../public/assets/js/lib/store.js';
import { otherEvent, readSharedLink } from '../../public/assets/js/lib/share.js';
import * as halloween from '../../public/assets/js/events/halloween.js';

const memory = () => {
  const mem = new Map();
  return { mem, getItem: (k) => (mem.has(k) ? mem.get(k) : null), setItem: (k, v) => mem.set(k, String(v)), removeItem: (k) => mem.delete(k) };
};

test('the 0.10 year store moves into the active event once, and only once', () => {
  const s = memory();
  const old = createStore('maple-hollow-demo', '2026', s);
  old.toggleSaved(4); old.saveRoute('Little kids');
  assert.equal(halloween.migratesYearStore, true);
  assert.equal(migrateYearStore('maple-hollow-demo', '2026', 7, s), true);
  assert.equal(s.getItem('nhm:maple-hollow-demo:2026'), null, 'the year store is gone');
  const now = createStore('maple-hollow-demo', '7', s);
  assert.deepEqual(now.get().saved, [4]);
  assert.deepEqual(now.get().routes.map((r) => r.name), ['Little kids']);
  assert.equal(migrateYearStore('maple-hollow-demo', '2026', 7, s), false, 'nothing left to move');
  assert.equal(migrateYearStore('maple-hollow-demo', '2026', 8, s), false, 'a later event never gets it');
  assert.deepEqual(createStore('maple-hollow-demo', '8', s).get().saved, [], 'the next event starts empty');
});

test('an event store that exists already is kept; another community is untouched; storage failures are harmless', () => {
  const s = memory();
  createStore('a', '2026', s).toggleSaved(1);
  createStore('a', '3', s).toggleSaved(2);
  createStore('b', '2026', s).toggleSaved(9);
  assert.equal(migrateYearStore('a', '2026', 3, s), true);
  assert.deepEqual(createStore('a', '3', s).get().saved, [2], 'the newer event store wins');
  assert.deepEqual(createStore('b', '2026', s).get().saved, [9]);
  assert.equal(migrateYearStore('a', '2026', 2026, s), false, 'an event id equal to the year is the same store');
  const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); }, removeItem: () => { throw new Error('denied'); } };
  assert.equal(migrateYearStore('a', '2026', 3, broken), false);
});

test('a shared link for another event is recognized; links without an event are this event', () => {
  assert.equal(otherEvent(null, 7), false);
  assert.equal(otherEvent('', 7), false);
  assert.equal(otherEvent('7', 7), false);
  assert.equal(otherEvent('6', 7), true);
  assert.equal(otherEvent('7abc', 7), true, 'a damaged value is never this event');
  assert.equal(otherEvent('-7', 7), true);
  assert.equal(otherEvent('0007', 7), false);
});

test('a hostile shared link changes nothing: markup in e=, stops or start is never a stop, a filter or this event', () => {
  const clean = (f) => (f && typeof f === 'object' && !Array.isArray(f) ? Object.fromEntries(Object.entries(f).filter(([k, v]) => k === 'q' && typeof v === 'string')) : {});
  const q = (o) => '?' + new URLSearchParams(o).toString();
  assert.deepEqual(readSharedLink(q({ e: '<script>alert(1)</script>', stops: '1.2.3' }), 7, clean), { otherEvent: true }, 'markup in e= is another event: no stops');
  assert.deepEqual(readSharedLink(q({ e: '7"><img src=x onerror=alert(1)>', stops: '4' }), 7, clean), { otherEvent: true });
  assert.deepEqual(readSharedLink(q({ e: '7', stops: '<img src=x>.5.-3.x.6', start: '<b>9</b>' }), 7, clean),
    { stops: [5, 6], start: null, order: false }, 'only positive whole ids (ids that are not listed are dropped by the page); a damaged start is no start');
  const f = Buffer.from(JSON.stringify({ q: '<script>x</script>', constructor: { polluted: true }, __proto__: { polluted: true } })).toString('base64');
  const out = readSharedLink(q({ e: '7', f }), 7, clean);
  assert.deepEqual(out.filters, { q: '<script>x</script>' }, 'the filter text stays text (the page escapes it); nothing else comes through');
  assert.equal(({}).polluted, undefined, 'no prototype pollution');
  assert.deepEqual(readSharedLink(q({ e: '7', f: '%%%not-base64' }), 7, clean), {}, 'a damaged filter part is ignored');
});

