/**
 * @module assets/js/register
 * @description "List my house" bottom sheet (address → stop details → email + PoW → link sent) and the
 *     "already claimed → request a review" dispute sheet. The server is the eligibility authority;
 *     this file only collects input, solves PoW in a worker and shows friendly results. The event type reads and
 *     checks its own listing fields (events/index.js); the listing goes out with them as `details`.
 * @dependencies lib/api.js, lib/pow.js, lib/modal.js, events/index.js, Leaflet (optional, for the self-entry pin map)
 */
import { api, ApiError, friendlyError } from './lib/api.js';
import { solvePow } from './lib/pow.js';
import { openModal } from './lib/modal.js';
import { esc } from './lib/render.js';
import { houseNumberOf } from './lib/address.js';
import { eventType } from './events/index.js';

let C = null;
let T = null; // the community's event type module
let selected = null; // { address_id, address } | { house_number, street, unit, lat, lon, zone_key, address }
let mode = 'registry';
let pinMap = null;
let pinMarker = null;
let step = 1;

const $ = (s) => document.querySelector(s);
const show = (el, on) => el && el.classList.toggle('hide', !on);

function setError(scope, field, message) {
  const el = scope.querySelector(`[data-error="${field}"]`);
  if (el) el.textContent = message || '';
}

function clearErrors(scope) {
  scope.querySelectorAll('[data-error]').forEach((e) => { e.textContent = ''; });
}

export function initRegistration({ community }) {
  C = community;
  T = eventType(C.event.type);
  mode = C.registration.address_mode;
  const form = $('#registerForm');
  if (!form) return;
  T.initForm(form, C.event); // e.g. the season's nights to pick from
  form.addEventListener('click', (e) => {
    const next = e.target.closest('[data-next]');
    if (next) { e.preventDefault(); goTo(Number(next.dataset.next)); }
  });
  form.addEventListener('submit', (e) => { e.preventDefault(); submit(); });

  const q = $('#addrQuery');
  if (q) {
    let timer = null;
    q.addEventListener('input', () => {
      selected = null;
      show($('#claimedPanel'), false);
      show($('#selectedAddress'), false);
      clearTimeout(timer);
      timer = setTimeout(() => lookup(q.value), 250);
    });
  }
  $('#addrSuggestions')?.addEventListener('click', (e) => {
    if (e.target.closest('[data-self-entry]')) {
      const house = ($('#addrQuery').value.match(/^\s*(\d+)/) || [])[1];
      $('#btnSelfEntry').click();
      if (house) $('#seHouse').value = house;
      return;
    }
    const b = e.target.closest('button[data-id]');
    if (b) choose({ address_id: Number(b.dataset.id), address: b.dataset.address, claimed: b.dataset.claimed === '1' });
  });
  $('#btnSelfEntry')?.addEventListener('click', () => selfEntryOn(true));
  $('#btnUseList')?.addEventListener('click', () => selfEntryOn(false));
  $('#btnRequestReview')?.addEventListener('click', () => { if (selected?.address_id) openDispute(selected.address_id, selected.address); });
  $('#regNote')?.addEventListener('input', (e) => { $('#noteCount').textContent = e.target.value.length; });
  initDispute();
}

export function openRegistration() {
  const wrap = openModal('registerModal');
  if (!wrap || !$('#registerForm')) return;
  $('#registerForm').hidden = false;
  show($('#registerSuccess'), false);
  goTo(1);
  if (mode === 'self_entry') initPinMap();
}

function goTo(n) {
  const form = $('#registerForm');
  clearErrors(form);
  if (n > step && step === 1 && !validateAddress()) return;
  if (n > step && step === 2 && !validateDetails()) return;
  step = n;
  const intro = $('#registerIntro');
  if (intro) intro.hidden = n > 1; // the explanation matters once; later steps keep the space for the form
  form.querySelectorAll('[data-step]').forEach((fs) => { fs.hidden = Number(fs.dataset.step) !== n; });
  document.querySelectorAll('[data-step-dot]').forEach((d) => d.classList.toggle('done', Number(d.dataset.stepDot) <= n));
  const first = form.querySelector(`[data-step="${n}"] input, [data-step="${n}"] select, [data-step="${n}"] textarea`);
  if (first) first.focus();
}

