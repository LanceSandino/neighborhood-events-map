/**
 * @module assets/js/admin/community
 * @description Community, event, branding, features, zones, registration and routing settings
 *     (PUT /api/admin/community; the server validates everything) plus logo upload. The event type comes with the
 *     settings: the types this version offers, and the current type's feature switches and console labels.
 * @dependencies admin/common.js
 */
import { $, $$, call, esc, flash } from './common.js';

let settings = null;
let types = []; // event types an organizer may choose: [{ key, name }]
let type = null; // the current event type: { key, name, features: [{ key, label }], copy }

const field = (id, label, value, attrs = '') => `<div class="field"><label for="${id}">${label}</label><input id="${id}" value="${esc(value ?? '')}" ${attrs}></div>`;
const area = (id, label, value, help = '') => `<div class="field full"><label for="${id}">${label}</label><textarea id="${id}">${esc(value ?? '')}</textarea>${help ? `<span class="form-caption">${help}</span>` : ''}</div>`;
const check = (id, label, on) => `<label class="check-chip"><input type="checkbox" id="${id}" ${on ? 'checked' : ''}> ${label}</label>`;

const ZONE_STATUS = [['active', 'Active'], ['coming_soon', 'Coming soon'], ['hidden', 'Hidden']];

function zoneRow(z = { key: '', label: '', claimable: true, residential: true, color: '', description: '', status: 'active' }) {
  const status = z.status || 'active';
  return `<tr>
    <td><input data-z="key" value="${esc(z.key)}" pattern="[a-z0-9][a-z0-9-]*" aria-label="Zone key"></td>
    <td><input data-z="label" value="${esc(z.label)}" aria-label="Zone label"></td>
    <td><input type="checkbox" data-z="claimable" ${z.claimable ? 'checked' : ''} aria-label="Homes can be listed"></td>
    <td><input type="checkbox" data-z="residential" ${z.residential ? 'checked' : ''} aria-label="Residential"></td>
    <td><input data-z="color" value="${esc(z.color || '')}" placeholder="#RRGGBB" aria-label="Colour"></td>
    <td><select data-z="status" aria-label="Status">${ZONE_STATUS.map(([v, l]) => `<option value="${v}" ${v === status ? 'selected' : ''}>${l}</option>`).join('')}</select></td>
    <td>${z.label_at ? '<span class="form-caption">Moved on the map</span> <button type="button" class="link-button" data-reset-label>Reset</button>' : '<span class="form-caption">Automatic</span>'}</td>
    <td><button type="button" class="link-button" data-remove-zone>Remove</button></td></tr>`;
}

/** How visitors may reach the homes; the event type names the "gates open during the event" choice. */
const access = () => [
  ['unspecified', 'Not stated'],
  ['public', 'Open to the public (public streets)'],
  ['gated_open', type.copy.access_gated_open],
  ['gated_guests', 'Gated — residents and their invited guests only'],
  ['private', 'Private — residents only'],
];
/** Feature switches every event type has, after the type's own. */
const SHARED_FEATURES = [['optional_zones', 'Show zone filter', 'fZones'], ['walking_routes', 'Suggested stop order', 'fRoutes']];

function linkRow(l = { label: '', url: '' }) {
  return `<div class="link-row"><input data-l="label" value="${esc(l.label)}" maxlength="40" placeholder="e.g. Facebook group…" aria-label="Link label">
    <input data-l="url" value="${esc(l.url)}" placeholder="https://… or mailto:…" aria-label="Link address"><button type="button" class="link-button" data-remove-link>Remove</button></div>`;
}

