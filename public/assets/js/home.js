/**
 * @module assets/js/home
 * @description Public family page controller: filters (localStorage), synchronized list + Leaflet
 *     map, favorites, selected-home panel, suggested stop order, info/house sheets, and the
 *     registration/dispute flows (register.js). Works without the map; the list is always usable.
 * @dependencies lib/*, register.js, Leaflet (global L, optional)
 */
import { api } from './lib/api.js';
import { createStore } from './lib/store.js';
import { DEFAULT_FILTERS, matches } from './lib/filters.js';
import { nowInZone, time12 } from './lib/time.js';
import { cardHtml, selectedHtml, pinHtml, esc } from './lib/render.js';
import { planStops } from './lib/route.js';
import { initModals, openModal, toast } from './lib/modal.js';
import { initRegistration, openRegistration, openDispute } from './register.js';

const boot = JSON.parse(document.getElementById('boot').textContent);
const C = boot.community;
let homes = boot.homes;
const zoneLabels = Object.fromEntries(C.zones.map((z) => [z.key, z.label]));
const store = createStore(C.community.id, C.event.date.slice(0, 4));
let filters = { ...DEFAULT_FILTERS, ...store.get().filters };
let activeId = null;
let routeNumbers = new Map();
let currentPlan = [];

const $ = (sel) => document.querySelector(sel);
const ctx = () => {
  const n = nowInZone(C.event.timezone);
  return { today: n.date, eventDate: C.event.date, nowMinutes: n.minutes, zoneLabels, saved: store.get().saved };
};
const visible = () => homes.filter((h) => matches(h, filters, ctx()));

/* ---------------- Map ---------------- */
let map = null;
const markers = new Map();

