// The Christmas lights event type in the browser (events/holiday_lights.js): its rules equal the shared fixture and
// HolidayLightsType, every shared holiday_lights display-status case through the module's clock, filters (drive-by,
// no flashing reported, music, walk-up, open tonight, one night, a style), Dec 31 → Jan 1, off tonight, done for the
// season, never "Lights on" outside a window, tags and words (no safety promise), pins, hours, the listing form's
// details and nights, and escaping of resident text in cards.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import * as lights from '../../public/assets/js/events/holiday_lights.js';
import { eventType } from '../../public/assets/js/events/index.js';
import { defaultFilters, normalizeFilters, matches, activeFilterCount } from '../../public/assets/js/lib/filters.js';
import { cardHtml, selectedHtml, pinHtml, tagsHtml } from '../../public/assets/js/lib/render.js';
import { nowInZone } from '../../public/assets/js/lib/clock.js';
import { seasonOf, eventDates } from '../../public/assets/js/lib/schedule.js';

const fixture = JSON.parse(readFileSync(new URL('../fixtures/schedule/display-status.json', import.meta.url), 'utf8'));
const EVENT = { id: 7, type: 'holiday_lights', starts_on: '2026-12-01', ends_on: '2027-01-03', timezone: 'America/Chicago' };
const ctxAt = (today, hhmm, extra = {}) => {
  const [h, m] = hhmm.split(':').map(Number);
  return { today, nowMinutes: h * 60 + m, zoneLabels: { north: 'North' }, saved: [], areas: [], type: lights, event: EVENT, ...extra };
};
const nights = (from, to, start = '18:00', end = '22:00') => eventDates({ starts_on: from, ends_on: to }).map((date) => ({ date, start, end }));
const home = (id, details = {}, extra = {}) => ({
  id, address: `${id} Evergreen Ct`, zone: 'north', lat: 29.2, lon: -81.68, start: '18:00', end: '22:00', note: '', status: 'active', status_date: null,
  details: { styles: ['classic'], flashing_warning: false, music_enabled: false, fm_frequency: null, viewing_mode: 'drive_by_only', treats: false, cocoa: false, ...details },
  windows: nights('2026-12-01', '2027-01-03'), ...extra,
});

test('the registry offers Christmas lights; its rules are the shared fixture\'s', () => {
  assert.equal(eventType('holiday_lights'), lights);
  assert.deepEqual(JSON.parse(JSON.stringify(lights.displayRules)), fixture.rules.holiday_lights);
  assert.deepEqual(lights.filters.map((f) => f.key), ['driveBy', 'noFlashing', 'music', 'walkUp', 'openTonight'], 'HolidayLightsType::filters() order');
  assert.deepEqual(lights.filters.filter((f) => f.quick).map((f) => f.key), ['driveBy', 'noFlashing', 'music']);
  assert.equal(lights.migratesYearStore, false, 'only Halloween takes over a 0.10 year store');
  assert.deepEqual(lights.hours, { start: [15, 22], end: [16, 23], visit: [16, 23] });
});

test('every shared holiday_lights display-status case through the module\'s clock', () => {
  const cases = fixture.cases.filter((c) => c.type === 'holiday_lights');
  assert.ok(cases.length >= 18);
  for (const c of cases) {
    const n = nowInZone(c.timezone, new Date(c.now));
    assert.equal(lights.displayKey({ status: c.status, status_date: c.status_date, windows: c.windows }, { today: n.date, nowMinutes: n.minutes }), c.expected, c.name);
  }
});

