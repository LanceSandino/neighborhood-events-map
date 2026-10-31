/**
 * @module assets/js/route-panel
 * @description "My route" on the map page: a suggested stop order (or the visitor's own order) from saved homes or
 *     every matching home, split into walkable trips, with a start point, skip/reorder, nearby suggestions, named
 *     saved routes (each keeps its own stops and filters; lib/store.js), Share and Show on map. The pin numbers come
 *     from here. It is never walking directions (ADR 0007). The event type (d.type) supplies its words and stop notes.
 *     Where the event offers driving (route_modes, a configured provider), a drive-by tour joins the stops into one trip
 *     and its path comes only from the provider's driving profile; otherwise the stops stay an ordered list.
 * @dependencies lib/route.js, lib/render.js, lib/time.js, lib/filters.js, lib/api.js, lib/modal.js, events/index.js
 */
import { planStops, applyOrder, moveStop, suggestNearby } from './lib/route.js';
import { esc } from './lib/render.js';
import { time12 } from './lib/time.js';
import { defaultFilters } from './lib/filters.js';
import { eventType } from './events/index.js';
import { api } from './lib/api.js';
import { toast } from './lib/modal.js';

/**
 * @param {object} d  page context: C (community), store, shared (link state), homes(), visible(), isOpen(home),
 *     filters(), map(), here(), setHere(pos), render() (whole page), applyStoredFilters(), reducedMotion(), type (event module)
 */
