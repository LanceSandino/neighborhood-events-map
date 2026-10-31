/**
 * @module assets/js/admin/events
 * @description Organizer → Events (ADR 0011): every event with its state, how many homes opted in and how many the map
 *     shows; prepare a draft (types this version offers, starting on the type's usual dates: Halloween October 31,
 *     Christmas lights November 1 to January 1, a sale's dates to be announced), edit (name, dates or "to be
 *     announced", tentative, hours, rules, the thank-you message, the type's own settings), preview a draft, announce
 *     it (upcoming), open, close (the confirmation shows the thank-you text and next year's dates it creates), make an
 *     event the default (what "/" shows), archive, export one event's listings (CSRF-checked form POST) and delete an
 *     archived event's listings. Several events may be on at once, one of each kind. The server checks everything.
 * @dependencies admin/common.js, lib/api.js
 */
import { $, call, esc, flash, fmtDate } from './common.js';
import { csrfToken } from '../lib/api.js';

let data = null;
let editing = null; // the event whose form is open (null: a new draft)

const STATE = { draft: 'Draft', upcoming: 'Upcoming', open: 'Open — on the map', closed: 'Closed', archived: 'Archived' };
const typeOf = (key) => data.types.find((t) => t.key === key);
const oneDay = (key) => (typeOf(key) || {}).schedule_kind === 'single_day';
const drives = (key) => ((typeOf(key) || {}).route_profiles || []).includes('driving');
const hasSetting = (key, name) => ((typeOf(key) || {}).settings || []).includes(name);
const options = (key, name) => Object.entries(((typeOf(key) || {}).setting_options || {})[name] || {});
const dates = (e) => (!e.starts_on ? 'Dates to be announced' : (e.starts_on === e.ends_on ? e.starts_on : `${e.starts_on} – ${e.ends_on}`) + (e.dates_tentative ? ' (tentative)' : ''));

