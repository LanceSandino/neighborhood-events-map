/**
 * @module assets/js/admin/events
 * @description Organizer → Events: the current event and every earlier or prepared one with how many homes opted in
 *     and how many the map shows; prepare a draft (types this version offers), edit, preview a draft, switch the map
 *     to a draft (the current event is archived; nothing carries over), end the current event, export one event's
 *     listings (CSRF-checked form POST) and delete an archived event's listings. The server checks everything.
 * @dependencies admin/common.js, lib/api.js
 */
import { $, call, esc, flash, fmtDate } from './common.js';
import { csrfToken } from '../lib/api.js';

let data = null;
let editing = null; // the event whose form is open (null: a new draft)

const STATE = { draft: 'Draft', active: 'On the map now', archived: 'Archived' };
const typeOf = (key) => data.types.find((t) => t.key === key);
const oneDay = (key) => (typeOf(key) || {}).schedule_kind === 'single_day';
const dates = (e) => (e.starts_on === e.ends_on ? e.starts_on : `${e.starts_on} – ${e.ends_on}`);

function form(e) {
  const type = e ? e.type : (data.types[0] || {}).key;
  const rules = ((e && e.settings && e.settings.rules) || []).join('\n');
  return `<form id="eventForm" class="form-grid" novalidate>
    <div class="field"><label for="evType">Event type</label><select id="evType" ${e ? 'disabled' : ''}>${(e ? [{ key: e.type, name: e.type_name }] : data.types).map((t) => `<option value="${esc(t.key)}" ${t.key === type ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
      <span class="form-caption">${e ? 'The type of an event never changes.' : 'This version offers ' + esc(data.types.map((t) => t.name).join(', ')) + '.'}</span></div>
    <div class="field"><label for="evName">Public name</label><input id="evName" maxlength="60" value="${esc(e ? e.name : '')}" placeholder="${esc((typeOf(type) || {}).name || '')}"></div>
    <div class="field"><label for="evStart">${oneDay(type) ? 'Date *' : 'First day *'}</label><input id="evStart" type="date" value="${esc(e ? e.starts_on : '')}" required></div>
    <div class="field ${oneDay(type) ? 'hide' : ''}" id="evEndField"><label for="evEnd">Last day</label><input id="evEnd" type="date" value="${esc(e ? e.ends_on : '')}"></div>
    <div class="field"><label for="evFrom">Most homes from</label><input id="evFrom" type="time" value="${esc(e ? e.default_start : '17:00')}"></div>
    <div class="field"><label for="evTo">until</label><input id="evTo" type="time" value="${esc(e ? e.default_end : '21:00')}"></div>
    <div class="field full"><label for="evRules">Event rules (one per line, up to 8)</label><textarea id="evRules" maxlength="1400">${esc(rules)}</textarea>
      <span class="form-caption">Plain text, for example parking or quiet hours. Shown with the event.</span></div>
    <div class="field full"><div class="modal-actions"><button type="button" class="secondary" id="evCancel">Cancel</button><button type="submit" class="primary">${e ? 'Save event' : 'Save draft'}</button></div></div>
  </form>`;
}

function row(e) {
  const actions = [];
  if (e.state === 'draft' && e.type_offered) actions.push(`<a class="secondary" href="/admin/events/${e.id}/preview">Preview</a>`, `<button type="button" class="primary" data-activate="${e.id}">Put on the map</button>`);
  if (e.state !== 'archived' && e.type_offered) actions.push(`<button type="button" class="secondary" data-edit="${e.id}">Edit</button>`);
  if (e.state !== 'archived') actions.push(`<button type="button" class="secondary" data-archive="${e.id}">${e.state === 'active' ? 'End event' : 'Archive'}</button>`);
  actions.push(`<form class="inline-form" method="post" action="/api/admin/events/${e.id}/export"><input type="hidden" name="_csrf" value="${esc(csrfToken())}"><button type="submit" class="secondary">Export CSV</button></form>`);
  if (e.state === 'archived' && e.opted_in > 0) actions.push(`<button type="button" class="danger" data-purge="${e.id}">Delete listings</button>`);
  return `<tr data-event="${e.id}"><td><b>${esc(e.name)}</b><br><span class="form-caption">${esc(e.type_name)}${e.type_offered ? '' : ' · not offered by this version'}</span></td>
    <td class="nowrap">${esc(dates(e))}<br><span class="form-caption">${esc(e.default_start)}–${esc(e.default_end)}</span></td>
    <td>${esc(STATE[e.state] || e.state)}${e.activated_at && e.state !== 'draft' ? `<br><span class="form-caption">since ${esc(fmtDate(e.activated_at))}</span>` : ''}</td>
    <td>${e.opted_in}</td><td>${e.state === 'active' ? e.public : '—'}</td><td><div class="row-actions">${actions.join('')}</div></td></tr>`;
}

function render() {
  const active = data.events.find((e) => e.state === 'active');
  $('#admin-events').innerHTML = `
    <div class="panel"><h2>On the map now</h2>${active
      ? `<p><b>${esc(active.name)}</b> · ${esc(dates(active))} · ${active.public} of ${active.opted_in} listed homes on the public map.</p>`
      : '<p class="notice">No event is on the map. Visitors see “No event on the map right now” and nobody can list a home until you put an event on the map.</p>'}
      <p class="form-caption">One event is on the map at a time. Claims stay with their residents; listings belong to one event, so a new event starts with no homes and residents list theirs again (nothing is copied from an earlier event).</p></div>
    <div class="panel"><h2>${editing ? 'Edit event' : 'Prepare the next event'}</h2><div id="eventFormHost">${editing !== undefined && editing !== false ? form(editing) : '<button type="button" class="primary" id="evNew">+ Prepare an event</button>'}</div></div>
    <div class="panel"><h2>All events</h2><div class="table-wrap"><table class="data events-table"><thead><tr><th>Event</th><th>Dates</th><th>State</th><th>Homes opted in</th><th>On the map</th><th></th></tr></thead>
      <tbody>${data.events.map(row).join('') || '<tr><td colspan="6">No events yet.</td></tr>'}</tbody></table></div></div>`;
  bind();
}

function readForm() {
  const type = $('#evType').value;
  const body = { name: $('#evName').value, starts_on: $('#evStart').value, ends_on: oneDay(type) ? $('#evStart').value : ($('#evEnd').value || $('#evStart').value),
    default_start: $('#evFrom').value, default_end: $('#evTo').value,
    settings: { rules: $('#evRules').value.split('\n').map((l) => l.trim()).filter(Boolean) } };
  if (!editing) body.type = type;
  return body;
}

function bind() {
  $('#evNew')?.addEventListener('click', () => { editing = null; $('#eventFormHost').innerHTML = form(null); bindForm(); });
  bindForm();
  document.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
    editing = data.events.find((e) => e.id === Number(b.dataset.edit));
    render();
    $('#evName').focus();
  }));
  document.querySelectorAll('[data-activate]').forEach((b) => b.addEventListener('click', async () => {
    const target = data.events.find((e) => e.id === Number(b.dataset.activate));
    const current = data.events.find((e) => e.state === 'active');
    const msg = `Put “${target.name}” (${dates(target)}) on the map?` + (current
      ? `\n\n“${current.name}” ends and is archived: its ${current.opted_in} listed homes leave the map. Nothing carries over; residents list their homes again for the new event.`
      : '');
    if (!window.confirm(msg)) return;
    await call(`/api/admin/events/${target.id}/activate`, { method: 'POST', body: { confirm: true, replaces: current ? current.id : null } });
    flash(`“${target.name}” is on the map now.`);
    load();
  }));
  document.querySelectorAll('[data-archive]').forEach((b) => b.addEventListener('click', async () => {
    const e = data.events.find((x) => x.id === Number(b.dataset.archive));
    if (!window.confirm(e.state === 'active' ? `End “${e.name}”? Its homes leave the map now and the map says there is no event until you put the next one on it.` : `Archive the draft “${e.name}”?`)) return;
    await call(`/api/admin/events/${e.id}/archive`, { method: 'POST', body: { confirm: true } });
    flash(`“${e.name}” is archived.`);
    load();
  }));
  document.querySelectorAll('[data-purge]').forEach((b) => b.addEventListener('click', async () => {
    const e = data.events.find((x) => x.id === Number(b.dataset.purge));
    if (window.prompt(`Type DELETE to remove the ${e.opted_in} listings of “${e.name}” for good (claims stay). Export them first if you need a copy.`) !== 'DELETE') return;
    const r = await call(`/api/admin/events/${e.id}/purge-listings`, { method: 'POST', body: { confirm: 'DELETE' } });
    flash(`Deleted ${r.purged} listing(s) of “${e.name}”.`);
    load();
  }));
}

function bindForm() {
  const f = $('#eventForm');
  if (!f) return;
  $('#evType').addEventListener('change', () => $('#evEndField').classList.toggle('hide', oneDay($('#evType').value)));
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
      flash('Draft saved. Preview it, then put it on the map when you are ready.');
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
