/**
 * @module assets/js/admin/overview
 * @description Setup checklist, counts, warnings and the registration switch.
 */
import { $, call, esc, flash } from './common.js';

async function load() {
  const o = await call('/api/admin/overview');
  const item = (ok, text, hint = '') => `<li><span class="${ok === true ? 'ok' : ok === 'warn' ? 'warn' : 'todo'}">${ok === true ? '✓' : ok === 'warn' ? '!' : '○'}</span><div>${text}${hint ? `<br><span class="form-caption">${hint}</span>` : ''}</div></li>`;
  const b = o.boundary;
  $('#admin-overview').innerHTML = `
    <div class="cards-row">
      <div class="stat"><b>${o.counts.public_listings}</b><span>Homes on the public map</span></div>
      <div class="stat"><b>${o.counts.claims}</b><span>Claimed addresses</span></div>
      <div class="stat"><b>${o.counts.addresses}</b><span>Addresses in the private registry</span></div>
      <div class="stat"><b>${o.counts.open_disputes}</b><span>Open review requests</span></div>
      <div class="stat"><b>${o.counts.hidden_listings}</b><span>Hidden by organizer</span></div>
    </div>
    <div class="panel"><h2>Setup checklist</h2><ul class="checklist">
      ${item(o.configured, 'Community details', '<a href="/admin/community">Name, branding, event date and time zone</a>')}
      ${item(b.present, 'Neighborhood boundary', '<a href="/admin/community/map">Draw or import a real GeoJSON boundary</a>')}
      ${item(b.approved ? true : (b.present ? 'warn' : false), b.approved ? `Boundary v${b.version} approved` : 'Boundary approval', 'House listings stay closed until you review the outline against real streets and approve it.')}
      ${item(b.zones_without_geometry.length === 0 ? true : 'warn', 'Zone geometry', b.zones_without_geometry.length ? 'Zones without polygons: ' + esc(b.zones_without_geometry.join(', ')) : 'Every configured zone has a polygon (or none are used).')}
      ${item(o.counts.addresses > 0 ? true : 'warn', 'Address inventory', o.counts.addresses ? `${o.counts.addresses} addresses (${o.counts.addresses_outside_boundary} outside the boundary)` : 'Import a reviewed CSV or enable self-entry with a street list.')}
      ${item(o.registration.open ? true : 'warn', o.registration.open ? 'House listings are OPEN' : 'House listings are closed', esc(o.registration.reason || ''))}
    </ul>
    ${b.warnings.map((w) => `<div class="notice">⚠️ ${esc(w)}</div>`).join('')}
    <div class="modal-actions"><button type="button" class="${o.registration.enabled ? 'secondary' : 'primary'}" id="btnReg">${o.registration.enabled ? 'Close house listings' : 'Allow house listings'}</button><a class="secondary" href="/api/admin/export/claims.csv">Export claims (CSV, private)</a></div></div>
    <div class="panel"><h2>Geometry versions</h2><div class="table-wrap"><table class="data"><thead><tr><th>Version</th><th>Created</th><th>Zones</th><th>Approved</th><th>Active</th></tr></thead><tbody>
      ${o.versions.map((v) => `<tr><td>v${v.version}</td><td>${esc(v.created_at)}</td><td>${v.zones}</td><td>${v.approved_at ? esc(v.approved_at) : '—'}</td><td>${v.active ? 'yes' : ''}</td></tr>`).join('') || '<tr><td colspan="5">No geometry yet.</td></tr>'}
    </tbody></table></div></div>`;
  $('#btnReg').addEventListener('click', async () => {
    await call('/api/admin/registration', { method: 'POST', body: { enabled: !o.registration.enabled } });
    flash(o.registration.enabled ? 'House listings closed.' : 'House listings allowed (they still require an approved boundary).');
    load();
  });
}
load();