/** What to do when a home is not on the list: add it (optionally for approval) or contact the organizer. */
function missingHint() {
  const none = `No matching address in ${esc(C.community.name)}.`;
  if (mode === 'both') {
    return `${none} <button type="button" class="link-button inline" data-self-entry>Add your home and place a pin</button>${C.registration.review_new_addresses ? ' — an organizer approves new addresses before they appear.' : ''}`;
  }
  return C.community.contact_url
    ? `${none} <a href="${esc(C.community.contact_url)}" rel="noopener">Contact the organizer</a> to add your home.`
    : `${none} Contact the organizer to add your home.`;
}

async function lookup(text) {
  const list = $('#addrSuggestions');
  list.innerHTML = '';
  if (!/^\s*\d+\s+\S{2,}/.test(text)) return;
  try {
    const { results } = await api(`/api/addresses/lookup?q=${encodeURIComponent(text)}`);
    list.innerHTML = results.length
      ? results.map((r) => `<li role="option"><button type="button" data-id="${r.id}" data-address="${esc(r.address)}" data-claimed="${r.claimed ? 1 : 0}"><span>${esc(r.address)}</span><small>${esc(r.zone_label || '')}${r.claimed ? ' · claimed' : ''}</small></button></li>`).join('')
      : `<li class="form-caption">${missingHint()}</li>`;
  } catch (e) {
    list.innerHTML = `<li class="form-caption">${esc(friendlyError(e))}</li>`;
  }
}

async function choose(item) {
  $('#addrSuggestions').innerHTML = '';
  $('#addrQuery').value = item.address;
  selected = { address_id: item.address_id, address: item.address };
  const claimed = item.claimed; // from the address lookup; ids are never probed one by one
  show($('#claimedPanel'), claimed);
  const sel = $('#selectedAddress');
  sel.textContent = claimed ? '' : `✅ ${item.address} is available to list.`;
  show(sel, !claimed);
  if (claimed) selected.claimed = true;
}

function selfEntryValues() {
  return {
    house_number: $('#seHouse')?.value.trim() || '',
    street: $('#seStreet')?.value || '',
    unit: $('#seUnit')?.value.trim() || undefined,
    zone_key: $('#seZone')?.value || undefined,
    lat: pinMarker ? Number(pinMarker.getLatLng().lat.toFixed(7)) : null,
    lon: pinMarker ? Number(pinMarker.getLatLng().lng.toFixed(7)) : null,
  };
}

/** "both" mode: either search the address list, or enter the address and place a pin — one at a time. */
function selfEntryOn(on) {
  show($('#selfEntry'), on);
  show($('#lookupField'), !on);
  show($('#btnSelfEntry'), !on);
  show($('#btnUseList'), on);
  clearErrors($('#registerForm'));
  if (on) { selected = null; initPinMap(); $('#seHouse').focus(); } else $('#addrQuery').focus();
}

/** Refusals the organizer can fix (missing or outdated map data) get a contact link. */
const CONTACT_CODES = ['outside_boundary', 'zone_not_claimable', 'zone_not_open', 'ambiguous_zone', 'not_eligible', 'street_not_allowed'];
function addContact(field, code) {
  const el = $('#registerForm').querySelector(`[data-error="${field}"]`);
  if (!el || !C.community.contact_url || !CONTACT_CODES.includes(code)) return;
  const a = document.createElement('a');
  a.href = C.community.contact_url;
  a.rel = 'noopener';
  a.textContent = 'Contact the organizer if your home belongs here.';
  el.append(' ', a);
}

function usingSelfEntry() {
  return mode === 'self_entry' || (mode === 'both' && !$('#selfEntry').classList.contains('hide') && !selected?.address_id);
}

