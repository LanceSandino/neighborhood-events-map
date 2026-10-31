// The display status in the browser (lib/schedule.js) on the fixtures PHPUnit shares (tests/fixtures/schedule), and
// Halloween's rules give exactly the 0.9 status words of events/halloween.js statusOf at every moment.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { displayStatus, effectiveStatus, phase } from '../../public/assets/js/lib/schedule.js';
import { nowInZone } from '../../public/assets/js/lib/clock.js';
import * as halloween from '../../public/assets/js/events/halloween.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/schedule/display-status.json', import.meta.url), 'utf8'));

test('every shared display-status case', () => {
  assert.ok(fixture.cases.length >= 40);
  for (const c of fixture.cases) {
    assert.equal(displayStatus(fixture.rules[c.type], c, new Date(c.now), c.timezone), c.expected, c.name);
  }
});

test('Halloween exports the rules the fixtures and HalloweenType use', () => {
  assert.deepEqual(JSON.parse(JSON.stringify(halloween.displayRules)), fixture.rules.halloween);
});

test('day-scoped statuses and phases', () => {
  const lights = fixture.rules.holiday_lights;
  assert.equal(effectiveStatus(lights, 'off_tonight', '2026-12-18', '2026-12-18'), 'off_tonight');
  assert.equal(effectiveStatus(lights, 'off_tonight', '2026-12-18', '2026-12-19'), 'active');
  assert.equal(effectiveStatus(lights, 'off_tonight', null, '2026-12-19'), 'active');
  assert.equal(effectiveStatus(lights, 'finished', null, '2026-12-19'), 'finished');
  assert.equal(effectiveStatus(lights, 'nonsense', null, '2026-12-19'), 'active');
  const w = [{ date: '2026-12-18', start: '18:00', end: '22:00' }];
  assert.equal(phase(w, '2026-12-18', '17:59'), 'later_today');
  assert.equal(phase(w, '2026-12-18', '18:00'), 'inside');
  assert.equal(phase(w, '2026-12-18', '21:59'), 'inside');
  assert.equal(phase(w, '2026-12-18', '22:00'), 'ended_today');
  assert.equal(phase(w, '2026-12-19', '19:00'), 'not_today');
  assert.equal(phase(undefined, '2026-12-19', '19:00'), 'not_today');
});

// A small deterministic generator (the same idea as events.test.mjs).
let seed = 20261031;
const rand = () => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed / 2147483648; };
const pick = (a) => a[Math.floor(rand() * a.length)];
const half = (h) => `${String(Math.floor(h)).padStart(2, '0')}:${h % 1 ? '30' : '00'}`;

test('Halloween: the shared rules say exactly what statusOf says, 2,000 seeded homes and moments', () => {
  const zones = ['America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Europe/Berlin'];
  for (let i = 0; i < 2000; i++) {
    const tz = pick(zones);
    const eventDate = pick(['2026-10-31', '2026-10-30', '2026-11-01', '2027-10-31']);
    const s = 15 + Math.floor(rand() * 14) / 2;
    const e = Math.min(23.5, s + 0.5 + Math.floor(rand() * 10) / 2);
    const home = { status: pick(['active', 'active', 'active', 'paused', 'out_of_candy', 'done']), start: half(s), end: half(e) };
    const now = new Date(Date.parse(`${eventDate}T12:00:00Z`) + Math.floor((rand() * 3 - 1.2) * 86400000));
    const n = nowInZone(tz, now);
    const expected = halloween.statusOf(home, { today: n.date, eventDate, nowMinutes: n.minutes }).key;
    const got = displayStatus(halloween.displayRules, { status: home.status, status_date: null, windows: [{ date: eventDate, start: home.start, end: home.end }] }, now, tz);
    assert.equal(got, expected, JSON.stringify({ home, now, tz, eventDate }));
  }
});
