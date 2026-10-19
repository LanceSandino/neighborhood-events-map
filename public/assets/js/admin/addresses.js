/**
 * @module assets/js/admin/addresses
 * @description Private address registry and claims: search, add (with explicit coordinates — never
 *     geocoded), edit coordinates/zone/enabled, CSV import (dry run first), transfer/unclaim with
 *     explicit confirmation, hide/unhide listings. Organizer-only.
 */
import { $, call, esc, flash } from './common.js';

let zones = [];

function shell() {
  $('#admin-addresses').innerHTML = `
    <div class="panel"><div class="toolbar"><input type="search" id="q" placeholder="Search address or owner email" aria-label="Search addresses"><button type="button" class="secondary" id="btnSearch">Search</button><span id="total" class="form-caption"></span></div>
      <div class="table-wrap"><table class="data"><thead><tr><th>Address</th><th>Zone</th><th>Location</th><th>Owner (private)</th><th>Listing</th><th>Actions</th></tr></thead><tbody id="rows"></tbody></table></div></div>
    <div class="panel"><h2>Add an address</h2><form id="addForm" class="form-grid" novalidate>
      <div class="field full"><label for="aAddr">Address</label><input id="aAddr" placeholder="123 Pumpkin Ln"></div>
      <div class="field"><label for="aLat">Latitude</label><input id="aLat" inputmode="decimal"></div>
      <div class="field"><label for="aLon">Longitude</label><input id="aLon" inputmode="decimal"></div>
      <div class="field"><label for="aZone">Zone</label><select id="aZone"><option value="">(none)</option>${zones.map((z) => `<option value="${esc(z.key)}">${esc(z.label)}</option>`).join('')}</select></div>
      <div class="field"><span class="label">&nbsp;</span><button type="submit" class="primary">Add address</button></div>
      <p class="form-caption full">Coordinates must come from a lawful, verified source (e.g. county address points). Nothing is geocoded or invented.</p></form></div>
    <div class="panel"><h2>Import a reviewed CSV</h2>
      <p class="form-caption">Columns: <code>address</code> (or <code>house_number,street,unit</code>), <code>latitude</code>, <code>longitude</code>, optional <code>zone_key</code>. Rows outside the boundary or with mismatched zones are skipped and reported.</p>
      <form id="importForm" class="toolbar"><input type="file" id="csv" accept=".csv,text/csv" aria-label="CSV file"><label><input type="checkbox" id="csvUpdate"> Update existing coordinates/zones</label>
        <button type="button" class="secondary" id="btnDry">Dry run</button><button type="button" class="primary" id="btnImport">Import</button></form>
      <pre class="json hide" id="report"></pre></div>`;
}