function form(e) {
  const type = e ? e.type : (data.types[0] || {}).key;
  const rules = ((e && e.settings && e.settings.rules) || []).join('\n');
  const [first, last] = e ? [e.starts_on, e.ends_on] : ((typeOf(type) || {}).new_dates || [null, null]);
  const tbd = !first;
  return `<form id="eventForm" class="form-grid" novalidate>
    <div class="field"><label for="evType">Event type</label><select id="evType" ${e ? 'disabled' : ''}>${(e ? [{ key: e.type, name: e.type_name }] : data.types).map((t) => `<option value="${esc(t.key)}" ${t.key === type ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
      <span class="form-caption">${e ? 'The type of an event never changes.' : 'This version offers ' + esc(data.types.map((t) => t.name).join(', ')) + '.'}</span></div>
    <div class="field"><label for="evName">Public name</label><input id="evName" maxlength="60" value="${esc(e ? e.name : '')}" placeholder="${esc((typeOf(type) || {}).name || '')}"></div>
    <div class="field full"><label class="check-chip"><input type="checkbox" id="evTbd" ${tbd ? 'checked' : ''}> Dates to be announced</label>
      <label class="check-chip"><input type="checkbox" id="evTentative" ${e && e.dates_tentative ? 'checked' : ''}> The dates are tentative</label>
      <span class="form-caption">Visitors see “dates to be announced” or “tentative”. Opening an event confirms its dates; residents can list once it has dates.</span></div>
    <div class="field ${tbd ? 'hide' : ''}" id="evStartField"><label for="evStart">${oneDay(type) ? 'Date *' : 'First day *'}</label><input id="evStart" type="date" value="${esc(first || '')}"></div>
    <div class="field ${oneDay(type) || tbd ? 'hide' : ''}" id="evEndField"><label for="evEnd">Last day</label><input id="evEnd" type="date" value="${esc(last || '')}"></div>
    <div class="field"><label for="evFrom">Most homes from</label><input id="evFrom" type="time" value="${esc(e ? e.default_start : '17:00')}"></div>
    <div class="field"><label for="evTo">until</label><input id="evTo" type="time" value="${esc(e ? e.default_end : '21:00')}"></div>
    <div class="field full"><label for="evRules">Event rules (one per line, up to 8)</label><textarea id="evRules" maxlength="1400">${esc(rules)}</textarea>
      <span class="form-caption">Plain text, for example parking or quiet hours. Shown with the event.</span></div>
    <div class="field full"><label for="evClosing">Thank-you message when it closes (optional, up to 400 characters)</label><textarea id="evClosing" maxlength="400" placeholder="${esc(closingPlaceholder(type))}">${esc((e && e.settings && e.settings.closing_message) || '')}</textarea>
      <span class="form-caption">Leave it empty for the default, which names next year's dates. Shown instead of homes once the event is closed.</span></div>
    <div class="field full">${typeFields(type, e)}</div>
    <div class="field full"><div class="modal-actions"><button type="button" class="secondary" id="evCancel">Cancel</button><button type="submit" class="primary">${e ? 'Save event' : 'Save draft'}</button></div></div>
  </form>`;
}

const closingPlaceholder = (type) => ((typeOf(type) || {}).closing_template || '').replace('{event}', 'This year’s event').replace('{next}', 'next year’s dates');

/** A sale's own settings: up to three sale days, the categories it takes and the early-bird time. */
function saleFields(type, s) {
  const days = s.days || [];
  const cats = s.categories || options(type, 'categories').map(([k]) => k);
  return [`<div class="field full type-field"><span class="label" id="evDaysLabel">Sale days (up to 3, inside the dates above)</span><div class="checks" role="group" aria-labelledby="evDaysLabel">${
    [0, 1, 2].map((i) => `<input id="evDay${i + 1}" class="sale-day-input" type="date" value="${esc(days[i] || '')}" aria-label="Sale day ${i + 1}">`).join('')}</div>
      <span class="form-caption">Leave them empty for a sale of up to three days: every day of it is a sale day. A sale opens once it has its days.</span></div>`,
  `<div class="field full type-field"><span class="label" id="evCatsLabel">Categories residents may list</span><div class="checks" role="group" aria-labelledby="evCatsLabel">${
    options(type, 'categories').map(([k, label]) => `<label class="check-chip"><input type="checkbox" name="evCategory" value="${esc(k)}" ${cats.includes(k) ? 'checked' : ''}> ${esc(label)}</label>`).join('')}</div></div>`,
  `<div class="field type-field"><label for="evEarly">Early birds not before (optional)</label><input id="evEarly" type="time" value="${esc(s.early_bird_time || '')}"></div>`];
}

/** Settings only some types have: the best night and quiet hours (lights), a sale's days, categories and early birds, the drive-by tour (types that allow driving). */
function typeFields(type, e) {
  const s = (e && e.settings) || {};
  const out = hasSetting(type, 'days') ? saleFields(type, s) : [];
  if (hasSetting(type, 'best_night')) out.push(`<div class="field type-field"><label for="evBest">Best night for a neighborhood walk (optional)</label><input id="evBest" type="date" value="${esc(s.best_night || '')}"></div>`);
  if (hasSetting(type, 'quiet_hours')) out.push(`<div class="field type-field"><label for="evQuiet">Quiet hours (optional)</label><input id="evQuiet" maxlength="120" value="${esc(s.quiet_hours || '')}" placeholder="e.g. Please keep it quiet after 10 PM"></div>`);
  if (drives(type)) out.push(`<div class="field full type-field"><label class="check-chip"><input type="checkbox" id="evDriving" ${(s.route_modes || []).includes('driving') ? 'checked' : ''}> ${hasSetting(type, 'days') ? 'Offer a driving tour' : 'Offer a drive-by tour'}</label><span class="form-caption">Driving directions appear only when a routing provider is configured; otherwise visitors get an ordered list of stops.</span></div>`);
  return `<div class="form-grid" id="evTypeFields">${out.join('')}</div>`;
}

function row(e) {
  const a = [];
  const offered = e.type_offered;
  if (e.state === 'draft' && offered) a.push(`<a class="secondary" href="/admin/events/${e.id}/preview">Preview</a>`, `<button type="button" class="secondary" data-announce="${e.id}">Announce</button>`);
  if (['draft', 'upcoming'].includes(e.state) && offered) a.push(`<button type="button" class="primary" data-open="${e.id}">Open</button>`);
  if (e.state === 'open' && offered) a.push(`<button type="button" class="primary" data-close="${e.id}">Close</button>`);
  if (['upcoming', 'open', 'closed'].includes(e.state) && !e.is_default && offered) a.push(`<button type="button" class="secondary" data-default="${e.id}">Make default</button>`);
  if (e.state !== 'archived' && offered) a.push(`<button type="button" class="secondary" data-edit="${e.id}">Edit</button>`);
  if (e.state !== 'archived') a.push(`<button type="button" class="secondary" data-archive="${e.id}">Archive</button>`);
  a.push(`<form class="inline-form" method="post" action="/api/admin/events/${e.id}/export"><input type="hidden" name="_csrf" value="${esc(csrfToken())}"><button type="submit" class="secondary">Export CSV</button></form>`);
  if (e.state === 'archived' && e.opted_in > 0) a.push(`<button type="button" class="danger" data-purge="${e.id}">Delete listings</button>`);
  return `<tr data-event="${e.id}"><td><b>${esc(e.name)}</b>${e.is_default ? ' <span class="badge">Default</span>' : ''}<br><span class="form-caption">${esc(e.type_name)}${offered ? '' : ' · not offered by this version'}</span></td>
    <td class="nowrap">${esc(dates(e))}<br><span class="form-caption">${esc(e.default_start)}–${esc(e.default_end)}</span></td>
    <td>${esc(STATE[e.state] || e.state)}${e.state === 'open' && e.activated_at ? `<br><span class="form-caption">since ${esc(fmtDate(e.activated_at))}</span>` : ''}${e.state === 'closed' && e.closed_at ? `<br><span class="form-caption">since ${esc(fmtDate(e.closed_at))}</span>` : ''}</td>
    <td>${e.opted_in}</td><td>${e.state === 'open' ? e.public : '—'}</td><td><div class="row-actions">${a.join('')}</div></td></tr>`;
}

function render() {
  const shown = data.events.filter((e) => ['upcoming', 'open', 'closed'].includes(e.state));
  $('#admin-events').innerHTML = `
    <div class="panel"><h2>On the public site</h2>${shown.length
      ? `<ul class="event-summary">${shown.map((e) => `<li><b>${esc(e.name)}</b>${e.is_default ? ' <span class="badge">Default</span>' : ''} · ${esc(STATE[e.state])} · ${esc(dates(e))}${e.state === 'open' ? ` · ${e.public} of ${e.opted_in} listed homes on the map` : (e.opted_in ? ` · ${e.opted_in} homes listed` : '')}</li>`).join('')}</ul>`
      : '<p class="notice">No event is public. Visitors see “No event on the map right now” and nobody can list a home until you announce or open an event.</p>'}
      <p class="form-caption">Several events can be on at once, one of each kind; visitors switch between them and “/” shows the default. Closing an event shows its thank-you message and creates next year's event of the same kind (upcoming, tentative dates). Claims stay with their residents; listings belong to one event, and nothing is copied from an earlier event unless a resident chooses “Use last year's details”.</p></div>
    <div class="panel"><h2>${editing ? 'Edit event' : 'Prepare an event'}</h2><div id="eventFormHost">${editing !== undefined && editing !== false ? form(editing) : '<button type="button" class="primary" id="evNew">+ Prepare an event</button>'}</div></div>
    <div class="panel"><h2>All events</h2><div class="table-wrap"><table class="data events-table"><thead><tr><th>Event</th><th>Dates</th><th>State</th><th>Homes opted in</th><th>On the map</th><th></th></tr></thead>
      <tbody>${data.events.map(row).join('') || '<tr><td colspan="6">No events yet.</td></tr>'}</tbody></table></div></div>`;
  bind();
}

function readForm() {
  const type = $('#evType').value;
  const tbd = $('#evTbd').checked;
  const body = { name: $('#evName').value,
    starts_on: tbd ? null : $('#evStart').value, ends_on: tbd ? null : (oneDay(type) ? $('#evStart').value : ($('#evEnd').value || $('#evStart').value)),
    dates_tentative: !tbd && $('#evTentative').checked, default_start: $('#evFrom').value, default_end: $('#evTo').value,
    settings: { rules: $('#evRules').value.split('\n').map((l) => l.trim()).filter(Boolean), closing_message: $('#evClosing').value.trim() } };
  if ($('#evBest')) body.settings.best_night = tbd ? null : ($('#evBest').value || null);
  if ($('#evQuiet')) body.settings.quiet_hours = $('#evQuiet').value.trim();
  if ($('#evDriving')) body.settings.route_modes = $('#evDriving').checked ? ['walking', 'driving'] : ['walking'];
  if ($('#evDay1')) {
    const days = ['#evDay1', '#evDay2', '#evDay3'].map((id) => $(id).value).filter(Boolean);
    if (days.length && !tbd) body.settings.days = days;
    body.settings.categories = [...document.querySelectorAll('input[name="evCategory"]:checked')].map((i) => i.value);
    body.settings.early_bird_time = $('#evEarly').value || null;
  }
  if (!editing) body.type = type;
  return body;
}

const byId = (id) => data.events.find((e) => e.id === Number(id));

/** One button kind per life-cycle step: confirm, POST, say what happened, reload. */
function step(attr, confirmText, url, done, body = () => ({ confirm: true })) {
  document.querySelectorAll(`[data-${attr}]`).forEach((b) => b.addEventListener('click', async () => {
    const e = byId(b.dataset[attr]);
    const text = confirmText(e);
    if (text && !window.confirm(text)) return;
    await call(`/api/admin/events/${e.id}/${url}`, { method: 'POST', body: body(e) });
    flash(done(e));
    load();
  }));
}

function bind() {
  $('#evNew')?.addEventListener('click', () => { editing = null; $('#eventFormHost').innerHTML = form(null); bindForm(); });
  bindForm();
  document.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
    editing = byId(b.dataset.edit);
    render();
    $('#evName').focus();
  }));
  step('announce', (e) => `Announce “${e.name}” (${dates(e)})? Visitors see it with its dates and residents can list their homes${e.starts_on ? '' : ' once the dates are set'}. Its homes appear on the map when you open it.`,
    'announce', (e) => `“${e.name}” is announced.`);
  step('open', (e) => `Open “${e.name}” (${dates(e)})? Its listed homes appear on the map now${e.dates_tentative ? ' and its dates are confirmed' : ''}.`,
    'open', (e) => `“${e.name}” is open: its homes are on the map now.`);
  step('close', (e) => {
    const p = e.close_preview || { message: '', next: { name: e.name, words: 'dates to be announced' } };
    return `Close “${e.name}”? Its homes leave the map and visitors see this thank-you message:\n\n${p.message}\n\nNext year's event is created now: “${p.next.name}”, ${p.next.words}. Residents can list their homes for it.`;
  }, 'close', (e) => `“${e.name}” is closed. Next year's event is ready.`);
  step('default', () => null, 'default', (e) => `“${e.name}” is the default event now: “/” shows it.`, () => ({}));
  step('archive', (e) => `Archive “${e.name}”? It leaves the public site for good${e.is_default ? ' and another public event becomes the default' : ''}. Its listings are kept until you delete them.`,
    'archive', (e) => `“${e.name}” is archived.`);
  document.querySelectorAll('[data-purge]').forEach((b) => b.addEventListener('click', async () => {
    const e = byId(b.dataset.purge);
    if (window.prompt(`Type DELETE to remove the ${e.opted_in} listings of “${e.name}” for good (claims stay). Export them first if you need a copy.`) !== 'DELETE') return;
    const r = await call(`/api/admin/events/${e.id}/purge-listings`, { method: 'POST', body: { confirm: 'DELETE' } });
    flash(`Deleted ${r.purged} listing(s) of “${e.name}”.`);
    load();
  }));
}

