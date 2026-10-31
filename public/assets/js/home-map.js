/**
 * @module assets/js/home-map
 * @description The family page's Leaflet map (home.js owns the page state and passes it in): tiles, the approved
 *     outline in the event type's colour, zone shapes with names that never cover each other, the dimmed outside
 *     (lib/map-focus.js), fitting the neighborhood or a chosen zone or walking area, the home pins (a focused pin is a
 *     button: Enter or Space selects its home, as a click does) and the visitor's location dot, which stays on the
 *     device. Without Leaflet, or when it fails, the map stays empty and the list is the page.
 * @dependencies lib/render.js, lib/map-focus.js, lib/zone-labels.js, Leaflet (global L, optional)
 */
import { pinHtml, esc, zoneStyle } from './lib/render.js';
import { focusMap } from './lib/map-focus.js';
import { labelPoint, resolveOverlaps } from './lib/zone-labels.js';

/**
 * @param {object} o
 * @param {object} o.C the public community payload
 * @param {object} o.T the event type's browser module
 * @param {() => object[]} o.homes the homes on the page
 * @param {() => object} o.filters the current filters
 * @param {() => number|null} o.activeId the selected home's id
 * @param {() => Map<number, number>} o.numbers stop numbers of the route
 * @param {() => object} o.ctx the filter context (the community clock)
 * @param {(id: number|null) => void} o.selectHome
 * @param {() => boolean} o.reducedMotion
 * @param {() => void} o.onReady runs once the map is up (home.js fits it to remembered filters)
 */
export function createHomeMap({ C, T, homes, filters, activeId, numbers, ctx, selectHome, reducedMotion, onReady }) {
  let map = null;
  const markers = new Map();
  const zoneLayers = new Map();
  let meMarker = null;
  function initMap() {
    if (!window.L) return;
    try {
      map = L.map('map', { zoomControl: false, scrollWheelZoom: false, tap: true, zoomSnap: 0.25 });
      L.tileLayer(C.map.tiles.url, { maxZoom: C.map.tiles.max_zoom, attribution: esc(C.map.tiles.attribution), referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
      if (C.map.boundary) {
        const line = T.mapColor || '#f07936'; // the event type's outline colour (Halloween: orange)
        L.geoJSON(C.map.boundary, { style: { color: line, weight: 2, fillColor: line, fillOpacity: 0.05 }, interactive: false }).addTo(map);
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
      if (filters().zone && filters().zone !== 'all') highlightZone(filters().zone, { fit: false });
      onReady(); // e.g. remembered filters: start on the homes that match
      map.on('click', () => selectHome(null));
      document.getElementById('mapFallback').hidden = true;
    } catch (e) {
      map = null;
      document.getElementById('mapFallback').textContent = 'The map could not load. Use the list of homes instead.';
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
    else if (homes().length) map.fitBounds(homes().map((h) => [h.lat, h.lon]), { padding: [40, 40], maxZoom: 17 });
    else if (C.map.center) map.setView([C.map.center.lat, C.map.center.lon], C.map.zoom);
    else map.setView([39.5, -98.35], 4);
  }

  function renderPins(visibleIds) {
    if (!map) return;
    const c = ctx();
    for (const [id, m] of markers) if (!homes().some((h) => h.id === id)) { m.remove(); markers.delete(id); }
    for (const h of homes()) {
      const icon = L.divIcon({ className: `pin-icon${activeId() === h.id ? ' active' : ''}`, html: pinHtml(h, { dim: !visibleIds.has(h.id), number: numbers().get(h.id), focus: filters(), type: T, ctx: c }), iconSize: [37, 37], iconAnchor: [5, 36] });
      let m = markers.get(h.id);
      if (!m) {
        m = L.marker([h.lat, h.lon], { icon, keyboard: true, title: h.address, alt: h.address, riseOnHover: true });
        m.on('click', (e) => { L.DomEvent.stopPropagation(e); selectHome(h.id); });
        // A focused pin is a button (Leaflet gives it role="button"): Enter or Space selects its home, as a click does.
        m.on('keydown', (e) => {
          if (e.originalEvent.key !== 'Enter' && e.originalEvent.key !== ' ') return;
          e.originalEvent.preventDefault();
          selectHome(h.id);
        });
        m.addTo(map);
        markers.set(h.id, m);
      } else {
        m.setIcon(icon);
        m.setLatLng([h.lat, h.lon]);
      }
      m.setZIndexOffset(visibleIds.has(h.id) ? (activeId() === h.id ? 1000 : 100) : 0);
    }
  }

  /** The visitor's location dot (memory only, never stored or sent). */
  function showMe(here) {
    if (!map || !here) return;
    const ll = [here.lat, here.lon];
    if (meMarker) meMarker.setLatLng(ll); else meMarker = L.circleMarker(ll, { radius: 7, color: '#fff', weight: 3, fillColor: '#3b82f6', fillOpacity: 1 }).addTo(map).bindTooltip('You are here (not shared)');
  }

  return { init: initMap, highlightZone, fitAll, renderPins, showMe, get map() { return map; } };
}
