/**
 * @module assets/js/admin/community
 * @description Community, event, branding, features, zones, registration and routing settings
 *     (PUT /api/admin/community; the server validates everything) plus logo upload.
 */
import { $, $$, call, esc, flash } from './common.js';

let settings = null;

const field = (id, label, value, attrs = '') => `<div class="field"><label for="${id}">${label}</label><input id="${id}" value="${esc(value ?? '')}" ${attrs}></div>`;
const area = (id, label, value, help = '') => `<div class="field full"><label for="${id}">${label}</label><textarea id="${id}">${esc(value ?? '')}</textarea>${help ? `<span class="form-caption">${help}</span>` : ''}</div>`;
const check = (id, label, on) => `<label class="check-chip"><input type="checkbox" id="${id}" ${on ? 'checked' : ''}> ${label}</label>`;

function zoneRow(z = { key: '', label: '', claimable: true, residential: true, color: '', description: '' }) {
  return `<tr>
    <td><input data-z="key" value="${esc(z.key)}" pattern="[a-z0-9][a-z0-9-]*" aria-label="Zone key"></td>
    <td><input data-z="label" value="${esc(z.label)}" aria-label="Zone label"></td>
    <td><input type="checkbox" data-z="claimable" ${z.claimable ? 'checked' : ''} aria-label="Homes can be listed"></td>
    <td><input type="checkbox" data-z="residential" ${z.residential ? 'checked' : ''} aria-label="Residential"></td>
    <td><input data-z="color" value="${esc(z.color || '')}" placeholder="#RRGGBB" aria-label="Colour"></td>
    <td><button type="button" class="link-button" data-remove-zone>Remove</button></td></tr>`;
}

