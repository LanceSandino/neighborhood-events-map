/**
 * @module assets/js/home
 * @description Public family page controller: filters (localStorage), synchronized list + Leaflet
 *     map, favorites, selected-home panel, suggested stop order, info/house sheets, and the
 *     registration/dispute flows (register.js). Works without the map; the list is always usable.
 * @dependencies lib/*, register.js, Leaflet (global L, optional)
 */
import { api } from './lib/api.js';
import { createStore } from './lib/store.js';
import { DEFAULT_FILTERS, matches, activeFilterCount, normalizeFilters, choiceCounts, isLowSensory } from './lib/filters.js';
import { nowInZone, time12 } from './lib/time.js';
import { cardHtml, selectedHtml, pinHtml, esc, zoneStyle } from './lib/render.js';
import { planStops, applyOrder, moveStop, suggestNearby } from './lib/route.js';
import { initModals, openModal, closeModal, toast } from './lib/modal.js';
import { initRegistration, openRegistration, openDispute } from './register.js';

const boot = JSON.parse(document.getElementById('boot').textContent);
const C = boot.community;
let homes = boot.homes;
const zoneLabels = Object.fromEntries(C.zones.map((z) => [z.key, z.label]));
const store = createStore(C.community.id, C.event.date.slice(0, 4));
let filters = { ...DEFAULT_FILTERS, ...normalizeFilters(store.get().filters) };
// A shared link (?f=<filters>&stops=<ids>&start=<id>) opens exactly that view.
const shared = readSharedLink();
if (shared.filters) { filters = { ...DEFAULT_FILTERS, ...normalizeFilters(shared.filters) }; store.set({ filters }); }
let activeId = null;
let routeNumbers = new Map();
let currentPlan = [];
let manualOrder = false;
let here = null; // the visitor's location for "start from my location": memory only, never stored or sent
let locating = false;

const $ = (sel) => document.querySelector(sel);
const ctx = () => {
  const n = nowInZone(C.event.timezone);
  return { today: n.date, eventDate: C.event.date, nowMinutes: n.minutes, zoneLabels, saved: store.get().saved, areas: C.routing.groups || [] };
};
const visible = () => homes.filter((h) => matches(h, filters, ctx()));
/** Handing out treats now or later tonight; paused, out of candy and done homes are not. */
const isOpen = (h) => h.status === 'active';
let showClosed = false;
const zoneLayers = new Map();
/** The device setting, or "Reduce motion" chosen in Appearance. */
const reducedMotion = () => document.documentElement.dataset.motion === 'reduce' || matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------------- Map ---------------- */
let map = null;
const markers = new Map();