function validateAddress() {
  const form = $('#registerForm');
  if (usingSelfEntry()) {
    const v = selfEntryValues();
    let ok = true;
    if (!/^\d{1,6}[A-Za-z]?$/.test(v.house_number) || !v.street) { setError(form, 'pin', 'Enter your house number and choose your street.'); ok = false; }
    if (v.lat === null) { setError(form, 'pin', 'Tap your house on the map to place the pin.'); ok = false; }
    if (ok) selected = { ...v, address: `${v.house_number} ${v.street}` };
    return ok;
  }
  if (!selected?.address_id) { setError(form, 'address', 'Choose your address from the list.'); return false; }
  if (selected.claimed) { setError(form, 'address', 'This home is already claimed. You can request a review.'); return false; }
  return true;
}

/** The listing: the shared fields, the event type's details and what else the type needs (e.g. its nights). */
function details() {
  const start = $('#regStart').value;
  const end = $('#regEnd').value;
  return {
    start, end, status: $('#regStatus')?.value || 'active',
    note: $('#regNote').value.trim(), details: T.formDetails($('#registerForm')),
    ...T.formExtras($('#registerForm'), { start, end, event: C.event }),
  };
}

function validateDetails() {
  const f = $('#registerForm');
  const d = details();
  let ok = true;
  if (d.end <= d.start) { setError(f, 'end', 'End time must be later than the start time.'); ok = false; }
  for (const [field, message] of Object.entries(T.validateDetails(d.details))) { setError(f, field, message); ok = false; }
  if (Array.isArray(d.windows) && !d.windows.length) { setError(f, 'windows', T.copy.noDays); ok = false; }
  if (d.note.length > 400) { setError(f, 'note', 'Keep the note to 400 characters.'); ok = false; }
  return ok;
}

async function submit() {
  const form = $('#registerForm');
  clearErrors(form);
  const email = $('#regEmail').value.trim();
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setError(form, 'email', 'Enter a valid email address.'); return; }
  const btn = $('#btnSendLink');
  const err = $('#submitError');
  show(err, false);
  btn.disabled = true;
  show($('#powStatus'), true);
  const bar = $('#powBar');
  try {
    const pow = await solvePow('claim', (p) => { bar.style.width = `${Math.round(p * 100)}%`; });
    const address = selected.address_id ? { address_id: selected.address_id, house_number: houseNumberOf(selected.address) } : { house_number: selected.house_number, street: selected.street, unit: selected.unit, lat: selected.lat, lon: selected.lon, zone_key: selected.zone_key };
    const res = await api('/api/auth/request-link', { method: 'POST', body: { email, pow, website: $('#regWebsite')?.value || '', claim: { address, listing: details() } } });
    form.hidden = true;
    const ok = $('#registerSuccess');
    ok.innerHTML = `<b>📬 Check your email.</b><br>We sent a confirmation link for <b>${esc(res.address || selected?.address || '')}</b> to <b>${esc(email)}</b>. Open it and press <b>Confirm</b> within ${res.minutes} minutes to publish your stop.${!selected.address_id && C.registration.review_new_addresses ? ' Because this address is new, an organizer approves it before it appears on the map.' : ''} If someone else confirms this address first, you’ll be offered a review instead.<div class="modal-actions"><button type="button" class="secondary" data-close>Done</button></div>`;
    show(ok, true);
    ok.focus();
  } catch (e) {
    if (e instanceof ApiError && e.code === 'already_claimed') {
      selected = { ...selected, address_id: e.body.error.address_id || selected.address_id, claimed: true };
      goTo(1);
      show($('#claimedPanel'), true);
      show($('#selectedAddress'), false);
      return;
    }
    if (e instanceof ApiError && e.fields) {
      for (const [k, v] of Object.entries(e.fields)) setError(form, k, v);
    }
    if (e instanceof ApiError && ['outside_boundary', 'zone_mismatch', 'ambiguous_zone', 'pin_collision', 'street_not_allowed', 'zone_not_claimable', 'zone_not_open', 'unknown_zone', 'not_eligible', 'invalid_address', 'self_entry_disabled'].includes(e.code)) {
      goTo(1);
      const field = usingSelfEntry() ? 'pin' : 'address';
      setError(form, field, e.message);
      addContact(field, e.code);
      return;
    }
    err.textContent = friendlyError(e);
    show(err, true);
  } finally {
    btn.disabled = false;
    show($('#powStatus'), false);
    bar.style.width = '0';
  }
}