const ACCESS = [
  ['unspecified', 'Not stated'],
  ['public', 'Open to the public (public streets)'],
  ['gated_open', 'Gated — gates open to trick-or-treaters during event hours'],
  ['gated_guests', 'Gated — residents and their invited guests only'],
  ['private', 'Private — residents only'],
];

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
      <div class="field full"><label for="cAccess">Access on Halloween night</label><select id="cAccess">${ACCESS.map(([k, l]) => `<option value="${k}" ${s.community.access === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
      ${area('cGate', 'Access notes (gates, entrances, parking)', s.community.gate_notice, 'Shown on the map and the About page. The site never grants gate access.')}
      ${field('cContact', 'Organizer contact (mailto: or https://) *', s.community.contact_url, 'placeholder="mailto:organizer@example.org"')}
    </div></div>
    <div class="panel"><h2>About page</h2><div class="form-grid">
      ${area('cAbout', 'About the neighborhood and the event', s.community.about, 'Shown on the public About page. Blank lines start a new paragraph. Plain text only.')}
      <div class="field full"><span class="label">Links (e.g. Facebook group, HOA website, event flyer)</span><div id="linkRows">${(s.community.links || []).map(linkRow).join('')}</div>
        <button type="button" class="secondary" id="btnAddLink">+ Add link</button></div>
    </div></div>
    <div class="panel"><h2>Event</h2><div class="form-grid">
      ${field('eDate', 'Date *', s.event.date, 'type="date"')}
      <div></div>
      ${field('eStart', 'Suggested start', s.event.starts_at, 'type="time"')}
      ${field('eEnd', 'Suggested end', s.event.ends_at, 'type="time"')}
    </div><p class="form-caption">Public listings show only homes that opted in for this date. Changing the date to next year hides last season's homes until owners save again.</p></div>
    <div class="panel"><h2>Branding</h2><div class="form-grid">
      ${field('bEmoji', 'Brand emoji', s.branding.brand_emoji, 'maxlength="8"')}
      <div class="field"><span class="label">Logo</span><input type="file" id="bLogo" accept=".svg,.png,.jpg,.jpeg,.webp"> ${s.branding.logo ? `<span class="form-caption">Current: ${esc(s.branding.logo)} <button type="button" class="link-button" id="bLogoRemove">Remove</button></span>` : ''}</div>
      ${field('bPrimary', 'Primary colour', s.branding.primary_color, 'type="color"')}
      ${field('bAccent', 'Accent colour', s.branding.accent_color, 'type="color"')}
      ${field('bHeadline', 'Headline', s.branding.headline, 'maxlength="40"')}
      ${field('bHeadlineAccent', 'Headline (accent part)', s.branding.headline_accent, 'maxlength="40"')}
      ${area('bIntro', 'Intro text', s.branding.intro)}
    </div></div>
    <div class="panel"><h2>Features</h2><div class="checks">
      ${check('fCandy', 'Candy', s.features.candy)}${check('fNonfood', 'Non-food treats', s.features.nonfood_treats)}
      ${check('fAdult', 'Adult refreshments (21+)', s.features.adult_refreshments)}${check('fSensory', 'Sensory filters', s.features.sensory_filters)}
      ${check('fZones', 'Show zone filter', s.features.optional_zones)}${check('fRoutes', 'Suggested stop order', s.features.walking_routes)}
    </div></div>
    <div class="panel"><h2>Zones (optional)</h2><p class="form-caption">Phases, sections or any named areas. Leave empty for a community without zones. Draw their polygons on the Boundary &amp; zones page.</p>
      <div class="table-wrap"><table class="data zones-table"><thead><tr><th>Key</th><th>Label</th><th>Listable</th><th>Residential</th><th>Colour</th><th></th></tr></thead><tbody id="zoneRows">${s.zones.map(zoneRow).join('')}</tbody></table></div>
      <button type="button" class="secondary" id="addZone">+ Add zone</button>
    </div>
    <div class="panel"><h2>House listings</h2><div class="form-grid">
      <div class="field"><label for="rMode">Address source</label><select id="rMode">
        <option value="registry" ${s.registration.address_mode === 'registry' ? 'selected' : ''}>Reviewed address list only</option>
        <option value="self_entry" ${s.registration.address_mode === 'self_entry' ? 'selected' : ''}>Street list + resident pin</option>
        <option value="both" ${s.registration.address_mode === 'both' ? 'selected' : ''}>Both</option></select></div>
      <div class="field"><span class="label">Listings</span><label class="check-chip"><input type="checkbox" id="rEnabled" ${s.registration.enabled ? 'checked' : ''}> Allow new listings</label></div>
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
      ${area('rGroups', 'Walkable zone groups (one group per line, keys separated by commas)', s.routing.groups.map((g) => g.join(', ')).join('\n'), 'Zones in different groups are always shown as separate trips. Only group zones you have verified are safely walkable together.')}
      <div class="field full"><label class="check-chip"><input type="checkbox" id="rVerified" ${s.routing.crossings_verified ? 'checked' : ''}> I verified safe pedestrian crossings for the groups above</label></div>
    </div></div>
    <div class="modal-actions"><button type="submit" class="primary">Save settings</button></div>
  </form>
  <datalist id="tzList">${(Intl.supportedValuesOf ? Intl.supportedValuesOf('timeZone') : []).map((t) => `<option value="${t}">`).join('')}</datalist>`;

  $('#addZone').addEventListener('click', () => $('#zoneRows').insertAdjacentHTML('beforeend', zoneRow()));
  $('#zoneRows').addEventListener('click', (e) => { if (e.target.matches('[data-remove-zone]')) e.target.closest('tr').remove(); });
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
    access: $('#cAccess').value, about: $('#cAbout').value,
    links: $$('#linkRows .link-row').map((r) => ({ label: $('[data-l=label]', r).value.trim(), url: $('[data-l=url]', r).value.trim() })).filter((l) => l.label || l.url) });
  Object.assign(s.event, { date: $('#eDate').value, starts_at: $('#eStart').value, ends_at: $('#eEnd').value });
  Object.assign(s.branding, { brand_emoji: $('#bEmoji').value, primary_color: $('#bPrimary').value, accent_color: $('#bAccent').value,
    headline: $('#bHeadline').value, headline_accent: $('#bHeadlineAccent').value, intro: $('#bIntro').value });
  Object.assign(s.features, { candy: $('#fCandy').checked, nonfood_treats: $('#fNonfood').checked, adult_refreshments: $('#fAdult').checked,
    sensory_filters: $('#fSensory').checked, optional_zones: $('#fZones').checked, walking_routes: $('#fRoutes').checked });
  s.zones = $$('#zoneRows tr').map((tr) => ({
    key: $('[data-z=key]', tr).value.trim(), label: $('[data-z=label]', tr).value.trim(), claimable: $('[data-z=claimable]', tr).checked,
    residential: $('[data-z=residential]', tr).checked, color: $('[data-z=color]', tr).value.trim() || undefined,
  })).filter((z) => z.key);
  Object.assign(s.registration, { address_mode: $('#rMode').value, enabled: $('#rEnabled').checked, street_allowlist: lines('#rStreets'),
    session_idle_minutes: Number($('#rIdle').value), email_magic_link_minutes: Number($('#rLink').value),
    max_homes_per_user: Number($('#rMaxHomes').value) });
  s.map.tiles = { ...s.map.tiles, url: $('#mTiles').value, attribution: $('#mAttr').value };
  s.map.zoom = Number($('#mZoom').value);
  s.map.crossing_notes = lines('#mNotes');
  s.routing.groups = lines('#rGroups').map((l) => l.split(',').map((k) => k.trim()).filter(Boolean));
  s.routing.crossings_verified = $('#rVerified').checked;
  const res = await call('/api/admin/community', { method: 'PUT', body: { settings: s } });
  settings = res.settings;
  flash('Settings saved.');
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
  settings = (await call('/api/admin/community')).settings;
  render();
}
load();