export function createRoutePanel(d) {
  const { C, store, shared } = d;
  const T = d.type || eventType();
  const DEFAULTS = defaultFilters(T);
  const $ = (s) => document.querySelector(s);
  const pane = $('#route');
  let numbers = new Map();
  let plan = [];
  let manualOrder = false;
  let locating = false;
  let pathLayer = null;
  // A drive-by tour is offered only when the event allows driving and a provider is configured (/api/event route_modes).
  const drivingOffered = (C.event.route_modes || []).includes('driving');
  let mode = 'walking';

  /** Link to this exact view: the event, the active filters and, for a route, its stops and starting point. */
  function shareLink() {
    const url = new URL('/', location.origin);
    url.searchParams.set('e', String(C.event.id)); // the event the stops belong to
    const filters = d.filters();
    const changed = Object.fromEntries(Object.entries(filters).filter(([k, v]) => JSON.stringify(v) !== JSON.stringify(DEFAULTS[k])));
    if (Object.keys(changed).length) url.searchParams.set('f', btoa(JSON.stringify(changed)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''));
    if (plan.length) {
      url.searchParams.set('stops', plan.flatMap((g) => g.stops.map((h) => h.id)).join('.'));
      const start = Number($('#routeStart').value) || null; // "my location" is never put in a link
      if (start) url.searchParams.set('start', String(start));
      if (manualOrder) url.searchParams.set('o', '1');
    }
    return url.toString();
  }

  /** A trip's name: the walking area's name ("Main (1–3)") when it has one, else its zones in map order. */
  function tripLabel(g, gi) {
    const area = g.key.startsWith('group-') ? document.querySelector(`#zone option[value="area:${g.key.slice(6)}"]`) : null;
    if (area) return area.dataset.label;
    const order = C.zones.map((z) => z.key);
    const labels = Object.fromEntries(C.zones.map((z) => [z.key, z.label]));
    if (g.zones.length) return [...g.zones].sort((a, b) => order.indexOf(a) - order.indexOf(b)).map((z) => labels[z] || z).join(', ');
    return plan.length > 1 ? `Area ${gi + 1}` : '';
  }

  /** The filters shaping a filter-based route, as chips, with a way back to change them. */
  function filterSummary() {
    const chips = [];
    const zone = $('#zone');
    if (zone && zone.value !== 'all') chips.push(zone.selectedOptions[0]?.dataset.label || zone.value);
    for (const b of document.querySelectorAll('[data-filter][aria-pressed="true"]')) {
      const icon = b.querySelector('.fi')?.outerHTML || '';
      const words = b.querySelector('.sr-only')?.textContent || [...b.childNodes].filter((n) => n.nodeType === Node.TEXT_NODE).map((n) => n.textContent).join('').trim();
      chips.push({ icon, words });
    }
    const time = $('#time');
    if (time && time.value !== 'any') chips.push(`🕐 ${time.selectedOptions[0]?.textContent || time.value}`);
    for (const el of document.querySelectorAll('[data-choice]')) {
      if (el.value && el.value !== 'any') chips.push(el.tagName === 'SELECT' ? (el.selectedOptions[0]?.textContent || el.value) : `📅 ${el.value}`);
    }
    const q = (d.filters().q || '').trim();
    if (q) chips.push(`🔍 “${q}”`);
    if (!chips.length) return `<p class="form-caption route-filters">${esc(T.copy.routeShowingAll)} <button type="button" class="link-button" data-edit-filters>Add filters</button></p>`;
    const html = chips.map((c) => `<span class="route-chip">${typeof c === 'string' ? esc(c) : `${c.icon}${esc(c.words)}`}</span>`).join('');
    return `<div class="route-filters" aria-label="Filters used for these stops"><span class="label">Filtered by</span>${html}<button type="button" class="link-button" data-edit-filters>Change</button></div>`;
  }

  /** Saved routes: a picker once there is one, "Save route" while there are stops, "Delete" for the open one. */
  function renderPicker(linked, planning) {
    const { routes, activeRoute } = store.get();
    const pick = $('#routePick');
    pick.hidden = !routes.length;
    pick.innerHTML = (linked ? '<option value="__shared">Shared with you</option>' : '')
      + '<option value="">My unsaved route</option>'
      + routes.map((r) => `<option value="${esc(r.id)}">${esc(r.name)}</option>`).join('');
    pick.value = linked ? '__shared' : (activeRoute || '');
    $('#btnSaveRoute').hidden = !planning || Boolean(activeRoute && !linked);
    $('#btnDeleteRoute').hidden = linked || !activeRoute;
    $('#routePickRow').hidden = pick.hidden && $('#btnSaveRoute').hidden && $('#btnDeleteRoute').hidden;
  }

  /** Builds the stop list and the pin numbers from the current saved homes, filters, start and order. */
  function render() {
    if (!pane) return;
    pane.hidden = false;
    const homes = d.homes();
    const { saved, routeAll } = store.get();
    const skippedIds = new Set(store.get().skipped);
    const vis = d.visible();
    const fromLink = shared.stops ? homes.filter((h) => shared.stops.includes(h.id)) : null;
    const linked = Boolean(fromLink && fromLink.length);
    const savedHere = saved.filter((id) => homes.some((h) => h.id === id));
    // Saved homes, or ("All matching" chosen) every home matching the filters; nothing yet otherwise.
    const useSaved = !linked && savedHere.length > 0 && !routeAll;
    const planning = linked || useSaved || routeAll;
    pane.classList.toggle('empty', !planning);
    $('#savedCount').textContent = String(savedHere.length);
    renderPicker(linked, planning);
    const source = !linked && (saved.length || routeAll)
      ? `<div class="route-source" role="group" aria-label="Stops to include"><button type="button" data-route-all="0" aria-pressed="${!routeAll}">🧡 Saved (${savedHere.length})</button><button type="button" data-route-all="1" aria-pressed="${routeAll}">All matching (${vis.filter(d.isOpen).length})</button></div>`
      : '';
    if (!planning) {
      plan = []; numbers = new Map(); manualOrder = false;
      const n = vis.filter(d.isOpen).length;
      $('#routeSub').textContent = 'Tap ♡ on the homes you want to visit and they appear here in a suggested order.';
      $('#routeItems').innerHTML = source + (n ? `<p class="route-empty-action"><button type="button" class="secondary" data-route-all="1">Plan with all ${n} matching ${n === 1 ? 'home' : 'homes'}</button></p>` : '');
      $('#btnShareRoute').dataset.url = shareLink();
      return;
    }
    const pool = linked ? fromLink : (useSaved ? homes.filter((h) => saved.includes(h.id)) : vis);
    // Homes that are not open (for Halloween: paused, out of candy or done for the night) never go on a route; skipped ones neither.
    const handingOut = pool.filter(d.isOpen);
    const chosen = handingOut.filter((h) => !skippedIds.has(h.id));
    const skipped = pool.length - handingOut.length;
    const userSkipped = handingOut.length - chosen.length;
    const startSel = $('#routeStart');
    const pref = (linked && shared.start) || store.get().start || '';
    const startId = Number(pref) || null;
    startSel.innerHTML = '<option value="">The first stop in each area</option>'
      + ('geolocation' in navigator && window.isSecureContext ? `<option value="here" ${pref === 'here' ? 'selected' : ''}>📍 My current location</option>` : '')
      + chosen.map((h) => `<option value="${h.id}" ${h.id === startId ? 'selected' : ''}>${esc(h.address)}</option>`).join('');
    const here = d.here();
    const start = pref === 'here' && here ? { id: null, lat: here.lat, lon: here.lon } : chosen.find((h) => h.id === startId) || null;
    const order = linked ? (shared.order ? shared.stops : null) : store.get().order;
    manualOrder = Boolean(order && order.length);
    // A drive-by tour is one trip (driving may join areas); walking keeps the walkable trips.
    plan = applyOrder(planStops(chosen, mode === 'driving' ? { groups: [], zonesEnabled: false, start } : { groups: C.routing.groups, zonesEnabled: C.zones.length > 0, start }), order);
    const modeSwitch = drivingOffered
      ? `<div class="route-mode" role="group" aria-label="How you will go"><button type="button" data-route-mode="walking" aria-pressed="${mode === 'walking'}">${esc(T.copy.walking)}</button><button type="button" data-route-mode="driving" aria-pressed="${mode === 'driving'}">${esc(T.copy.drivingTour)}</button></div>`
      : '';
    $('#routeSub').innerHTML = esc(linked
      ? 'A stop list someone shared with you. Save it to keep it, or save the homes you like with ♡.'
      : useSaved
      ? `Your ${savedHere.length} saved ${savedHere.length === 1 ? 'home' : 'homes'}, in ${manualOrder ? 'your own' : 'a suggested'} order.`
      : T.copy.routeEvery)
      // "My current location" is remembered as a choice, but the position never is: asked for again, on a tap.
      + (pref === 'here' && !here ? (locating ? ' 📍 Finding your location…' : ' <button type="button" class="link-button" data-locate-start>📍 Use my location</button>') : '');
    let n = 0;
    numbers = new Map();
    const move = (h, i, last, dir) => `<button type="button" class="move" data-move="${dir}" data-id="${h.id}" aria-label="Move ${esc(h.address)} ${dir < 0 ? 'earlier' : 'later'}" ${(dir < 0 ? i === 0 : i === last) ? 'disabled' : ''}>${dir < 0 ? '↑' : '↓'}</button>`;
    const ideas = useSaved && chosen.length
      ? suggestNearby(chosen, vis.filter((h) => d.isOpen(h) && !skippedIds.has(h.id)), { groups: C.routing.groups, zonesEnabled: C.zones.length > 0 })
      : [];
    const extras = ideas.length ? `<div class="route-suggest"><h3 class="route-group-title">Nearby, not on your list</h3><ul>${ideas.map(({ home: h, meters }) => `<li><div class="route-text"><b>${esc(h.address)}</b><p>About ${Math.max(10, Math.round(meters / 10) * 10)} m from a stop · ${time12(h.start)}–${time12(h.end)}</p></div><button type="button" class="secondary add-stop" data-add-stop="${h.id}" aria-label="Add ${esc(h.address)} to my stops">+ Add</button></li>`).join('')}</ul></div>` : '';
    const stopCount = plan.reduce((t, g) => t + g.stops.length, 0);
    const status = `<p class="route-status" role="status"><b>${stopCount} ${stopCount === 1 ? 'stop' : 'stops'}</b>${plan.length > 1 ? ` in ${plan.length} trips` : ''}${start ? ` · from ${start.id ? esc(start.address) : 'your location'}` : ''}${skipped ? ` · ${skipped} ${esc(T.copy.routeNotOpen)}` : ''}${userSkipped ? ` · ${userSkipped} skipped <button type="button" class="link-button" data-unskip>Bring back</button>` : ''}</p>`;
    $('#routeItems').innerHTML = source + modeSwitch + (!linked && !useSaved ? filterSummary() : '') + status + (plan.length ? (manualOrder ? '<p class="form-caption route-order">Your own order. <button type="button" class="link-button" id="btnResetOrder">Use the suggested order</button></p>' : '')
      + plan.map((g, gi) => {
        const label = tripLabel(g, gi);
        return `${label ? `<h3 class="route-group-title">${plan.length > 1 ? `Trip ${gi + 1}: ` : ''}${esc(label)}</h3>` : ''}
        <ol class="route-items" tabindex="0" aria-label="${esc(label || 'Stops')}: ${g.stops.length} stops">${g.stops.map((h, i) => { n += 1; numbers.set(h.id, n); return `<li class="route-item"><span class="route-index" aria-hidden="true">${n}</span><div class="route-text"><b>${esc(h.address)}</b><p>${time12(h.start)}–${time12(h.end)}${esc(T.routeNote(h))}</p></div><span class="route-move">${g.stops.length > 1 ? move(h, i, g.stops.length - 1, -1) + move(h, i, g.stops.length - 1, 1) : ''}<button type="button" class="move skip" data-skip="${h.id}" aria-label="Skip ${esc(h.address)}">✕</button></span></li>`; }).join('')}</ol>
        ${g.truncated ? '<p class="form-caption">Showing the first 20 stops in this area.</p>' : ''}
        ${(mode === 'driving' ? drivingOffered : C.routing.provider) && g.stops.length > 1 ? `<button type="button" class="secondary" data-provider-path="${gi}">${mode === 'driving' ? 'Show the driving route for this tour' : 'Show walking path for this trip'}</button>` : ''}`;
      }).join('') + (plan.length > 1 ? '<div class="notice">🚧 These areas are separate trips. The site does not suggest crossing between them; check for safe sidewalks, crossings and gate rules.</div>' : '')
      : '<div class="empty">No stops yet. Save some homes ♡ or change your filters.</div>') + extras;
    $('#btnShareRoute').dataset.url = shareLink();
  }

  /** Shows the panel (from the nav, a link or #route) and moves keyboard focus to it. */
  function open() {
    d.render();
    pane.scrollIntoView({ block: 'start', behavior: d.reducedMotion() ? 'auto' : 'smooth' });
    $('#routeTitle').focus({ preventScroll: true });
  }

  /** Moves a stop within its trip and keeps the new order (on this device, or in the shared view). */
  function reorder(id, delta) {
    const order = moveStop(plan, id, delta);
    if (shared.stops) { shared.stops = order; shared.order = true; } else store.set({ order });
    d.render();
    const again = document.querySelector(`#routeItems [data-move="${delta}"][data-id="${id}"]:not([disabled])`)
      || document.querySelector(`#routeItems [data-move="${-delta}"][data-id="${id}"]`);
    if (again) again.focus();
  }

  /** "Start from my location": asks for the position (after a tap, or when already allowed); kept in memory only. */
  function startFromHere() {
    if (!navigator.geolocation || !window.isSecureContext) { store.set({ start: '' }); return; }
    locating = true;
    d.render();
    navigator.geolocation.getCurrentPosition((pos) => {
      locating = false;
      d.setHere({ lat: pos.coords.latitude, lon: pos.coords.longitude });
      d.render();
    }, () => {
      locating = false;
      store.set({ start: '' });
      d.render();
      toast('Could not get your location. Choose a starting home instead.');
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  }

  async function showProviderPath(gi) {
    const g = plan[gi];
    const map = d.map();
    try {
      const { route } = await api('/api/routes/plan', { method: 'POST', body: { stops: g.stops.map((h) => h.id), ...(mode === 'driving' ? { profile: 'driving' } : {}) } });
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

  /** Copies text: the Clipboard API where allowed (HTTPS), else a hidden textarea (also iPhone over plain HTTP). */
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

  /** Fits the numbered stops on the map and brings the map into view. */
  function showOnMap() {
    const map = d.map();
    const stops = plan.flatMap((g) => g.stops);
    if (!map || !stops.length) return;
    const here = $('#routeStart').value === 'here' ? d.here() : null;
    map.fitBounds([...stops.map((h) => [h.lat, h.lon]), ...(here ? [[here.lat, here.lon]] : [])], { padding: [40, 40], maxZoom: 18 });
    const box = $('#mapPane').getBoundingClientRect();
    if (box.top < 0 || box.bottom > innerHeight) $('#mapPane').scrollIntoView({ block: innerWidth <= 660 ? 'start' : 'nearest', behavior: d.reducedMotion() ? 'auto' : 'smooth' });
  }

  function forgetLink() {
    shared.stops = null; shared.start = null; shared.order = false;
    history.replaceState(null, '', '/');
  }

  function bind() {
    $('#routeStart').addEventListener('change', (e) => {
      // A new start means a new suggested order.
      shared.start = null; shared.order = false;
      store.set({ start: e.target.value, order: [] });
      if (e.target.value === 'here' && !d.here()) startFromHere(); else d.render();
    });
    $('#routeItems').addEventListener('click', (e) => {
      const b = e.target.closest('[data-move]');
      if (b) { reorder(Number(b.dataset.id), Number(b.dataset.move)); return; }
      const skip = e.target.closest('[data-skip]');
      if (skip) { store.set({ skipped: [...new Set([...store.get().skipped, Number(skip.dataset.skip)])] }); d.render(); $('#routeStart').focus(); return; }
      if (e.target.closest('[data-unskip]')) { store.set({ skipped: [] }); d.render(); return; }
      const add = e.target.closest('[data-add-stop]');
      if (add) { store.toggleSaved(Number(add.dataset.addStop)); d.render(); toast('Added to your stops.'); return; }
      if (e.target.closest('[data-edit-filters]')) {
        $('#explore').scrollIntoView({ behavior: d.reducedMotion() ? 'auto' : 'smooth' });
        if ($('#moreFilters') && !$('#moreFilters').classList.contains('open')) $('#btnFilters').click();
        return;
      }
      const modeBtn = e.target.closest('[data-route-mode]');
      if (modeBtn) { mode = modeBtn.dataset.routeMode === 'driving' && drivingOffered ? 'driving' : 'walking'; if (pathLayer) { pathLayer.remove(); pathLayer = null; } d.render(); return; }
      const src = e.target.closest('[data-route-all]');
      if (src) { store.set({ routeAll: src.dataset.routeAll === '1' }); d.render(); return; }
      const path = e.target.closest('[data-provider-path]');
      if (path) { showProviderPath(Number(path.dataset.providerPath)); return; }
      if (e.target.closest('#btnResetOrder')) {
        if (shared.stops) shared.order = false; else store.set({ order: [] });
        d.render();
        $('#routeStart').focus();
      }
    });
    $('#routeSub').addEventListener('click', (e) => { if (e.target.closest('[data-locate-start]')) startFromHere(); });
    $('#btnShareRoute').addEventListener('click', async () => {
      const url = shareLink();
      if (navigator.share) {
        try { await navigator.share({ title: `${C.community.name} ${C.event.name} stops`, text: T.copy.shareText, url }); return; } catch (e) { if (e && e.name === 'AbortError') return; }
      }
      toast(await copyText(url) ? 'Link copied — anyone with it sees these stops.' : `Copy this link to share these stops: ${url}`, 8000);
    });
    $('#btnRouteMap').addEventListener('click', showOnMap);
    $('#routePick').addEventListener('change', (e) => {
      if (e.target.value === '__shared') return;
      if (shared.stops) forgetLink();
      store.switchRoute(e.target.value || null);
      d.applyStoredFilters();
    });
    const form = $('#routeNameForm');
    const closeForm = () => { form.hidden = true; $('#btnSaveRoute').focus(); };
    $('#btnSaveRoute').addEventListener('click', () => { form.hidden = false; $('#routeName').value = ''; $('#routeName').focus(); });
    $('#btnCancelRouteName').addEventListener('click', closeForm);
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const name = $('#routeName').value.trim();
      if (!name) { $('#routeName').focus(); return; }
      const fields = shared.stops ? { saved: shared.stops, order: shared.order ? shared.stops : [], start: shared.start ? String(shared.start) : '', filters: d.filters() } : null;
      store.saveRoute(name, fields);
      if (shared.stops) forgetLink();
      form.hidden = true;
      toast(`Saved “${name}”. Switch routes from the list above your stops.`);
      d.applyStoredFilters();
      $('#routePick').focus();
    });
    $('#btnDeleteRoute').addEventListener('click', () => {
      const r = store.get().routes.find((x) => x.id === store.get().activeRoute);
      if (!r || !confirm(`Delete the route “${r.name}”? Your other routes stay.`)) return;
      store.deleteRoute(r.id);
      d.applyStoredFilters();
      toast(`Deleted “${r.name}”.`);
    });
    // A remembered "My current location" start is used at once only when location is already allowed (no prompt on load).
    if (store.get().start === 'here' && navigator.permissions?.query) {
      navigator.permissions.query({ name: 'geolocation' }).then((p) => { if (p.state === 'granted' && !d.here()) startFromHere(); }).catch(() => {});
    }
  }

  if (pane) bind();
  return { render, open, numbers: () => numbers };
}