function syncDates() {
  const type = $('#evType').value;
  const tbd = $('#evTbd').checked;
  $('#evStartField').classList.toggle('hide', tbd);
  $('#evEndField').classList.toggle('hide', tbd || oneDay(type));
  $('#evTentative').disabled = tbd;
  $('#evStart').previousElementSibling.textContent = oneDay(type) ? 'Date *' : 'First day *';
}

function bindForm() {
  const f = $('#eventForm');
  if (!f) return;
  $('#evType').addEventListener('change', () => {
    const type = $('#evType').value;
    const [first, last] = (typeOf(type) || {}).new_dates || [null, null];
    $('#evTbd').checked = !first;
    $('#evStart').value = first || '';
    $('#evEnd').value = last || '';
    $('#evClosing').placeholder = closingPlaceholder(type);
    $('#evTypeFields').outerHTML = typeFields(type, null);
    syncDates();
  });
  $('#evTbd').addEventListener('change', syncDates);
  syncDates();
  $('#evCancel').addEventListener('click', () => { editing = false; render(); });
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const body = readForm();
    if (editing) {
      try {
        await call(`/api/admin/events/${editing.id}`, { method: 'PUT', body });
      } catch (e) {
        if (e?.code !== 'listings_move' || !window.confirm(`${e.message}\n\nMove them to ${body.starts_on}?`)) return;
        await call(`/api/admin/events/${editing.id}`, { method: 'PUT', body: { ...body, move_listings: true } });
      }
      flash('Event saved.');
    } else {
      await call('/api/admin/events', { method: 'POST', body });
      flash('Draft saved. Preview it, then announce or open it when you are ready.');
    }
    editing = false;
    load();
  });
}

async function load() {
  data = await call('/api/admin/events');
  if (editing) editing = data.events.find((e) => e.id === editing.id) || false;
  render();
}
editing = false;
load();