function initMap() {
  if (!window.L) return;
  try {
    map = L.map('map', { zoomControl: false, scrollWheelZoom: false, tap: true, zoomSnap: 0.25 });
    L.tileLayer(C.map.tiles.url, { maxZoom: C.map.tiles.max_zoom, attribution: esc(C.map.tiles.attribution), referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
    if (C.map.boundary) {
      L.geoJSON(C.map.boundary, { style: { color: '#f07936', weight: 2, fillColor: '#f07936', fillOpacity: 0.05 }, interactive: false }).addTo(map);
    }
    for (const z of C.zones) {
      if (!z.geometry) continue;
      const layer = L.geoJSON(z.geometry, { style: zoneStyle(z, null), interactive: false }).addTo(map);
      layer.bindTooltip(esc(z.label) + (z.status === 'coming_soon' ? ' · coming soon' : ''), { permanent: true, direction: 'center', className: 'boundary-label' });
      zoneLayers.set(z.key, { zone: z, layer });
    }
    fitAll();
    if (filters.zone && filters.zone !== 'all') highlightZone(filters.zone);
    map.on('click', () => selectHome(null));
    $('#mapFallback').hidden = true;
  } catch (e) {
    map = null;
    $('#mapFallback').textContent = 'The map could not load. Use the list of homes instead.';
  }
}

/** Selecting a phase/zone or a walking area outlines it, shades the rest and zooms to it; "all" restores the overview. */
function highlightZone(key, { fit = true } = {}) {
  if (!map || !zoneLayers.size) return;
  const keys = key && key.startsWith('area:') ? (C.routing.groups[Number(key.slice(5))] || []) : (key && key !== 'all' ? [key] : []);
  const selected = new Set(keys.filter((k) => zoneLayers.has(k)));
  for (const { zone, layer } of zoneLayers.values()) layer.setStyle(zoneStyle(zone, selected));
  if (!fit) return;
  if (selected.size) {
    const bounds = L.latLngBounds([]);
    for (const k of selected) bounds.extend(zoneLayers.get(k).layer.getBounds());
    map.fitBounds(bounds, { padding: [30, 30], animate: !reducedMotion() });
  } else fitAll();
}

function fitAll() {
  if (!map) return;
  const b = C.map.bbox;
  if (b) map.fitBounds([[b.minLat, b.minLon], [b.maxLat, b.maxLon]], { padding: [24, 24] });
  else if (homes.length) map.fitBounds(homes.map((h) => [h.lat, h.lon]), { padding: [40, 40], maxZoom: 17 });
  else if (C.map.center) map.setView([C.map.center.lat, C.map.center.lon], C.map.zoom);
  else map.setView([39.5, -98.35], 4);
}

function renderPins(visibleIds) {
  if (!map) return;
  for (const [id, m] of markers) if (!homes.some((h) => h.id === id)) { m.remove(); markers.delete(id); }
  for (const h of homes) {
    const icon = L.divIcon({ className: `pin-icon${activeId === h.id ? ' active' : ''}`, html: pinHtml(h, { dim: !visibleIds.has(h.id), number: routeNumbers.get(h.id) }), iconSize: [37, 37], iconAnchor: [5, 36] });
    let m = markers.get(h.id);
    if (!m) {
      m = L.marker([h.lat, h.lon], { icon, keyboard: true, title: h.address, alt: h.address, riseOnHover: true });
      m.on('click', (e) => { L.DomEvent.stopPropagation(e); selectHome(h.id); });
      m.addTo(map);
      markers.set(h.id, m);
    } else {
      m.setIcon(icon);
      m.setLatLng([h.lat, h.lon]);
    }
    m.setZIndexOffset(visibleIds.has(h.id) ? (activeId === h.id ? 1000 : 100) : 0);
  }
}

/* ---------------- List + stats ---------------- */
function render() {
  const c = ctx();
  const vis = visible();
  const ids = new Set(vis.map((h) => h.id));
  const saved = store.get().saved;
  const open = vis.filter(isOpen);
  const closed = vis.filter((h) => !isOpen(h));
  const card = (h) => cardHtml(h, c, { saved: saved.includes(h.id), active: activeId === h.id });
  const toggle = closed.length
    ? `<li class="closed-toggle"><button type="button" class="link-button" id="btnShowClosed" aria-expanded="${showClosed}">${showClosed ? 'Hide' : 'Show'} ${closed.length} ${closed.length === 1 ? 'home' : 'homes'} not handing out right now</button></li>`
    : '';
  $('#homeCards').innerHTML = open.length || closed.length
    ? open.map(card).join('') + (open.length ? '' : '<li class="empty">No matching homes are handing out right now.</li>') + toggle + (showClosed ? closed.map(card).join('') : '')
    : `<li class="empty">${homes.length ? 'No homes match these filters. Try a different area or time.' : 'No homes are listed yet. Residents can add theirs with “List your home”.'}</li>`;
  $('#listCount').textContent = `${open.length} showing`;
  const view = store.get().view === 'compact' ? 'compact' : 'cards';
  $('#homeCards').classList.toggle('compact', view === 'compact');
  for (const b of document.querySelectorAll('[data-view]')) b.setAttribute('aria-pressed', String(b.dataset.view === view));
  const count = activeFilterCount({ ...filters, q: '' });
  $('#filterCount').textContent = String(count);
  $('#filterCount').classList.toggle('hide', count === 0);
  $('#btnFilters').setAttribute('aria-label', count ? `Filters, ${count} active` : 'Filters');
  $('#savedCount').textContent = saved.filter((id) => homes.some((h) => h.id === id)).length;
  $('#statHomes').textContent = homes.length;
  if ($('#statCandy')) $('#statCandy').textContent = homes.filter((h) => h.candy).length;
  if ($('#statAdult')) $('#statAdult').textContent = homes.filter((h) => h.adult_drinks).length;
  $('#statGentle').textContent = homes.filter(isLowSensory).length;
  renderChoiceCounts(c);
  for (const btn of document.querySelectorAll('[data-filter]')) btn.setAttribute('aria-pressed', String(!!filters[btn.dataset.filter]));
  $('#btnReset').classList.toggle('hide', activeFilterCount(filters) === 0);
  renderPins(ids);
  if (activeId && !homes.some((h) => h.id === activeId)) selectHome(null);
}

/** Each filter and zone choice shows how many homes handing out it would list (with the other filters kept). */
function renderChoiceCounts(c) {
  const buttons = [...document.querySelectorAll('[data-filter]')];
  const zoneSel = $('#zone');
  const counts = choiceCounts(homes, filters, c, { toggles: buttons.map((b) => b.dataset.filter), zones: zoneSel ? [...zoneSel.options].map((o) => o.value) : [], keep: isOpen });
  for (const b of buttons) {
    let s = b.querySelector('.fcount');
    if (!s) { s = document.createElement('span'); s.className = 'fcount'; s.setAttribute('aria-hidden', 'true'); b.append(s); }
    s.textContent = String(counts.toggles[b.dataset.filter]);
  }
  if (zoneSel) for (const o of zoneSel.options) o.textContent = `${o.dataset.label || o.textContent} · ${counts.zones[o.value]}`;
}

function setFilters(patch) {
  filters = { ...filters, ...patch };
  store.set({ filters });
  render();
}

function selectHome(id, { pan = true } = {}) {
  activeId = id;
  const panel = $('#mapSelected');
  const h = homes.find((x) => x.id === id);
  if (!h) {
    panel.classList.remove('open');
    panel.innerHTML = '';
  } else {
    panel.innerHTML = selectedHtml(h, ctx(), store.get().saved.includes(h.id));
    panel.classList.add('open');
    if (map && pan) map.panTo([h.lat, h.lon], { animate: !reducedMotion() });
    const card = document.querySelector(`.home-card[data-id="${h.id}"]`);
    if (card && card.scrollIntoView && innerWidth > 660) card.scrollIntoView({ block: 'nearest' });
    // Phones show the map above the list: bring the map (and the preview on it) into view.
    if (innerWidth <= 660 && pan) {
      const pane = $('#mapPane');
      const r = pane.getBoundingClientRect();
      if (r.top < 0 || r.bottom > innerHeight) pane.scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' });
    }
  }
  render();
}

/* ---------------- Shareable links ---------------- */
function readSharedLink() {
  const q = new URLSearchParams(location.search);
  const out = {};
  try {
    if (q.get('f')) {
      const json = JSON.parse(atob(q.get('f').replace(/-/g, '+').replace(/_/g, '/')));
      out.filters = Object.fromEntries(Object.entries(json).filter(([k]) => k in DEFAULT_FILTERS));
    }
  } catch { /* ignore a damaged link */ }
  const ids = (q.get('stops') || '').split('.').map(Number).filter((n) => Number.isInteger(n) && n > 0).slice(0, 60);
  if (ids.length) { out.stops = ids; out.start = Number(q.get('start')) || null; out.order = q.get('o') === '1'; }
  return out;
}

/** Link to this exact view: the active filters and, for a route, its stops and starting point. */
function shareLink({ withRoute = true } = {}) {
  const url = new URL('/', location.origin);
  const changed = Object.fromEntries(Object.entries(filters).filter(([k, v]) => JSON.stringify(v) !== JSON.stringify(DEFAULT_FILTERS[k])));
  if (Object.keys(changed).length) url.searchParams.set('f', btoa(JSON.stringify(changed)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''));
  if (withRoute && currentPlan.length) {
    url.searchParams.set('stops', currentPlan.flatMap((g) => g.stops.map((h) => h.id)).join('.'));
    const start = Number($('#routeStart').value) || null; // "my location" is never put in a link
    if (start) url.searchParams.set('start', String(start));
    if (manualOrder) url.searchParams.set('o', '1');
  }
  return url.toString();
}

/* ---------------- Route (suggested stop order) ---------------- */
/** Builds the stop list (suggested order, or the visitor's own order) and shows it; opens the sheet unless it is open. */
function showRoute({ open = true } = {}) {
  const { saved, routeAll } = store.get();
  const skippedIds = new Set(store.get().skipped);
  const vis = visible();
  const fromLink = shared.stops ? homes.filter((h) => shared.stops.includes(h.id)) : null;
  const linked = Boolean(fromLink && fromLink.length);
  // Saved homes, or (no favourites, or "All matching" chosen) every home matching the filters.
  const useSaved = !linked && saved.length > 0 && !routeAll;
  const pool = linked ? fromLink : (useSaved ? homes.filter((h) => saved.includes(h.id)) : vis);
  // Homes that are paused, out of candy or done for the night never go on a route; skipped ones neither.
  const handingOut = pool.filter(isOpen);
  const chosen = handingOut.filter((h) => !skippedIds.has(h.id));
  const skipped = pool.length - handingOut.length;
  const userSkipped = handingOut.length - chosen.length;
  const startSel = $('#routeStart');
  const pref = (fromLink && shared.start) || store.get().start || '';
  const startId = Number(pref) || null;
  startSel.innerHTML = '<option value="">The first stop in each area</option>'
    + ('geolocation' in navigator && window.isSecureContext ? `<option value="here" ${pref === 'here' ? 'selected' : ''}>📍 My current location</option>` : '')
    + chosen.map((h) => `<option value="${h.id}" ${h.id === startId ? 'selected' : ''}>${esc(h.address)}</option>`).join('');
  // "My current location" is remembered as a choice, but the position never is: ask for it again (once).
  if (pref === 'here' && !here && !locating) startFromHere();
  const start = pref === 'here' && here ? { id: null, lat: here.lat, lon: here.lon } : chosen.find((h) => h.id === startId) || null;
  const order = fromLink && fromLink.length ? (shared.order ? shared.stops : null) : store.get().order;
  manualOrder = Boolean(order && order.length);
  currentPlan = applyOrder(planStops(chosen, { groups: C.routing.groups, zonesEnabled: C.zones.length > 0, start }), order);
  $('#routeSub').textContent = (linked
    ? 'A stop list someone shared with you. Save the ones you like with ♡.'
    : useSaved
    ? `Your saved stops, in ${manualOrder ? 'your own' : 'a suggested'} order. Separate areas are listed as separate trips.`
    : `Homes matching your filters${saved.length ? '' : ' (save favorites ♡ to choose your own)'}. Skip any you don’t want with ✕. Separate areas are listed as separate trips.`)
    + (skipped ? ` ${skipped} ${skipped === 1 ? 'home is' : 'homes are'} left out because ${skipped === 1 ? 'it is' : 'they are'} not handing out right now.` : '')
    + (pref === 'here' && !here ? ' 📍 Finding your location…' : '');
  let n = 0;
  routeNumbers = new Map();
  const move = (h, i, last, dir) => `<button type="button" class="move" data-move="${dir}" data-id="${h.id}" aria-label="Move ${esc(h.address)} ${dir < 0 ? 'earlier' : 'later'}" ${(dir < 0 ? i === 0 : i === last) ? 'disabled' : ''}>${dir < 0 ? '↑' : '↓'}</button>`;
  const source = !linked && saved.length
    ? `<div class="route-source" role="group" aria-label="Stops to include"><button type="button" data-route-all="0" aria-pressed="${useSaved}">🧡 Saved (${saved.length})</button><button type="button" data-route-all="1" aria-pressed="${!useSaved}">All matching (${vis.filter(isOpen).length})</button></div>`
    : '';
  const ideas = useSaved && chosen.length
    ? suggestNearby(chosen, vis.filter((h) => isOpen(h) && !skippedIds.has(h.id)), { groups: C.routing.groups, zonesEnabled: C.zones.length > 0 })
    : [];
  const extras = (ideas.length ? `<div class="route-suggest"><h3 class="route-group-title">Nearby, not on your list</h3><ul>${ideas.map(({ home: h, meters }) => `<li><div class="route-text"><b>${esc(h.address)}</b><p>About ${Math.max(10, Math.round(meters / 10) * 10)} m from a stop · ${time12(h.start)}–${time12(h.end)}</p></div><button type="button" class="secondary add-stop" data-add-stop="${h.id}" aria-label="Add ${esc(h.address)} to my stops">+ Add</button></li>`).join('')}</ul></div>` : '')
    + (userSkipped ? `<p class="form-caption route-order">${userSkipped} skipped. <button type="button" class="link-button" data-unskip>Bring them back</button></p>` : '');
  $('#routeItems').innerHTML = source + (currentPlan.length ? (manualOrder ? '<p class="form-caption route-order">Your own order. <button type="button" class="link-button" id="btnResetOrder">Use the suggested order</button></p>' : '')
    + currentPlan.map((g, gi) => {
    const label = tripLabel(g, gi);
    return `${label ? `<h3 class="route-group-title">${currentPlan.length > 1 ? `Trip ${gi + 1}: ` : ''}${esc(label)}</h3>` : ''}
      <ol class="route-items" tabindex="0" aria-label="${esc(label || 'Stops')}: ${g.stops.length} stops">${g.stops.map((h, i) => { n += 1; routeNumbers.set(h.id, n); return `<li class="route-item"><span class="route-index" aria-hidden="true">${n}</span><div class="route-text"><b>${esc(h.address)}</b><p>${time12(h.start)}–${time12(h.end)}${h.low_noise ? ' · Gentle' : ''}${h.jump_scares || h.scare_level === 'intense' ? ' · Intense scares' : ''}</p></div><span class="route-move">${g.stops.length > 1 ? move(h, i, g.stops.length - 1, -1) + move(h, i, g.stops.length - 1, 1) : ''}<button type="button" class="move skip" data-skip="${h.id}" aria-label="Skip ${esc(h.address)}">✕</button></span></li>`; }).join('')}</ol>
      ${g.truncated ? '<p class="form-caption">Showing the first 20 stops in this area.</p>' : ''}
      ${C.routing.provider && g.stops.length > 1 ? `<button type="button" class="secondary" data-provider-path="${gi}">Show walking path for this trip</button>` : ''}`;
  }).join('') + (currentPlan.length > 1 ? '<div class="notice">🚧 These areas are separate trips. The site does not suggest crossing between them; check for safe sidewalks, crossings and gate rules.</div>' : '')
    : '<div class="empty">No stops yet. Save some homes ♡ or change your filters.</div>') + extras;
  $('#routeLink').value = shareLink();
  render();
  if (open && !$('#routeModal').classList.contains('open')) openModal('routeModal');
}

/** Moves a stop within its trip and keeps the new order (on this device, or in the shared view). */
function reorder(id, delta) {
  const order = moveStop(currentPlan, id, delta);
  if (shared.stops) { shared.stops = order; shared.order = true; } else store.set({ order });
  showRoute({ open: false });
  const again = document.querySelector(`#routeItems [data-move="${delta}"][data-id="${id}"]:not([disabled])`)
    || document.querySelector(`#routeItems [data-move="${-delta}"][data-id="${id}"]`);
  if (again) again.focus();
}

/** "Start from my location": asks for the position once; it stays in memory on this device. */
function startFromHere() {
  if (!navigator.geolocation || !window.isSecureContext) { store.set({ start: '' }); return; }
  locating = true;
  const done = () => { locating = false; if ($('#routeModal').classList.contains('open')) showRoute({ open: false }); };
  navigator.geolocation.getCurrentPosition((pos) => {
    here = { lat: pos.coords.latitude, lon: pos.coords.longitude };
    showMe();
    done();
  }, () => {
    store.set({ start: '' });
    done();
    toast('Could not get your location. Choose a starting home instead.');
  }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
}

/** A trip's name: the walking area's name ("Main (1–3)") when it has one, else its zones in map order. */
function tripLabel(g, gi) {
  const area = g.key.startsWith('group-') ? document.querySelector(`#zone option[value="area:${g.key.slice(6)}"]`) : null;
  if (area) return area.dataset.label;
  const order = C.zones.map((z) => z.key);
  if (g.zones.length) return [...g.zones].sort((a, b) => order.indexOf(a) - order.indexOf(b)).map((z) => zoneLabels[z] || z).join(', ');
  return currentPlan.length > 1 ? `Area ${gi + 1}` : '';
}

function routeText() {
  let n = 0;
  return `${C.community.name} Halloween — my stops (${manualOrder ? 'my order' : 'suggested order'}, not walking directions)\n` + currentPlan.map((g, gi) =>
    (currentPlan.length > 1 ? `\nTrip ${gi + 1}${tripLabel(g, gi) ? ` (${tripLabel(g, gi)})` : ''}\n` : '')
    + g.stops.map((h) => `${++n}. ${h.address} (${time12(h.start)}–${time12(h.end)})`).join('\n')).join('\n')
    + `\n\nOpen on the map: ${shareLink()}`;
}

let pathLayer = null;
async function showProviderPath(gi) {
  const g = currentPlan[gi];
  try {
    const { route } = await api('/api/routes/plan', { method: 'POST', body: { stops: g.stops.map((h) => h.id) } });
    if (map) {
      if (pathLayer) pathLayer.remove();
      pathLayer = L.geoJSON(route.geometry, { style: { color: '#302a40', weight: 4, opacity: 0.75 } }).addTo(map);
      map.fitBounds(pathLayer.getBounds(), { padding: [30, 30] });
    }
    toast(`${route.notice} About ${Math.round(route.distance_m / 100) / 10} km.`, 6000);
  } catch (e) {
    toast(e.message || 'No walking path is available. Use the suggested stop order.');
  }
}

/**
 * Copies text on every browser: the Clipboard API where it is allowed (HTTPS), otherwise a hidden
 * textarea with execCommand('copy'), which also works on iPhone over plain HTTP.
 */
async function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    try { await navigator.clipboard.writeText(text); return true; } catch { /* fall through */ }
  }
  const area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  area.className = 'copy-buffer';
  document.body.appendChild(area);
  area.select();
  area.setSelectionRange(0, text.length);
  let ok = false;
  try { ok = document.execCommand('copy'); } catch { ok = false; }
  area.remove();
  return ok;
}

/** Closes the stop list and shows the numbered stops on the map. */
function showRouteOnMap() {
  closeModal(document.getElementById('routeModal'));
  const stops = currentPlan.flatMap((g) => g.stops);
  if (map && stops.length) {
    const fromHere = $('#routeStart').value === 'here' && here;
    if (fromHere) showMe();
    map.fitBounds([...stops.map((h) => [h.lat, h.lon]), ...(fromHere ? [[here.lat, here.lon]] : [])], { padding: [40, 40], maxZoom: 18 });
    if (innerWidth <= 660) $('#mapPane').scrollIntoView({ block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth' });
  }
}

/* ---------------- Location (stays on this device) ---------------- */
let meMarker = null;
function showMe() {
  if (!map || !here) return;
  const ll = [here.lat, here.lon];
  if (meMarker) meMarker.setLatLng(ll); else meMarker = L.circleMarker(ll, { radius: 7, color: '#fff', weight: 3, fillColor: '#3b82f6', fillOpacity: 1 }).addTo(map).bindTooltip('You are here (not shared)');
}
function locate() {
  if (!navigator.geolocation) { toast('Location is not available in this browser.'); return; }
  navigator.geolocation.getCurrentPosition((pos) => {
    here = { lat: pos.coords.latitude, lon: pos.coords.longitude };
    showMe();
    if (map) map.setView([here.lat, here.lon], Math.max(map.getZoom(), 17));
  }, () => toast('Could not get your location. The map works without it.'), { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
}

/* ---------------- Wiring ---------------- */
function actions(action) {
  switch (action) {
    case 'explore': document.getElementById('explore').scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth' }); return true;
    case 'route': showRoute(); return true;
    case 'register': openRegistration(); return true;
    case 'info': openModal('infoModal'); return true;
    case 'house': openModal('houseModal'); return true;
    default: return false;
  }
}

function bind() {
  initModals();
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-action]');
    if (a) {
      // An action chosen inside a sheet (e.g. "+ List my house" in My house) replaces that sheet.
      const host = a.closest('.modal-wrap.open');
      if (host && a.dataset.action !== 'explore') closeModal(host);
      if (actions(a.dataset.action)) { e.preventDefault(); return; }
    }
    const save = e.target.closest('[data-save]');
    if (save) {
      e.stopPropagation();
      const id = Number(save.dataset.save);
      store.toggleSaved(id);
      if (activeId === id) selectHome(id, { pan: false }); else render();
      return;
    }
    const pathBtn = e.target.closest('[data-provider-path]');
    if (pathBtn) { showProviderPath(Number(pathBtn.dataset.providerPath)); return; }
    const report = e.target.closest('[data-report]');
    if (report) {
      const h = homes.find((x) => x.id === Number(report.dataset.report));
      if (h) openDispute(h.id, h.address, 'report');
      return;
    }
    if (e.target.closest('[data-close-selected]')) { selectHome(null); return; }
    if (e.target.closest('#btnShowClosed')) { showClosed = !showClosed; render(); return; }
    const viewBtn = e.target.closest('[data-view]');
    if (viewBtn) { store.set({ view: viewBtn.dataset.view }); render(); return; }
    const card = e.target.closest('.home-card[data-id]');
    if (card) selectHome(Number(card.dataset.id));
  });
  $('#homeCards').addEventListener('keydown', (e) => {
    const card = e.target.closest('.home-card[data-id]');
    if (card && (e.key === 'Enter' || e.key === ' ') && e.target === card) { e.preventDefault(); selectHome(Number(card.dataset.id)); }
  });
  for (const btn of document.querySelectorAll('[data-filter]')) btn.addEventListener('click', () => setFilters({ [btn.dataset.filter]: !filters[btn.dataset.filter] }));
  const more = $('#btnFilters');
  more.addEventListener('click', () => {
    const open = !$('#moreFilters').classList.contains('open');
    $('#moreFilters').classList.toggle('open', open);
    more.setAttribute('aria-expanded', String(open));
  });
  const search = $('#search');
  search.value = filters.q || '';
  search.addEventListener('input', () => setFilters({ q: search.value }));
  const zone = $('#zone');
  if (zone) {
    zone.value = filters.zone || 'all';
    zone.addEventListener('change', () => { setFilters({ zone: zone.value }); highlightZone(zone.value); });
  }
  const time = $('#time');
  time.value = filters.time || 'any';
  time.addEventListener('change', () => setFilters({ time: time.value }));
  $('#btnReset').addEventListener('click', () => {
    filters = { ...DEFAULT_FILTERS };
    store.set({ filters });
    search.value = ''; if (zone) zone.value = 'all'; time.value = 'any';
    highlightZone('all');
    render();
  });
  $('#btnFit').addEventListener('click', () => { selectHome(null); fitAll(); });
  $('#btnZoomIn').addEventListener('click', () => map && map.zoomIn());
  $('#btnZoomOut').addEventListener('click', () => map && map.zoomOut());
  $('#btnLocate').addEventListener('click', locate);
  $('#routeStart').addEventListener('change', (e) => {
    // A new start means a new suggested order.
    shared.start = null; shared.order = false;
    store.set({ start: e.target.value, order: [] });
    showRoute({ open: false }); // asks for the location when "My current location" is chosen
  });
  $('#routeItems').addEventListener('click', (e) => {
    const b = e.target.closest('[data-move]');
    if (b) { reorder(Number(b.dataset.id), Number(b.dataset.move)); return; }
    const skip = e.target.closest('[data-skip]');
    if (skip) { store.set({ skipped: [...new Set([...store.get().skipped, Number(skip.dataset.skip)])] }); showRoute({ open: false }); $('#routeStart').focus(); return; }
    if (e.target.closest('[data-unskip]')) { store.set({ skipped: [] }); showRoute({ open: false }); return; }
    const add = e.target.closest('[data-add-stop]');
    if (add) { store.toggleSaved(Number(add.dataset.addStop)); showRoute({ open: false }); toast('Added to your stops.'); return; }
    const src = e.target.closest('[data-route-all]');
    if (src) { store.set({ routeAll: src.dataset.routeAll === '1' }); showRoute({ open: false }); return; }
    if (e.target.closest('#btnResetOrder')) {
      if (shared.stops) shared.order = false; else store.set({ order: [] });
      showRoute({ open: false });
      $('#routeStart').focus();
    }
  });
  $('#routeLink').addEventListener('focus', (e) => e.target.select());
  $('#btnCopyRoute').addEventListener('click', async () => {
    toast(await copyText(routeText()) ? 'Stop list copied.' : 'Copy did not work here; select the list and copy it.');
  });
  $('#btnShareRoute').addEventListener('click', async () => {
    const url = shareLink();
    if (navigator.share) {
      try { await navigator.share({ title: `${C.community.name} Halloween stops`, text: 'My trick-or-treat stops', url }); return; } catch (e) { if (e && e.name === 'AbortError') return; }
    }
    toast(await copyText(url) ? 'Link copied — anyone with it sees these stops.' : 'Copy the link below to share these stops.');
  });
  $('#btnRouteMap').addEventListener('click', showRouteOnMap);
  if (filters.noScares || filters.noFlashing || filters.noLoud || filters.nonfood || filters.adult || filters.stroller || filters.saved || filters.time !== 'any') {
    $('#moreFilters').classList.add('open');
    more.setAttribute('aria-expanded', 'true');
  }
}

async function refresh() {
  if (document.hidden) return;
  try {
    const data = await api('/api/homes');
    homes = data.homes;
    render();
    if (activeId) selectHome(activeId, { pan: false });
  } catch { /* keep the last list; it is still useful offline */ }
}

function routeFromHash() {
  const map = { '#route': 'route', '#list-my-house': 'register', '#info': 'info', '#my-house': 'house' };
  if (map[location.hash]) actions(map[location.hash]);
}

bind();
initRegistration({ community: C, onPublishedHint: () => {} });
render();
if (document.readyState === 'complete') initMap(); else addEventListener('load', initMap);
addEventListener('load', () => { if (map) { render(); map.invalidateSize(); } });
setInterval(refresh, 60000); // public data only; never touches a signed-in session
document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
addEventListener('hashchange', routeFromHash);
routeFromHash();
if (shared.stops) showRoute();