function row(a) {
  const listing = a.listing ? `${esc(a.listing.status)}${a.listing.published ? '' : ' · unpublished'}${a.listing.hidden ? ' · <b class="todo">hidden</b>' : ''}<br><span class="form-caption">${esc(a.listing.event_date)}</span>` : '—';
  return `<tr data-id="${a.id}">
    <td><b>${esc(a.address)}</b><br><span class="form-caption">#${a.id} · ${esc(a.source)}${a.enabled ? '' : ' · disabled'}${a.inside_boundary ? '' : ' · <span class="todo">outside boundary</span>'}</span></td>
    <td>${esc(a.zone ?? '—')}</td>
    <td><span class="form-caption">${a.lat.toFixed(6)}, ${a.lon.toFixed(6)}</span></td>
    <td>${a.owner_email ? `${esc(a.owner_email)}<br><span class="form-caption">user #${a.owner_user_id}</span>` : '<span class="form-caption">unclaimed</span>'}</td>
    <td>${listing}</td>
    <td><div class="row-actions">
      <button type="button" data-act="edit">Edit</button>
      ${a.owner_user_id ? '<button type="button" data-act="transfer">Transfer</button><button type="button" data-act="unclaim">Unclaim</button>' : ''}
      ${a.listing ? (a.listing.hidden ? '<button type="button" data-act="unhide">Unhide</button>' : '<button type="button" data-act="hide">Hide</button>') : ''}
    </div></td></tr>`;
}

let rows = [];
async function search() {
  const res = await call(`/api/admin/addresses?q=${encodeURIComponent($('#q').value)}`);
  rows = res.addresses;
  $('#total').textContent = `${res.total} addresses total · showing ${rows.length}`;
  $('#rows').innerHTML = rows.map(row).join('') || '<tr><td colspan="6">No matches.</td></tr>';
}

async function act(id, action) {
  const a = rows.find((r) => r.id === id);
  if (action === 'edit') {
    const lat = prompt('Latitude', a.lat); if (lat === null) return;
    const lon = prompt('Longitude', a.lon); if (lon === null) return;
    const zone = prompt(`Zone key (${zones.map((z) => z.key).join(', ') || 'none'}; blank for none)`, a.zone ?? ''); if (zone === null) return;
    const enabled = confirm('Keep this address enabled for claims? (Cancel = disable)');
    await call(`/api/admin/addresses/${id}`, { method: 'PATCH', body: { lat: Number(lat), lon: Number(lon), zone_key: zone.trim() || null, enabled } });
    flash('Address updated.');
  } else if (action === 'transfer') {
    const email = prompt(`Transfer ${a.address} to which email? The current owner (${a.owner_email}) loses access.`); if (!email) return;
    if (!confirm(`Confirm transfer of ${a.address} to ${email}? This is recorded in the audit log.`)) return;
    const disputeId = new URLSearchParams(location.search).get('dispute');
    await call(`/api/admin/claims/${id}/transfer`, { method: 'POST', body: { email, confirm: true, dispute_id: disputeId ? Number(disputeId) : undefined } });
    flash(`Transferred ${a.address} to ${email}.`);
  } else if (action === 'unclaim') {
    const reason = prompt(`Unclaim ${a.address}? Its listing will be removed. Reason (for the audit log):`); if (reason === null) return;
    await call(`/api/admin/claims/${id}/unclaim`, { method: 'POST', body: { confirm: true, reason } });
    flash(`${a.address} is unclaimed.`);
  } else if (action === 'hide' || action === 'unhide') {
    const reason = action === 'hide' ? prompt('Reason for hiding (audit log):', 'Reported listing') : '';
    if (reason === null) return;
    await call(`/api/admin/listings/${id}/${action}`, { method: 'POST', body: { reason } });
    flash(action === 'hide' ? 'Listing hidden from the public map.' : 'Listing visible again.');
  }
  search();
}

async function importCsv(dry) {
  const f = $('#csv').files[0];
  if (!f) { flash('Choose a CSV file first.', 'error'); return; }
  const fd = new FormData();
  fd.append('file', f);
  if (dry) fd.append('dry_run', '1');
  if ($('#csvUpdate').checked) fd.append('update', '1');
  const { report } = await call('/api/admin/addresses/import', { method: 'POST', body: fd });
  $('#report').classList.remove('hide');
  $('#report').textContent = `${dry ? 'DRY RUN — nothing saved\n' : ''}Imported ${report.imported}, updated ${report.updated}, unchanged ${report.unchanged}, skipped ${report.skipped.length}\n` + report.skipped.map((s) => `line ${s.line}: ${s.reason}`).join('\n');
  if (!dry) { flash('Import finished.'); search(); }
}

(async () => {
  zones = (await call('/api/admin/community')).settings.zones;
  shell();
  $('#q').value = new URLSearchParams(location.search).get('q') || '';
  $('#btnSearch').addEventListener('click', search);
  $('#q').addEventListener('keydown', (e) => { if (e.key === 'Enter') search(); });
  $('#rows').addEventListener('click', (e) => { const b = e.target.closest('button[data-act]'); if (b) act(Number(b.closest('tr').dataset.id), b.dataset.act); });
  $('#addForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    await call('/api/admin/addresses', { method: 'POST', body: { address: $('#aAddr').value, lat: Number($('#aLat').value), lon: Number($('#aLon').value), zone_key: $('#aZone').value || null } });
    flash('Address added.'); e.target.reset(); search();
  });
  $('#btnDry').addEventListener('click', () => importCsv(true));
  $('#btnImport').addEventListener('click', () => importCsv(false));
  search();
})();