function initPinMap() {
  if (pinMap || !window.L || !$('#pinMap')) return;
  pinMap = L.map('pinMap', { scrollWheelZoom: false, zoomSnap: 0.25 });
  L.tileLayer(C.map.tiles.url, { maxZoom: C.map.tiles.max_zoom, attribution: esc(C.map.tiles.attribution), referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(pinMap);
  if (C.map.boundary) L.geoJSON(C.map.boundary, { style: { color: T.mapColor || '#f07936', weight: 2, fillOpacity: 0.04 }, interactive: false }).addTo(pinMap);
  const b = C.map.bbox;
  const fit = () => {
    pinMap.invalidateSize();
    if (b) pinMap.fitBounds([[b.minLat, b.minLon], [b.maxLat, b.maxLon]], { padding: [12, 12] }); else if (C.map.center) pinMap.setView([C.map.center.lat, C.map.center.lon], C.map.zoom);
  };
  fit();
  requestAnimationFrame(fit); // the sheet may still be laying out when the map is created
  pinMap.on('click', (e) => {
    if (pinMarker) pinMarker.setLatLng(e.latlng);
    else pinMarker = L.marker(e.latlng, { draggable: true, keyboard: true, title: 'Your house' }).addTo(pinMap);
    setError($('#registerForm'), 'pin', '');
  });
  setTimeout(() => pinMap.invalidateSize(), 50);
}

/* ---------------- Dispute / report ---------------- */
let disputeAddressId = null;
let disputeHouse = '';

const DISPUTE_TEXT = {
  claimed: { title: 'Ask the organizer to review', after: ' is already claimed. Tell the organizer why you think this is a mistake. They review requests by hand; nothing changes automatically and the current owner is never revealed.' },
  report: { title: 'Report this listing', after: ': tell the organizer what is wrong with this listing (wrong address, unsafe or offensive details). They review reports by hand; nothing changes automatically.' },
};

/** @param {'claimed'|'report'} mode claimed = "this home is already claimed" review; report = a listing on the map */
export function openDispute(addressId, address, mode = 'claimed') {
  disputeAddressId = addressId;
  disputeHouse = houseNumberOf(address);
  const text = DISPUTE_TEXT[mode] || DISPUTE_TEXT.claimed;
  $('#disputeTitle').textContent = text.title;
  const intro = $('#disputeIntro');
  const b = document.createElement('b');
  b.id = 'disputeAddress';
  b.textContent = address;
  intro.replaceChildren(b, document.createTextNode(text.after));
  $('#disputeForm').hidden = false;
  show($('#disputeSuccess'), false);
  openModal('disputeModal');
}

function initDispute() {
  const form = $('#disputeForm');
  if (!form) return;
  $('#dReason').addEventListener('input', (e) => { $('#dCount').textContent = e.target.value.length; });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const reason = $('#dReason').value.trim();
    const email = $('#dEmail').value.trim();
    if (reason.length < 200) { setError(form, 'reason', `Please explain in at least 200 characters (you have ${reason.length}).`); return; }
    const err = $('#dError');
    show(err, false);
    show($('#dPow'), true);
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      const pow = await solvePow('dispute', (p) => { $('#dPowBar').style.width = `${Math.round(p * 100)}%`; });
      const res = await api('/api/disputes', { method: 'POST', body: { address_id: disputeAddressId, house_number: disputeHouse, reporter_email: email, reason, pow, website: $('#dWebsite')?.value || '' } });
      form.hidden = true;
      const ok = $('#disputeSuccess');
      ok.innerHTML = `<b>🎟️ Request ${esc(res.ticket)} sent.</b><br>${esc(res.message)}<div class="modal-actions"><button type="button" class="secondary" data-close>Close</button></div>`;
      show(ok, true);
      ok.focus();
    } catch (ex) {
      if (ex instanceof ApiError && ex.fields) for (const [k, v] of Object.entries(ex.fields)) setError(form, k, v);
      err.textContent = friendlyError(ex);
      show(err, true);
    } finally {
      btn.disabled = false;
      show($('#dPow'), false);
    }
  });
}
