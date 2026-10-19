/**
 * @module assets/js/admin/disputes
 * @description Review requests ("already claimed"): read evidence offline, then resolve/reject with a
 *     note. Transfers are done explicitly from the Addresses page (linked here). No automation.
 */
import { $, call, esc, flash, fmtDate } from './common.js';

async function load() {
  const status = $('#filter')?.value || 'open';
  const { disputes } = await call(`/api/admin/disputes${status === 'all' ? '' : '?status=' + status}`);
  $('#list').innerHTML = disputes.map((d) => `<div class="panel" data-id="${d.id}">
      <h2>${esc(d.ticket)} · ${esc(d.display_address)} <span class="${d.status === 'open' ? 'warn' : 'ok'}">${esc(d.status)}</span></h2>
      <p class="form-caption">Received ${esc(fmtDate(d.created_at))} · reporter <a href="mailto:${esc(d.reporter_email)}">${esc(d.reporter_email)}</a> · current owner (private): ${esc(d.owner_email || 'none')}${d.notified_at ? '' : ' · <span class="todo">email notice failed</span>'}</p>
      <p class="reason">${esc(d.reason)}</p>
      ${d.resolution_note ? `<p class="form-caption">Note: ${esc(d.resolution_note)}</p>` : ''}
      <div class="modal-actions"><a class="secondary" href="/admin/addresses?q=${encodeURIComponent(d.display_address)}&dispute=${d.id}">Open address (transfer / unclaim / hide)</a>
        <button type="button" class="secondary" data-res="rejected">Reject</button><button type="button" class="primary" data-res="resolved">Mark resolved</button>
        ${d.status !== 'open' ? '<button type="button" class="secondary" data-res="open">Reopen</button>' : ''}</div>
    </div>`).join('') || '<p class="empty">No review requests here.</p>';
}

$('#admin-disputes').innerHTML = `<div class="toolbar"><label for="filter">Show</label><select id="filter"><option value="open">Open</option><option value="resolved">Resolved</option><option value="rejected">Rejected</option><option value="all">All</option></select></div><div id="list"></div>`;
$('#filter').addEventListener('change', load);
$('#list').addEventListener('click', async (e) => {
  const b = e.target.closest('button[data-res]');
  if (!b) return;
  const id = b.closest('[data-id]').dataset.id;
  const note = prompt('Note for the record (what you checked / decided):', '');
  if (note === null) return;
  await call(`/api/admin/disputes/${id}`, { method: 'POST', body: { status: b.dataset.res, note } });
  flash(`Request updated: ${b.dataset.res}.`);
  load();
});
load();