function render() {
  const s = settings;
  $('#admin-community').innerHTML = `
  <form id="settingsForm" novalidate>
    <div class="panel"><h2>Community</h2><div class="form-grid">
      ${field('cName', 'Name *', s.community.name, 'maxlength="60" required')}
      ${field('cSubtitle', 'Subtitle', s.community.subtitle, 'maxlength="80"')}
      ${field('cLocality', 'City / locality', s.community.locality)}
      ${field('cRegion', 'State / region', s.community.region)}
      ${field('cPostal', 'Postal code', s.community.postal_code)}
      ${field('cTz', 'Time zone (IANA) *', s.community.timezone, 'list="tzList"')}
      <div class="field full"><label for="cAccess">Access during the event</label><select id="cAccess">${access().map(([k, l]) => `<option value="${k}" ${s.community.access === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
      ${area('cGate', 'Access notes (gates, entrances, parking)', s.community.gate_notice, 'Shown on the map and the About page. The site never grants gate access.')}
      ${field('cContact', 'Organizer contact (mailto: or https://) *', s.community.contact_url, 'placeholder="mailto:organizer@example.org"')}
    </div></div>
    <div class="panel"><h2>About page</h2><div class="form-grid">
      ${area('cAbout', 'About the neighborhood and the event', s.community.about, 'Shown on the public About page. Blank lines start a new paragraph. Plain text only.')}
      ${area('cReminders', esc(type.copy.reminders_label), (s.community.reminders || []).join('\n'), esc(type.copy.reminders_help))}
      <div class="field full"><span class="label">Links (e.g. Facebook group, HOA website, event flyer)</span><div id="linkRows">${(s.community.links || []).map(linkRow).join('')}</div>
        <button type="button" class="secondary" id="btnAddLink">+ Add link</button></div>
    </div></div>
    <div class="panel"><h2>Event</h2><div class="form-grid">
      <div class="field"><label for="eType">Event type</label><select id="eType" aria-describedby="eTypeHelp">${types.map((t) => `<option value="${esc(t.key)}" ${t.key === type.key ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>
        <span class="form-caption" id="eTypeHelp">${types.length > 1 ? 'Changing the type changes the listing fields, filters and words; save, then review this page again.' : `This version supports ${esc(types.map((t) => t.name).join(', '))}. More event types come with later versions.`}</span></div>
      ${field('eDate', type.schedule_kind === 'single_day' ? 'Date *' : 'First day *', s.event.date, 'type="date"')}
      ${type.schedule_kind === 'single_day' ? '' : field('eEndsOn', 'Last day *', s.event.ends_on || s.event.date, 'type="date"')}
      ${field('eName', 'Event name', s.event.name, `maxlength="40" placeholder="${esc(type.name)}"`)}
      ${field('eStart', 'Suggested start', s.event.starts_at, 'type="time"')}
      ${field('eEnd', 'Suggested end', s.event.ends_at, 'type="time"')}
    </div><p class="form-caption">This is the event on the map now (<a href="/admin/events">Organizer → Events</a>). Its name and hours change here, its date only while no home is listed. For next season, prepare a new event under Events: each season's listings stay with their season.</p></div>
    <div class="panel"><h2>Branding</h2><div class="form-grid">
      ${field('bEmoji', 'Brand emoji', s.branding.brand_emoji, 'maxlength="8"')}
      <div class="field"><span class="label">Name</span><label class="check-chip"><input type="checkbox" id="bPlace" ${s.branding.show_locality ? 'checked' : ''}> Show city under the name</label></div>
      <div class="field"><span class="label">Logo</span><input type="file" id="bLogo" accept=".svg,.png,.jpg,.jpeg,.webp"> ${s.branding.logo ? `<span class="form-caption">Current: ${esc(s.branding.logo)} <button type="button" class="link-button" id="bLogoRemove">Remove</button></span>` : ''}</div>
      ${field('bPrimary', 'Primary colour', s.branding.primary_color, 'type="color"')}
      ${field('bAccent', 'Accent colour', s.branding.accent_color, 'type="color"')}
      ${field('bHeadline', 'Headline', s.branding.headline, 'maxlength="40"')}
      ${field('bHeadlineAccent', 'Headline (accent part)', s.branding.headline_accent, 'maxlength="40"')}
      ${area('bIntro', 'Intro text', s.branding.intro)}
    </div></div>
    <div class="panel"><h2>Features</h2><div class="checks">
      ${type.features.map((f) => check(`feature-${esc(f.key)}`, esc(f.label), s.features[f.key] ?? true)).join('')}
      ${SHARED_FEATURES.map(([k, label, id]) => check(id, label, s.features[k])).join('')}
    </div></div>
    <div class="panel"><h2>Zones (optional)</h2><p class="form-caption">Phases, sections or any named areas. Leave empty for a community without zones. Draw their polygons on the Boundary &amp; zones page. <b>Coming soon</b> draws the zone with that label but homes there cannot be listed yet (e.g. no houses built); <b>Hidden</b> leaves it off the public map entirely.</p>
      <div class="table-wrap"><table class="data zones-table"><thead><tr><th>Key</th><th>Label</th><th>Listable</th><th>Residential</th><th>Colour</th><th>Status</th><th>Name on map</th><th></th></tr></thead><tbody id="zoneRows">${s.zones.map(zoneRow).join('')}</tbody></table></div>
      <button type="button" class="secondary" id="addZone">+ Add zone</button>
    </div>
    <div class="panel"><h2>House listings</h2><div class="form-grid">
      <div class="field"><label for="rMode">Address source</label><select id="rMode">
        <option value="registry" ${s.registration.address_mode === 'registry' ? 'selected' : ''}>Reviewed address list only</option>
        <option value="self_entry" ${s.registration.address_mode === 'self_entry' ? 'selected' : ''}>Street list + resident pin</option>
        <option value="both" ${s.registration.address_mode === 'both' ? 'selected' : ''}>Both</option></select></div>
      <div class="field"><span class="label">Listings</span><label class="check-chip"><input type="checkbox" id="rEnabled" ${s.registration.enabled ? 'checked' : ''}> Allow new listings</label></div>
      <div class="field"><span class="label">New addresses</span><label class="check-chip"><input type="checkbox" id="rReview" ${s.registration.review_new_addresses ? 'checked' : ''}> Approve self-entered homes before they show</label></div>
      ${area('rStreets', 'Allowed streets (one per line, for self-entry)', s.registration.street_allowlist.join('\n'))}
      ${field('rIdle', 'Sign-in session idle minutes (5–240)', s.registration.session_idle_minutes, 'type="number" min="5" max="240"')}
      ${field('rLink', 'Email link minutes (5–60)', s.registration.email_magic_link_minutes, 'type="number" min="5" max="60"')}
      ${field('rMaxHomes', 'Homes one neighbor can manage (1–50)', s.registration.max_homes_per_user ?? 2, 'type="number" min="1" max="50"')}
    </div><p class="form-caption">Environment variables AUTH_SESSION_IDLE_MINUTES / MAGIC_LINK_MINUTES override these values when set.</p></div>
    <div class="panel"><h2>Map &amp; routes</h2><div class="form-grid">
      ${field('mTiles', 'Tile URL template (https, {z}/{x}/{y})', s.map.tiles.url)}
      ${field('mAttr', 'Tile attribution *', s.map.tiles.attribution)}
      ${field('mZoom', 'Default zoom', s.map.zoom, 'type="number" min="10" max="19"')}
      ${area('mNotes', 'Crossing / safety notes (one per line)', s.map.crossing_notes.join('\n'))}
      ${area('rGroups', 'Walkable zone groups (one group per line, keys separated by commas; optional name first, e.g. "Main: phase-1, phase-2")', s.routing.groups.map((g, i) => `${(s.routing.group_labels || [])[i] ? `${s.routing.group_labels[i]}: ` : ''}${g.join(', ')}`).join('\n'), 'Named or multi-zone groups also appear as walking areas in the map’s zone filter, e.g. “Main (1–3)”. Zones in different groups are always shown as separate trips. Only group zones you have verified are safely walkable together.')}
      <div class="field full"><label class="check-chip"><input type="checkbox" id="rVerified" ${s.routing.crossings_verified ? 'checked' : ''}> I verified safe pedestrian crossings for the groups above</label></div>
    </div></div>
    <div class="modal-actions"><button type="submit" class="primary">Save settings</button></div>
  </form>
  <datalist id="tzList">${(Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : []).map((t) => `<option value="${t}">`).join('')}</datalist>`;

  $('#addZone').addEventListener('click', () => $('#zoneRows').insertAdjacentHTML('beforeend', zoneRow()));
  $('#zoneRows').addEventListener('click', (e) => {
    if (e.target.matches('[data-remove-zone]')) e.target.closest('tr').remove();
    if (e.target.matches('[data-reset-label]')) { // the map chooses the spot again once settings are saved
      e.target.closest('tr').dataset.resetLabel = '1';
      e.target.closest('td').innerHTML = '<span class="form-caption">Automatic after saving</span>';
    }
  });
  $('#btnAddLink').addEventListener('click', () => $('#linkRows').insertAdjacentHTML('beforeend', linkRow()));
  $('#linkRows').addEventListener('click', (e) => { if (e.target.matches('[data-remove-link]')) e.target.closest('.link-row').remove(); });
  $('#settingsForm').addEventListener('submit', save);
  $('#bLogo').addEventListener('change', uploadLogo);
  $('#bLogoRemove')?.addEventListener('click', async () => {
    const fd = new FormData(); fd.append('remove', '1');
    await call('/api/admin/community/logo', { method: 'POST', body: fd });
    flash('Logo removed.'); load();
  });
}

const lines = (id) => $(id).value.split('\n').map((l) => l.trim()).filter(Boolean);

async function save(e) {
  e.preventDefault();
  const s = structuredClone(settings);
  Object.assign(s.community, { name: $('#cName').value, subtitle: $('#cSubtitle').value, locality: $('#cLocality').value, region: $('#cRegion').value,
    postal_code: $('#cPostal').value, timezone: $('#cTz').value, gate_notice: $('#cGate').value, contact_url: $('#cContact').value || null,
    access: $('#cAccess').value, about: $('#cAbout').value, reminders: lines('#cReminders'),
    links: $$('#linkRows .link-row').map((r) => ({ label: $('[data-l=label]', r).value.trim(), url: $('[data-l=url]', r).value.trim() })).filter((l) => l.label || l.url) });
  Object.assign(s.event, { type: $('#eType').value, name: $('#eName').value, date: $('#eDate').value, starts_at: $('#eStart').value, ends_at: $('#eEnd').value });
  // An event of several days (Christmas lights) has a first and a last day; a one-day event keeps only its date.
  if ($('#eEndsOn')) Object.assign(s.event, { starts_on: $('#eDate').value, ends_on: $('#eEndsOn').value });
  Object.assign(s.branding, { brand_emoji: $('#bEmoji').value, primary_color: $('#bPrimary').value, accent_color: $('#bAccent').value,
    headline: $('#bHeadline').value, headline_accent: $('#bHeadlineAccent').value, intro: $('#bIntro').value, show_locality: $('#bPlace').checked });
  for (const f of type.features) s.features[f.key] = $(`#feature-${f.key}`).checked;
  for (const [k, , id] of SHARED_FEATURES) s.features[k] = $(`#${id}`).checked;
  // Fields this table does not show (description, the name's spot on the map) are kept from the saved zone.
  const before = Object.fromEntries((settings.zones || []).map((z) => [z.key, z]));
  s.zones = $$('#zoneRows tr').map((tr) => {
    const key = $('[data-z=key]', tr).value.trim();
    return {
      ...(before[key] || {}),
      key, label: $('[data-z=label]', tr).value.trim(), claimable: $('[data-z=claimable]', tr).checked,
      residential: $('[data-z=residential]', tr).checked, color: $('[data-z=color]', tr).value.trim() || undefined,
      status: $('[data-z=status]', tr).value,
      ...(tr.dataset.resetLabel ? { label_at: null } : {}),
    };
  }).filter((z) => z.key);
  Object.assign(s.registration, { address_mode: $('#rMode').value, enabled: $('#rEnabled').checked, street_allowlist: lines('#rStreets'),
    session_idle_minutes: Number($('#rIdle').value), email_magic_link_minutes: Number($('#rLink').value),
    max_homes_per_user: Number($('#rMaxHomes').value), review_new_addresses: $('#rReview').checked });
  s.map.tiles = { ...s.map.tiles, url: $('#mTiles').value, attribution: $('#mAttr').value };
  s.map.zoom = Number($('#mZoom').value);
  s.map.crossing_notes = lines('#mNotes');
  const groupLines = lines('#rGroups').map((l) => (l.includes(':') ? [l.slice(0, l.indexOf(':')).trim(), l.slice(l.indexOf(':') + 1)] : ['', l]));
  s.routing.groups = groupLines.map(([, keys]) => keys.split(',').map((k) => k.trim()).filter(Boolean));
  s.routing.group_labels = groupLines.map(([name]) => name);
  s.routing.crossings_verified = $('#rVerified').checked;
  const res = await call('/api/admin/community', { method: 'PUT', body: { settings: s } });
  settings = res.settings;
  flash('Settings saved.');
  if (settings.event.type !== type.key) load(); // another type: its features and labels
}

async function uploadLogo(e) {
  const f = e.target.files[0];
  if (!f) return;
  const fd = new FormData();
  fd.append('logo', f);
  await call('/api/admin/community/logo', { method: 'POST', body: fd });
  flash('Logo updated.');
  load();
}

async function load() {
  ({ settings, event_types: types, event_type: type } = await call('/api/admin/community'));
  render();
}
load();