function initMap() {
  if (!window.L) return;
  try {
    map = L.map('map', { zoomControl: false, scrollWheelZoom: false, tap: true });
    L.tileLayer(C.map.tiles.url, { maxZoom: C.map.tiles.max_zoom, attribution: esc(C.map.tiles.attribution), referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
    if (C.map.boundary) {
      L.geoJSON(C.map.boundary, { style: { color: '#f07936', weight: 2, fillColor: '#f07936', fillOpacity: 0.05 }, interactive: false }).addTo(map);
    }
    for (const z of C.zones) {
      if (!z.geometry) continue;
      const layer = L.geoJSON(z.geometry, { style: { color: z.residential ? '#7d8f86' : '#a59cb2', weight: 1, dashArray: '4 6', fill: !z.residential, fillOpacity: 0.06 }, interactive: false }).addTo(map);
      layer.bindTooltip(esc(z.label), { permanent: true, direction: 'center', className: 'boundary-label' });
    }
    fitAll();
    map.on('click', () => selectHome(null));
    $('#mapFallback').hidden = true;
  } catch (e) {
    map = null;
    $('#mapFallback').textContent = 'The map could not load. Use the list of homes instead.';
  }
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
  $('#homeCards').innerHTML = vis.length
    ? vis.map((h) => cardHtml(h, c, { saved: saved.includes(h.id), active: activeId === h.id })).join('')
    : `<li class="empty">${homes.length ? 'No homes match these filters. Try a different area or time.' : 'No homes are listed yet. Residents can add theirs with “List your home”.'}</li>`;
  $('#listCount').textContent = `${vis.length} showing`;
  $('#savedCount').textContent = saved.filter((id) => homes.some((h) => h.id === id)).length;
  $('#statHomes').textContent = homes.length;
  $('#statCandy').textContent = homes.filter((h) => h.candy).length;
  $('#statGentle').textContent = homes.filter((h) => h.low_noise && !h.flashing_lights && !h.jump_scares && !h.loud_audio && h.scare_level !== 'intense').length;
  for (const btn of document.querySelectorAll('[data-filter]')) btn.setAttribute('aria-pressed', String(!!filters[btn.dataset.filter]));
  renderPins(ids);
  if (activeId && !homes.some((h) => h.id === activeId)) selectHome(null);
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
    if (map && pan) map.panTo([h.lat, h.lon], { animate: !matchMedia('(prefers-reduced-motion: reduce)').matches });
    const card = document.querySelector(`.home-card[data-id="${h.id}"]`);
    if (card && card.scrollIntoView && innerWidth > 660) card.scrollIntoView({ block: 'nearest' });
  }
  render();
}

/* ---------------- Route (suggested stop order) ---------------- */
function showRoute() {
  const saved = store.get().saved;
  const vis = visible();
  const chosen = saved.length ? homes.filter((h) => saved.includes(h.id)) : vis;
  const startSel = $('#routeStart');
  const startId = Number(store.get().start) || null;
  startSel.innerHTML = '<option value="">The first stop in each area</option>' + chosen.map((h) => `<option value="${h.id}" ${h.id === startId ? 'selected' : ''}>${esc(h.address)}</option>`).join('');
  const start = chosen.find((h) => h.id === startId) || null;
  currentPlan = planStops(chosen, { groups: C.routing.groups, zonesEnabled: C.zones.length > 0, start });
  $('#routeSub').textContent = saved.length
    ? 'Your saved stops, in a suggested order. Separate areas are listed as separate trips.'
    : 'Homes matching your filters (save favorites ♡ to choose your own). Separate areas are listed as separate trips.';
  let n = 0;
  routeNumbers = new Map();
  $('#routeItems').innerHTML = currentPlan.length ? currentPlan.map((g, gi) => {
    const label = g.zones.length ? g.zones.map((z) => zoneLabels[z] || z).join(', ') : (currentPlan.length > 1 ? `Area ${gi + 1}` : '');
    return `${label ? `<h3 class="route-group-title">${currentPlan.length > 1 ? `Trip ${gi + 1}: ` : ''}${esc(label)}</h3>` : ''}
      <ol class="route-items">${g.stops.map((h) => { n += 1; routeNumbers.set(h.id, n); return `<li class="route-item"><span class="route-index" aria-hidden="true">${n}</span><div><b>${esc(h.address)}</b><p>${time12(h.start)}–${time12(h.end)}${h.low_noise ? ' · Gentle' : ''}${h.jump_scares || h.scare_level === 'intense' ? ' · Intense scares' : ''}</p></div></li>`; }).join('')}</ol>
      ${g.truncated ? '<p class="form-caption">Showing the first 20 stops in this area.</p>' : ''}
      ${C.routing.provider && g.stops.length > 1 ? `<button type="button" class="secondary" data-provider-path="${gi}">Show walking path for this trip</button>` : ''}`;
  }).join('') + (currentPlan.length > 1 ? '<div class="notice">🚧 These areas are separate trips. The site does not suggest crossing between them; check for safe sidewalks, crossings and gate rules.</div>' : '')
    : '<div class="empty">No stops yet. Save some homes ♡ or change your filters.</div>';
  render();
  openModal('routeModal');
}

function routeText() {
  let n = 0;
  return `${C.community.name} Halloween — my stops (suggested order, not walking directions)\n` + currentPlan.map((g, gi) =>
    (currentPlan.length > 1 ? `\nTrip ${gi + 1}${g.zones.length ? ' (' + g.zones.map((z) => zoneLabels[z] || z).join(', ') + ')' : ''}\n` : '')
    + g.stops.map((h) => `${++n}. ${h.address} (${time12(h.start)}–${time12(h.end)})`).join('\n')).join('\n');
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

/* ---------------- Location (stays on this device) ---------------- */
let meMarker = null;
function locate() {
  if (!navigator.geolocation) { toast('Location is not available in this browser.'); return; }
  navigator.geolocation.getCurrentPosition((pos) => {
    if (!map) return;
    const ll = [pos.coords.latitude, pos.coords.longitude];
    if (meMarker) meMarker.setLatLng(ll); else meMarker = L.circleMarker(ll, { radius: 7, color: '#fff', weight: 3, fillColor: '#3b82f6', fillOpacity: 1 }).addTo(map).bindTooltip('You are here (not shared)');
    map.setView(ll, Math.max(map.getZoom(), 17));
  }, () => toast('Could not get your location. The map works without it.'), { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
}

/* ---------------- Wiring ---------------- */
function actions(action) {
  switch (action) {
    case 'explore': document.getElementById('explore').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); return true;
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
    if (a && actions(a.dataset.action)) { e.preventDefault(); return; }
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
      if (h) openDispute(h.id, h.address);
      return;
    }
    if (e.target.closest('[data-close-selected]')) { selectHome(null); return; }
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
  if (zone) { zone.value = filters.zone || 'all'; zone.addEventListener('change', () => setFilters({ zone: zone.value })); }
  const time = $('#time');
  time.value = filters.time || 'any';
  time.addEventListener('change', () => setFilters({ time: time.value }));
  $('#btnReset').addEventListener('click', () => {
    filters = { ...DEFAULT_FILTERS };
    store.set({ filters });
    search.value = ''; if (zone) zone.value = 'all'; time.value = 'any';
    render();
  });
  $('#btnFit').addEventListener('click', () => { selectHome(null); fitAll(); });
  $('#btnZoomIn').addEventListener('click', () => map && map.zoomIn());
  $('#btnZoomOut').addEventListener('click', () => map && map.zoomOut());
  $('#btnLocate').addEventListener('click', locate);
  $('#routeStart').addEventListener('change', (e) => { store.set({ start: e.target.value }); showRoute(); });
  $('#btnCopyRoute').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(routeText()); toast('Stop list copied.'); } catch { prompt('Copy your stop list:', routeText()); }
  });
  $('#btnShareRoute').addEventListener('click', async () => {
    if (navigator.share) { try { await navigator.share({ title: `${C.community.name} Halloween stops`, text: routeText() }); } catch { /* cancelled */ } }
    else { try { await navigator.clipboard.writeText(routeText()); toast('Sharing is not available; the list was copied instead.'); } catch { prompt('Copy your stop list:', routeText()); } }
  });
  if (filters.noJumps || filters.noFlashing || filters.noLoud || filters.noIntense || filters.nonfood || filters.adult || filters.stroller || filters.saved || filters.time !== 'any') {
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
