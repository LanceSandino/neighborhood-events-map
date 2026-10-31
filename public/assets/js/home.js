/**
 * @module assets/js/home
 * @description Public family page controller: filters (localStorage), synchronized list + Leaflet
 *     map kept on the neighborhood, favorites, selected-home panel, the inline "My route" panel
 *     (route-panel.js), Rules/house sheets, and the registration/dispute flows (register.js). Works
 *     without the map; the list is always usable. The community's event type (events/index.js) supplies the filter
 *     toggles, symbols, tags, statuses and words.
 * @dependencies lib/*, events/index.js, route-panel.js, register.js, Leaflet (global L, optional)
 */
import { api } from './lib/api.js';
import { createStore, migrateYearStore } from './lib/store.js';
import { defaultFilters, matches, activeFilterCount, normalizeFilters, choiceCounts } from './lib/filters.js';
import { eventType } from './events/index.js';
import { nowInZone } from './lib/time.js';
import { seasonOf } from './lib/schedule.js';
import { cardHtml, selectedHtml, pinHtml, esc, zoneStyle } from './lib/render.js';
import { otherEvent } from './lib/share.js';
import { focusMap } from './lib/map-focus.js';
import { labelPoint, resolveOverlaps } from './lib/zone-labels.js';
import { initModals, openModal, closeModal, toast } from './lib/modal.js';
import { initRegistration, openRegistration, openDispute } from './register.js';
import { createRoutePanel } from './route-panel.js';

const boot = JSON.parse(document.getElementById('boot').textContent);
const C = boot.community;
const T = eventType(C.event.type);
const DEFAULT_FILTERS = defaultFilters(T);
let homes = boot.homes;
const zoneLabels = Object.fromEntries(C.zones.map((z) => [z.key, z.label]));
// Saved homes and routes belong to this event (nhm:<community>:<event id>); a 0.10 season store moves in once.
if (T.migratesYearStore) migrateYearStore(C.community.id, C.event.date.slice(0, 4), C.event.id);
const store = createStore(C.community.id, String(C.event.id));
let filters = { ...DEFAULT_FILTERS, ...normalizeFilters(store.get().filters, T) };
// A shared link (?f=<filters>&stops=<ids>&start=<id>&e=<event id>) opens exactly that view, in the unsaved route so a
// saved one stays as it was; a link made for another event opens the map without its stops.
const shared = readSharedLink();
if ((shared.filters || shared.stops) && store.get().activeRoute) {
  store.switchRoute(null);
  filters = { ...DEFAULT_FILTERS, ...normalizeFilters(store.get().filters, T) };
}
if (shared.filters) { filters = { ...DEFAULT_FILTERS, ...normalizeFilters(shared.filters, T) }; store.set({ filters }); }
let activeId = null;
let here = null; // the visitor's location for "start from my location": memory only, never stored or sent
let route = null; // the "My route" panel (route-panel.js)

const $ = (sel) => document.querySelector(sel);
// The community clock, read at most once a second (every filter and status asks for it).
let clock = null;
const now = () => {
  if (!clock || Math.abs(Date.now() - clock.at) > 1000) clock = { at: Date.now(), n: nowInZone(C.event.timezone) };
  return clock.n;
};
const ctx = () => {
  const n = now();
  return { today: n.date, eventDate: C.event.date, nowMinutes: n.minutes, zoneLabels, saved: store.get().saved, areas: C.routing.groups || [], type: T, event: C.event };
};
const visible = () => homes.filter((h) => matches(h, filters, ctx()));
/** On the list, as the event type says (Halloween: handing out; lights: nights left this season). */
const isOpen = (h) => T.isOpen(h, ctx());
/** The type's filter choices besides its toggles (a night, a style): the controls marked data-choice. */
const choiceDefault = (k) => ((T.choices || []).find((c) => c.key === k) || { default: '' }).default;
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
      // The name sits where the organizer put it, else deepest inside the zone (not mid bounding box), as its own label.
      const tip = L.tooltip({ permanent: true, direction: 'center', className: 'boundary-label', interactive: false })
        .setLatLng(z.label_at ? [z.label_at[1], z.label_at[0]] : labelPoint(z.geometry) || layer.getBounds().getCenter())
        .setContent(esc(z.label) + (z.status === 'coming_soon' ? ' · coming soon' : ''));
      map.openTooltip(tip);
      zoneLayers.set(z.key, { zone: z, layer, tip });
    }
    fitAll();
    focusMap(L, map, { boundary: C.map.boundary, bbox: C.map.bbox }); // dim outside the neighborhood; stay near it
    spaceLabels();
    map.on('zoomend resize', spaceLabels);
    if (filters.zone && filters.zone !== 'all') highlightZone(filters.zone, { fit: false });
    if (activeFilterCount(filters, T)) fitToResults(); // remembered filters: start on the homes that match
    map.on('click', () => selectHome(null));
    $('#mapFallback').hidden = true;
  } catch (e) {
    map = null;
    $('#mapFallback').textContent = 'The map could not load. Use the list of homes instead.';
  }
}