test('never "Lights on" outside a window; New Year\'s Eve into Jan 1; off tonight and done for the season', () => {
  const h = home(1, {}, { windows: [{ date: '2026-12-31', start: '18:00', end: '23:30' }, { date: '2027-01-01', start: '18:00', end: '22:00' }] });
  const seen = new Set();
  for (const [day, from, to] of [['2026-12-31', 0, 24 * 60], ['2027-01-01', 0, 24 * 60], ['2027-01-02', 0, 24 * 60]]) {
    for (let m = from; m < to; m += 5) {
      const hhmm = `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
      const w = h.windows.find((x) => x.date === day);
      const inside = Boolean(w && hhmm >= w.start && hhmm < w.end);
      const st = lights.statusOf(h, ctxAt(day, hhmm));
      seen.add(st.key);
      assert.equal(st.key === 'lights_on', inside, `${day} ${hhmm}: ${st.key}`);
    }
  }
  assert.deepEqual([...seen].sort(), ['finished', 'later_tonight', 'lights_on', 'not_tonight'].sort(), 'after its last night a home says "No more nights"');
  assert.equal(lights.statusOf(h, ctxAt('2027-01-02', '19:00')).label, 'No more nights');
  const off = home(2, {}, { status: 'off_tonight', status_date: '2026-12-12' });
  assert.deepEqual(lights.statusOf(off, ctxAt('2026-12-12', '19:00')), { key: 'off_tonight', label: 'Off tonight' });
  assert.equal(lights.statusOf(off, ctxAt('2026-12-13', '19:00')).key, 'lights_on', 'off tonight holds one night');
  assert.equal(lights.hoursLabel(off, ctxAt('2026-12-12', '19:00')), 'Sun, Dec 13 · 6–10 PM', 'off tonight shows the next night');
  const done = home(3, {}, { status: 'finished' });
  assert.deepEqual(lights.statusOf(done, ctxAt('2026-12-12', '19:00')), { key: 'finished', label: 'Done for the season' });
  assert.equal(lights.isOpen(done, ctxAt('2026-12-12', '19:00')), false);
  assert.equal(lights.isOpen(h, ctxAt('2027-01-02', '19:00')), false, 'no nights left');
  assert.equal(lights.isOpen(off, ctxAt('2026-12-12', '19:00')), true, 'off tonight stays in the season');
});

test('filters: drive-by, no flashing reported, music, walk-up, open tonight, a night and a style', () => {
  const homes = [
    home(1, { viewing_mode: 'drive_by_only', styles: ['classic'] }),
    home(2, { viewing_mode: 'walk_up_welcome', flashing_warning: true, styles: ['animated', 'inflatables'] }),
    home(3, { music_enabled: true, fm_frequency: '88.7', styles: ['synchronized_music'] }, { windows: nights('2026-12-20', '2027-01-02', '17:00', '21:00') }),
    home(4, { viewing_mode: 'walk_up_welcome', styles: ['walkthrough'] }, { status: 'off_tonight', status_date: '2026-12-31' }),
    home(5, { styles: ['classic'] }, { status: 'finished' }),
  ];
  const T = lights;
  const base = defaultFilters(T);
  assert.deepEqual(Object.keys(base), ['q', 'zone', 'time', 'driveBy', 'noFlashing', 'music', 'walkUp', 'openTonight', 'saved', 'night', 'style']);
  const ids = (f, ctx = ctxAt('2026-12-31', '19:00')) => homes.filter((h) => matches(h, { ...base, ...f }, ctx)).map((h) => h.id);
  assert.deepEqual(ids({ driveBy: true }), [1, 3, 5]);
  assert.deepEqual(ids({ walkUp: true }), [2, 4]);
  assert.deepEqual(ids({ noFlashing: true }), [1, 3, 4, 5], 'excludes reports of flashing');
  assert.deepEqual(ids({ music: true }), [3]);
  assert.deepEqual(ids({ openTonight: true }), [1, 2, 3], '19:00 on Dec 31: not the home off tonight, not the finished one');
  assert.deepEqual(ids({ openTonight: true }, ctxAt('2026-12-31', '22:30')), [], 'all windows over for the night');
  assert.deepEqual(ids({ openTonight: true }, ctxAt('2026-12-31', '16:00')), [1, 2, 3], 'later tonight counts');
  assert.deepEqual(ids({ night: '2026-12-31' }), [1, 2, 3], 'New Year\'s Eve: not off-tonight home 4, not finished home 5');
  assert.deepEqual(ids({ night: '2027-01-01' }), [1, 2, 3, 4], 'Jan 1: home 4 is back');
  assert.deepEqual(ids({ night: '2027-01-03' }), [1, 2, 4], 'home 3\'s nights end on Jan 2');
  assert.deepEqual(ids({ night: '2026-12-05' }), [1, 2, 4]);
  assert.deepEqual(ids({ style: 'inflatables' }), [2]);
  assert.deepEqual(ids({ style: 'walkthrough', walkUp: true }), [4]);
  assert.deepEqual(ids({ q: 'cocoa' }), []);
  assert.deepEqual(ids({ q: '88.7' }), [3], 'the FM station is searchable');
  assert.deepEqual(ids({ q: 'walk-up' }), [2, 4]);
});

test('filter choices from a shared link are checked; active filters count them', () => {
  assert.deepEqual(normalizeFilters({ night: '2026-12-31', style: 'inflatables', driveBy: true }, lights), { night: '2026-12-31', style: 'inflatables', driveBy: true });
  assert.deepEqual(normalizeFilters({ night: '<img src=x>', style: 'lasers', candy: true, time: 'now' }, lights), { time: 'now' }, 'unknown choices and other types\' toggles are dropped');
  assert.equal(activeFilterCount({ ...defaultFilters(lights) }, lights), 0);
  assert.equal(activeFilterCount({ ...defaultFilters(lights), night: '2026-12-31', style: 'classic', music: true }, lights), 3);
});

test('tags carry words, never only colour, and never promise safety; pins follow music, walk-through, inflatables', () => {
  const t = lights.tags(home(1, { styles: ['classic', 'inflatables'], music_enabled: true, fm_frequency: '88.7', cocoa: true, treats: true }));
  assert.deepEqual(t.map((x) => x.text), ['Classic', 'Inflatables', 'Drive-by', 'No flashing reported', 'Music 88.7 FM', 'Treats', 'Cocoa']);
  assert.deepEqual(lights.tags(home(2, { viewing_mode: 'walk_up_welcome', flashing_warning: true })).map((x) => x.text), ['Classic', 'Walk-up', 'Flashing lights']);
  for (const x of t) assert.doesNotMatch(x.text, /\bsafe\b/i);
  for (const w of Object.values(lights.copy)) assert.doesNotMatch(w, /\bsafe\b/i);
  assert.deepEqual([lights.symbol(home(1, { styles: ['classic'], music_enabled: true })), lights.pinClass(home(1, { styles: ['synchronized_music'] }))], ['🎵', 'music']);
  assert.deepEqual([lights.symbol(home(1, { styles: ['inflatables', 'walkthrough'] })), lights.pinClass(home(1, { styles: ['inflatables'] }))], ['🎄', 'inflatable']);
  assert.equal(lights.symbol(home(1, { styles: ['animated'] })), '✨');
});

test('hours on the card: tonight, the next night or the last; one suffix when both times share it', () => {
  assert.equal(lights.span('18:00', '22:00'), '6–10 PM');
  assert.equal(lights.span('18:30', '21:00'), '6:30–9 PM');
  assert.equal(lights.span('11:00', '13:00'), '11 AM–1 PM');
  assert.equal(lights.dayLabel('2027-01-01'), 'Fri, Jan 1');
  const h = home(1, {}, { windows: nights('2026-12-20', '2026-12-22', '17:30', '21:00') });
  assert.equal(lights.hoursLabel(h, ctxAt('2026-12-21', '12:00')), 'Tonight 5:30–9 PM');
  assert.equal(lights.hoursLabel(h, ctxAt('2026-12-01', '12:00')), 'Sun, Dec 20 · 5:30–9 PM', 'season preview: the first night');
  assert.equal(lights.hoursLabel(h, ctxAt('2026-12-30', '12:00')), 'Last night Tue, Dec 22');
});

test('cards, the selected home and pins: the status chip once the season is on, resident text escaped', () => {
  const evil = home(9, {}, { note: '<img src=x onerror=alert(1)>', address: '9 <b>Evergreen</b> Ct' });
  const before = ctxAt('2026-11-20', '19:00');
  const during = ctxAt('2026-12-05', '23:00');
  const card = cardHtml(evil, during, { saved: false, active: false, focus: {} });
  assert.doesNotMatch(card, /<img src=x/);
  assert.match(card, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.match(card, /9 &lt;b&gt;Evergreen&lt;\/b&gt; Ct/);
  assert.match(card, /card-status not_tonight">Not tonight</, 'during the season a dark home says so');
  assert.doesNotMatch(cardHtml(evil, before, { saved: false, active: false, focus: {} }), /card-status/, 'before the season no chip repeats "not tonight"');
  assert.match(cardHtml(evil, ctxAt('2026-12-05', '19:00'), { saved: true, active: false, focus: {} }), /card-status lights_on">Lights on</);
  assert.match(selectedHtml(evil, during, false), /♡ Save this stop/);
  assert.match(pinHtml(evil, { focus: {}, type: lights, ctx: during }), /class="pin lights inactive"/);
  assert.match(pinHtml(evil, { focus: {}, type: lights, ctx: ctxAt('2026-12-05', '19:00') }), /class="pin lights"/);
  assert.doesNotMatch(tagsHtml(home(1, { fm_frequency: '<script>' , music_enabled: true }), lights), /<script>/);
});

test('the season and its nights across the year end (62 nights at most)', () => {
  assert.equal(seasonOf(EVENT, '2026-11-30'), 'before');
  assert.equal(seasonOf(EVENT, '2026-12-01'), 'during');
  assert.equal(seasonOf(EVENT, '2027-01-03'), 'during');
  assert.equal(seasonOf(EVENT, '2027-01-04'), 'after');
  const dates = eventDates(EVENT);
  assert.equal(dates.length, 34);
  assert.deepEqual(dates.slice(29, 32), ['2026-12-30', '2026-12-31', '2027-01-01']);
  assert.equal(eventDates({ starts_on: '2026-11-15', ends_on: '2027-01-15' }).length, 62);
});

/** A tiny stand-in for a form: named inputs with value/checked and the selectors the module uses. */
function fakeForm(inputs) {
  const all = inputs.map((i) => ({ checked: false, value: '', ...i }));
  const match = (sel) => {
    const m = sel.match(/^input\[name="([^"]+)"\](:checked)?$/);
    return m ? all.filter((i) => i.name === m[1] && (!m[2] || i.checked)) : [];
  };
  const form = { querySelector: (sel) => match(sel)[0] || null, querySelectorAll: (sel) => match(sel) };
  for (const i of all) if (!['styles', 'viewing_mode', 'nights_mode', 'night'].includes(i.name)) form[i.name] = i;
  return form;
}

test('the listing form: details, every night or the nights picked, and checks before sending', () => {
  const form = fakeForm([
    { name: 'styles', value: 'classic', checked: true }, { name: 'styles', value: 'inflatables', checked: true }, { name: 'styles', value: 'animated' },
    { name: 'viewing_mode', value: 'drive_by_only' }, { name: 'viewing_mode', value: 'walk_up_welcome', checked: true },
    { name: 'music_enabled', checked: true }, { name: 'flashing_warning' }, { name: 'treats' }, { name: 'cocoa', checked: true },
    { name: 'fm_frequency', value: ' 88.7 ' }, { name: 'nights_mode', value: 'all', checked: true }, { name: 'nights_mode', value: 'pick' },
    { name: 'night', value: '2026-12-31', checked: true }, { name: 'night', value: '2027-01-01', checked: true }, { name: 'night', value: '2027-01-02' },
  ]);
  assert.deepEqual(lights.formDetails(form), { styles: ['classic', 'inflatables'], flashing_warning: false, music_enabled: true, treats: false, cocoa: true, fm_frequency: '88.7', viewing_mode: 'walk_up_welcome' });
  assert.equal(lights.formExtras(form, { start: '18:00', end: '22:00', event: EVENT }).windows.length, 34, 'every night by default');
  form.querySelectorAll('input[name="nights_mode"]').forEach((r) => { r.checked = r.value === 'pick'; });
  assert.deepEqual(lights.formExtras(form, { start: '17:00', end: '21:30', event: EVENT }).windows,
    [{ date: '2026-12-31', start: '17:00', end: '21:30' }, { date: '2027-01-01', start: '17:00', end: '21:30' }]);
  assert.deepEqual(lights.validateDetails({ styles: [], fm_frequency: null }), { styles: 'Choose at least one display style.' });
  assert.deepEqual(lights.validateDetails({ styles: ['classic'], music_enabled: true, fm_frequency: '108.1' }), { fm_frequency: 'Use an FM station like 88.7 (87.5 to 108.0).' });
  assert.deepEqual(lights.validateDetails({ styles: ['classic'], music_enabled: true, fm_frequency: '87.4' }), { fm_frequency: 'Use an FM station like 88.7 (87.5 to 108.0).' });
  assert.deepEqual(lights.validateDetails({ styles: ['classic'], music_enabled: false, fm_frequency: '88.7' }), { fm_frequency: 'Turn on music to add its radio station, or leave the station empty.' });
  assert.deepEqual(lights.validateDetails({ styles: ['classic'], music_enabled: true, fm_frequency: '87.5' }), {});
});

test('the lights theme: both dark blocks identical, every light token also set in dark, organizer colours never set', () => {
  const css = readFileSync(new URL('../../public/assets/css/events/holiday_lights.css', import.meta.url), 'utf8');
  const light = css.match(/\n:root\[data-event-type="holiday_lights"\] \{\n([\s\S]*?)\n\}/);
  const system = css.match(/@media \(prefers-color-scheme: dark\) \{\n {2}:root\[data-event-type="holiday_lights"\]:not\(\[data-theme="light"\]\) \{\n([\s\S]*?)\n {2}\}\n\}/);
  const chosen = css.match(/\n:root\[data-event-type="holiday_lights"\]\[data-theme="dark"\] \{\n([\s\S]*?)\n\}/);
  assert.ok(light && system && chosen, 'the three token blocks exist');
  const norm = (s) => s.split('\n').map((l) => l.trim()).filter(Boolean).join('\n');
  assert.equal(norm(chosen[1]), norm(system[1]));
  const names = (s) => [...s.matchAll(/(--[a-z0-9-]+):/g)].map((m) => m[1]).sort();
  assert.deepEqual(names(system[1]), names(light[1]), 'a light value never leaks into dark mode');
  assert.doesNotMatch(css, /--brand(-accent|-ink|-text)?\s*:/, '/theme.css (the organizer\'s colours) keeps winning');
  assert.doesNotMatch(css, /animation|@keyframes/, 'nothing blinks or moves');
});

test('a shared lights link keeps its night and style, drops what is not a lights filter, and never another event\'s stops', async () => {
  const { readSharedLink } = await import('../../public/assets/js/lib/share.js');
  const f = Buffer.from(JSON.stringify({ night: '2026-12-31', style: 'walkthrough', driveBy: true, candy: true, style2: 'x' })).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const clean = (x) => normalizeFilters(x, lights);
  assert.deepEqual(readSharedLink(`?e=7&f=${f}&stops=3.5&start=5&o=1`, 7, clean), { filters: { night: '2026-12-31', style: 'walkthrough', driveBy: true }, stops: [3, 5], start: 5, order: true });
  assert.deepEqual(readSharedLink(`?e=6&f=${f}&stops=3.5`, 7, clean), { otherEvent: true });
  assert.deepEqual(readSharedLink('?f=%%%&stops=x.0.-2', 7, clean), {}, 'a damaged link opens the plain map');
});
