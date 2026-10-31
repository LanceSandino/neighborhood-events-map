/**
 * @module assets/js/admin/overview
 * @description The community's overview: its counts (claims, addresses, review requests, the organizer's holds and
 *     addresses waiting for approval), each public event with its own name, type mark, state and homes (an event's own
 *     section), the setup checklist, warnings and the registration switch. Nothing here takes an event's branding
 *     (D-20261031-52).
 */
import { $, call, esc, flash, fmtDate } from './common.js';
import { csrfToken } from '../lib/api.js';

const STATE = { upcoming: 'Upcoming', open: 'Open — on the map', closed: 'Closed' };
const dates = (e) => (!e.starts_on ? 'dates to be announced' : (e.starts_on === e.ends_on ? e.starts_on : `${e.starts_on} – ${e.ends_on}`) + (e.dates_tentative ? ' (tentative)' : ''));

/** One public event, in its own section: its type mark, name, state, dates and homes. */
function eventRow(e) {
  const homes = e.state === 'open' ? `${e.public} of ${e.opted_in} listed homes on the map` : `${e.opted_in} homes listed`;
  return `<li data-event-section="${esc(e.type)}"><span aria-hidden="true">${esc(e.type_icon || '')}</span> <b>${esc(e.name)}</b>${e.is_default ? ' <span class="badge">Default</span>' : ''} · ${esc(STATE[e.state] || e.state)} · ${esc(dates(e))} · ${esc(homes)}</li>`;
}

async function load() {
  const [o, ev] = await Promise.all([call('/api/admin/overview'), call('/api/admin/events')]);
  // The public events as visitors' tabs order them: the default first, then by date (dates to be announced last).
  const shown = ev.events.filter((e) => ['upcoming', 'open', 'closed'].includes(e.state))
    .sort((a, b) => (b.is_default - a.is_default) || String(a.starts_on || '9999').localeCompare(String(b.starts_on || '9999')));
  const item = (ok, text, hint = '') => `<li><span class="${ok === true ? 'ok' : ok === 'warn' ? 'warn' : 'todo'}">${ok === true ? '✓' : ok === 'warn' ? '!' : '○'}</span><div>${text}${hint ? `<br><span class="form-caption">${hint}</span>` : ''}</div></li>`;
  const b = o.boundary;
  $('#admin-overview').innerHTML = `
    <div class="cards-row">
      <div class="stat"><b>${o.counts.claims}</b><span>Claimed addresses</span></div>
      <div class="stat"><b>${o.counts.addresses}</b><span>Addresses in the private registry</span></div>
      <div class="stat"><b>${o.counts.open_disputes}</b><span>Open review requests</span></div>
      <div class="stat"><b>${o.counts.hidden_listings}</b><span>Hidden by organizer</span></div>
      <div class="stat"><b>${o.counts.pending_review}</b><span>New addresses waiting for approval</span></div>
    </div>
    <div class="panel"><h2>Events on the public site</h2>${shown.length ? `<ul class="event-summary">${shown.map(eventRow).join('')}</ul>` : '<p class="notice">No event is public: visitors see “No event on the map right now”.</p>'}
      <p class="form-caption"><a href="/admin/events">Prepare, open, close or choose the default event</a>; each event’s look is in its Branding section there.</p></div>
    ${o.pending_addresses.length ? `<div class="panel"><h2>New addresses to approve</h2><p class="form-caption">Residents added these homes themselves. Check each one, then choose Approve (or Hide) on the Addresses page.</p>
      <ul class="link-list">${o.pending_addresses.map((p) => `<li><a href="/admin/addresses?q=${encodeURIComponent(p.address)}">${esc(p.address)}</a></li>`).join('')}</ul></div>` : ''}
    <div class="panel"><h2>Setup checklist</h2><ul class="checklist">
      ${item(o.configured, 'Community details', '<a href="/admin/community">Name, logo and time zone</a>')}
      ${item(shown.length ? true : 'warn', shown.length ? `${shown.length} public event${shown.length === 1 ? '' : 's'} (see above)` : 'No public event', '<a href="/admin/events">Prepare and open events</a>')}
      ${item(b.present, 'Neighborhood boundary', '<a href="/admin/community/map">Draw or import a real GeoJSON boundary</a>')}
      ${item(b.approved ? true : (b.present ? 'warn' : false), b.approved ? `Boundary v${b.version} approved` : 'Boundary approval', 'House listings stay closed until you review the outline against real streets and approve it.')}
      ${item(b.zones_without_geometry.length === 0 ? true : 'warn', 'Zone geometry', b.zones_without_geometry.length ? 'Zones without polygons: ' + esc(b.zones_without_geometry.join(', ')) : 'Every configured zone has a polygon (or none are used).')}
      ${item(o.counts.addresses > 0 ? true : 'warn', 'Address inventory', o.counts.addresses ? `${o.counts.addresses} addresses (${o.counts.addresses_outside_boundary} outside the boundary)` : 'Import a reviewed CSV or enable self-entry with a street list.')}
      ${item(o.registration.open ? true : 'warn', o.registration.open ? 'House listings are OPEN' : 'House listings are closed', o.registration.reason ? `<span data-event-section="default">${esc(o.registration.reason)}</span>` : '')}
    </ul>
    ${b.warnings.map((w) => `<div class="notice">⚠️ ${esc(w)}</div>`).join('')}
    <div class="modal-actions"><button type="button" class="${o.registration.enabled ? 'secondary' : 'primary'}" id="btnReg">${o.registration.enabled ? (o.registration.open ? 'Close house listings' : 'Turn off listings (they open after boundary approval)') : 'Allow house listings'}</button><form class="inline-form" method="post" action="/api/admin/export/claims.csv"><input type="hidden" name="_csrf" value="${esc(csrfToken())}"><button type="submit" class="secondary">Export claims (CSV, private)</button></form></div></div>
    <div class="panel"><h2>Geometry versions</h2><div class="table-wrap"><table class="data"><thead><tr><th>Version</th><th>Created</th><th>Zones</th><th>Approved</th><th>Active</th></tr></thead><tbody>
      ${o.versions.map((v) => `<tr><td>v${v.version}</td><td class="nowrap">${esc(fmtDate(v.created_at))}</td><td>${v.zones}</td><td>${v.approved_at ? esc(fmtDate(v.approved_at)) : '—'}</td><td>${v.active ? 'yes' : ''}</td></tr>`).join('') || '<tr><td colspan="5">No geometry yet.</td></tr>'}
    </tbody></table></div></div>`;
  $('#btnReg').addEventListener('click', async () => {
    await call('/api/admin/registration', { method: 'POST', body: { enabled: !o.registration.enabled } });
    flash(o.registration.enabled ? 'House listings closed.' : 'House listings allowed (they still require an approved boundary).');
    load();
  });
}
load();