/**
 * Zone names never cover each other: names placed by an organizer and phases that take listings keep their place, then other residential zones,
 * and names of common areas move below whatever they would cover. Runs again after every zoom and resize.
 */
function spaceLabels() {
  const items = [...zoneLayers.values()].filter((x) => x.tip && x.tip.getElement());
  for (const { tip } of items) { tip.options.offset = L.point(0, 0); tip.update(); }
  const boxes = items.map(({ zone, tip }) => {
    const r = tip.getElement().getBoundingClientRect();
    // Names an organizer placed never move; the others make room around them.
    return { x: r.left, y: r.top, w: r.width, h: r.height, priority: zone.label_at ? 10 : (zone.claimable ? 2 : 0) + (zone.residential ? 1 : 0) };
  });
  resolveOverlaps(boxes).forEach((dy, i) => { if (dy) { items[i].tip.options.offset = L.point(0, dy); items[i].tip.update(); } });
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
  const c = ctx();
  for (const [id, m] of markers) if (!homes.some((h) => h.id === id)) { m.remove(); markers.delete(id); }
  for (const h of homes) {
    const icon = L.divIcon({ className: `pin-icon${activeId === h.id ? ' active' : ''}`, html: pinHtml(h, { dim: !visibleIds.has(h.id), number: route.numbers().get(h.id), focus: filters, type: T, ctx: c }), iconSize: [37, 37], iconAnchor: [5, 36] });
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
  const card = (h) => cardHtml(h, c, { saved: saved.includes(h.id), active: activeId === h.id, focus: filters });
  const toggle = closed.length
    ? `<li class="closed-toggle"><button type="button" class="link-button" id="btnShowClosed" aria-expanded="${showClosed}">${showClosed ? 'Hide' : 'Show'} ${closed.length} ${closed.length === 1 ? 'home' : 'homes'} ${esc(T.copy.notOpen)}</button></li>`
    : '';
  $('#homeCards').innerHTML = open.length || closed.length
    ? open.map(card).join('') + (open.length ? '' : `<li class="empty">${esc(T.copy.noneOpen)}</li>`) + toggle + (showClosed ? closed.map(card).join('') : '')
    : `<li class="empty">${homes.length ? 'No homes match these filters. Try a different area or time.' : 'No homes are listed yet. Residents can add theirs with “List your home”.'}</li>`;
  $('#listCount').textContent = `${open.length} showing`;
  const view = store.get().view === 'compact' ? 'compact' : 'cards';
  $('#homeCards').classList.toggle('compact', view === 'compact');
  for (const b of document.querySelectorAll('[data-view]')) b.setAttribute('aria-pressed', String(b.dataset.view === view));
  const count = activeFilterCount({ ...filters, q: '' }, T);
  $('#filterCount').textContent = String(count);
  $('#filterCount').classList.toggle('hide', count === 0);
  $('#btnFilters').setAttribute('aria-label', count ? `Filters, ${count} active` : 'Filters');
  renderChoiceCounts(c);
  for (const btn of document.querySelectorAll('[data-filter]')) btn.setAttribute('aria-pressed', String(!!filters[btn.dataset.filter]));
  $('#btnReset').classList.toggle('hide', activeFilterCount(filters, T) === 0);
  renderSeason(c);
  route.render(); // stop order first: the pins show its numbers
  renderPins(ids);
  if (activeId && !homes.some((h) => h.id === activeId)) selectHome(null);
}

/** The season pill under the hero (types with a date range): where the season stands on the visitor's clock. */
function renderSeason(c) {
  const pill = $('#seasonPill');
  if (!pill || !C.event.starts_on) return;
  const s = seasonOf(C.event, c.today);
  pill.className = `season-pill ${s}`;
  pill.textContent = pill.dataset[s] || pill.textContent;
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
  fitToResults({ delay: 'q' in patch ? 500 : 0 }); // typing in search waits for a pause
}

/**
 * After a filter change the map shows what matches: the homes handing out that match (closer than a whole
 * phase), else the chosen phase or walking area, else the whole neighborhood.
 */
let fitTimer = null;
function fitToResults({ delay = 0 } = {}) {
  clearTimeout(fitTimer);
  fitTimer = setTimeout(() => {
    if (!map) return;
    if (activeFilterCount(filters, T) === 0) { fitAll(); return; }
    const shown = visible().filter(isOpen);
    if (shown.length) map.fitBounds(shown.map((h) => [h.lat, h.lon]), { padding: [50, 50], maxZoom: 18, animate: !reducedMotion() });
    else highlightZone(filters.zone || 'all');
  }, delay);
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
  if (otherEvent(q.get('e'), C.event.id)) { out.otherEvent = true; return out; }
  try {
    if (q.get('f')) {
      const json = JSON.parse(atob(q.get('f').replace(/-/g, '+').replace(/_/g, '/')));
      out.filters = normalizeFilters(json, T);
    }
  } catch { /* ignore a damaged link */ }
  const ids = (q.get('stops') || '').split('.').map(Number).filter((n) => Number.isInteger(n) && n > 0).slice(0, 60);
  if (ids.length) { out.stops = ids; out.start = Number(q.get('start')) || null; out.order = q.get('o') === '1'; }
  return out;
}

/** A saved route was opened (or saved or deleted): its filters become the page's filters. */
function applyStoredFilters() {
  filters = { ...DEFAULT_FILTERS, ...normalizeFilters(store.get().filters, T) };
  syncControls();
  highlightZone(filters.zone || 'all', { fit: false });
  render();
  fitToResults();
}

/** Puts the current filters into the search box, zone, time and the type's choice controls. */
function syncControls() {
  $('#search').value = filters.q || '';
  if ($('#zone')) $('#zone').value = filters.zone || 'all';
  if ($('#time')) $('#time').value = filters.time || 'any';
  for (const el of document.querySelectorAll('[data-choice]')) el.value = filters[el.dataset.choice] ?? choiceDefault(el.dataset.choice);
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
    case 'route': route.open(); return true;
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
  syncControls();
  const search = $('#search');
  search.addEventListener('input', () => setFilters({ q: search.value }));
  const zone = $('#zone');
  if (zone) {
    zone.addEventListener('change', () => { highlightZone(zone.value, { fit: false }); setFilters({ zone: zone.value }); });
  }
  const time = $('#time');
  if (time) time.addEventListener('change', () => setFilters({ time: time.value }));
  for (const el of document.querySelectorAll('[data-choice]')) {
    el.addEventListener('change', () => setFilters({ [el.dataset.choice]: el.value || choiceDefault(el.dataset.choice) }));
  }
  $('#btnReset').addEventListener('click', () => {
    filters = { ...DEFAULT_FILTERS };
    store.set({ filters });
    search.value = ''; if (zone) zone.value = 'all'; if (time) time.value = 'any';
    syncControls();
    highlightZone('all', { fit: false });
    render();
    fitToResults(); // no filters: the whole neighborhood (also where there are no phases)
  });
  $('#btnFit').addEventListener('click', () => { selectHome(null); fitAll(); });
  $('#btnZoomIn').addEventListener('click', () => map && map.zoomIn());
  $('#btnZoomOut').addEventListener('click', () => map && map.zoomOut());
  $('#btnLocate').addEventListener('click', locate);
  // Filters behind the "Filters" button start open when one of them is on.
  if (T.filters.some((f) => !f.quick && filters[f.key]) || filters.time !== 'any' || (T.choices || []).some((c) => filters[c.key] !== c.default)) {
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

// Leaflet's stylesheet is only needed for the map, so it loads here instead of blocking the first paint
// (stylesheets added before the page's load event still delay it, and the map starts on load).
if (!document.querySelector('link[href="/vendor/leaflet/leaflet.css"]')) {
  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = '/vendor/leaflet/leaflet.css';
  document.head.append(css);
}

route = createRoutePanel({ C, type: T, store, shared, homes: () => homes, visible, isOpen, filters: () => filters, map: () => map,
  here: () => here, setHere: (pos) => { here = pos; showMe(); }, render, applyStoredFilters, reducedMotion });
bind();
initRegistration({ community: C, onPublishedHint: () => {} });
render();
if (document.readyState === 'complete') initMap(); else addEventListener('load', initMap);
addEventListener('load', () => { if (map) { render(); map.invalidateSize(); } });
setInterval(refresh, 60000); // public data only; never touches a signed-in session
document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
addEventListener('hashchange', routeFromHash);
// A link to a sheet or to My route opens once the page (and the map above the route) has its final layout.
const whenLoaded = (fn) => (document.readyState === 'complete' ? fn() : addEventListener('load', fn, { once: true }));
whenLoaded(() => {
  routeFromHash();
  if (shared.stops) route.open();
  if (shared.otherEvent) { history.replaceState(null, '', '/'); toast('That shared link was made for another event, so its stops are not shown.', 10000); }
});
