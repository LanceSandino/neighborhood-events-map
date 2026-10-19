/**
 * @module assets/js/register
 * @description "List my house" bottom sheet (address → stop details → email + PoW → link sent) and the
 *     "already claimed → request a review" dispute sheet. The server is the eligibility authority;
 *     this file only collects input, solves PoW in a worker and shows friendly results.
 * @dependencies lib/api.js, lib/pow.js, lib/modal.js, Leaflet (optional, for the self-entry pin map)
 */
import { api, ApiError, friendlyError } from './lib/api.js';
import { solvePow } from './lib/pow.js';
import { openModal } from './lib/modal.js';
import { esc } from './lib/render.js';

let C = null;
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
  mode = C.registration.address_mode;
  const form = $('#registerForm');
  if (!form) return;
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
    const b = e.target.closest('button[data-id]');
    if (b) choose({ address_id: Number(b.dataset.id), address: b.dataset.address, claimed: b.dataset.claimed === '1' });
  });
  $('#btnSelfEntry')?.addEventListener('click', () => { show($('#selfEntry'), true); initPinMap(); $('#seHouse').focus(); });
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
  form.querySelectorAll('[data-step]').forEach((fs) => { fs.hidden = Number(fs.dataset.step) !== n; });
  document.querySelectorAll('[data-step-dot]').forEach((d) => d.classList.toggle('done', Number(d.dataset.stepDot) <= n));
  const first = form.querySelector(`[data-step="${n}"] input, [data-step="${n}"] select, [data-step="${n}"] textarea`);
  if (first) first.focus();
}

async function lookup(text) {
  const list = $('#addrSuggestions');
  list.innerHTML = '';
  if (!/^\s*\d+\s+\S{2,}/.test(text)) return;
  try {
    const { results } = await api(`/api/addresses/lookup?q=${encodeURIComponent(text)}`);
    list.innerHTML = results.length
      ? results.map((r) => `<li role="option"><button type="button" data-id="${r.id}" data-address="${esc(r.address)}" data-claimed="${r.claimed ? 1 : 0}"><span>${esc(r.address)}</span><small>${esc(r.zone_label || '')}${r.claimed ? ' · claimed' : ''}</small></button></li>`).join('')
      : `<li class="form-caption">No matching address in ${esc(C.community.name)}.${mode === 'both' ? ' You can enter it and place a pin instead.' : ' Contact the organizer if your home is missing.'}</li>`;
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

function details() {
  const f = $('#registerForm');
  const checked = (n) => !!f.querySelector(`input[name="${n}"]`)?.checked;
  return {
    start: $('#regStart').value, end: $('#regEnd').value, scare_level: $('#regScare').value, status: $('#regStatus').value,
    candy: checked('candy'), nonfood: checked('nonfood'), adult_drinks: checked('adult_drinks'), low_noise: checked('low_noise'),
    jump_scares: checked('jump_scares'), flashing_lights: checked('flashing_lights'), loud_audio: checked('loud_audio'),
    stroller_friendly: checked('stroller_friendly'), note: $('#regNote').value.trim(),
  };
}

function validateDetails() {
  const f = $('#registerForm');
  const d = details();
  let ok = true;
  if (d.end <= d.start) { setError(f, 'end', 'End time must be later than the start time.'); ok = false; }
  if (!d.candy && !d.nonfood && !d.adult_drinks) { setError(f, 'offerings', 'Choose at least one: candy, non-food treats or adult refreshments.'); ok = false; }
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
    const address = selected.address_id ? { address_id: selected.address_id } : { house_number: selected.house_number, street: selected.street, unit: selected.unit, lat: selected.lat, lon: selected.lon, zone_key: selected.zone_key };
    const res = await api('/api/auth/request-link', { method: 'POST', body: { email, pow, website: $('#regWebsite')?.value || '', claim: { address, listing: details() } } });
    form.hidden = true;
    const ok = $('#registerSuccess');
    ok.innerHTML = `<b>📬 Check your email.</b><br>We sent a confirmation link for <b>${esc(res.address || selected?.address || '')}</b> to <b>${esc(email)}</b>. Open it and press <b>Confirm</b> within ${res.minutes} minutes to publish your stop. If someone else confirms this address first, you’ll be offered a review instead.<div class="modal-actions"><button type="button" class="secondary" data-close>Done</button></div>`;
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
    if (e instanceof ApiError && ['outside_boundary', 'zone_mismatch', 'ambiguous_zone', 'pin_collision', 'street_not_allowed', 'zone_not_claimable', 'unknown_zone', 'not_eligible', 'invalid_address', 'self_entry_disabled'].includes(e.code)) {
      goTo(1);
      setError(form, usingSelfEntry() ? 'pin' : 'address', e.message);
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
  pinMap = L.map('pinMap', { scrollWheelZoom: false });
  L.tileLayer(C.map.tiles.url, { maxZoom: C.map.tiles.max_zoom, attribution: esc(C.map.tiles.attribution), referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(pinMap);
  if (C.map.boundary) L.geoJSON(C.map.boundary, { style: { color: '#f07936', weight: 2, fillOpacity: 0.04 }, interactive: false }).addTo(pinMap);
  const b = C.map.bbox;
  if (b) pinMap.fitBounds([[b.minLat, b.minLon], [b.maxLat, b.maxLon]]); else if (C.map.center) pinMap.setView([C.map.center.lat, C.map.center.lon], C.map.zoom);
  pinMap.on('click', (e) => {
    if (pinMarker) pinMarker.setLatLng(e.latlng);
    else pinMarker = L.marker(e.latlng, { draggable: true, keyboard: true, title: 'Your house' }).addTo(pinMap);
    setError($('#registerForm'), 'pin', '');
  });
  setTimeout(() => pinMap.invalidateSize(), 50);
}

/* ---------------- Dispute / report ---------------- */
let disputeAddressId = null;

export function openDispute(addressId, address) {
  disputeAddressId = addressId;
  $('#disputeAddress').textContent = address;
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
      const res = await api('/api/disputes', { method: 'POST', body: { address_id: disputeAddressId, reporter_email: email, reason, pow, website: $('#dWebsite')?.value || '' } });
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
