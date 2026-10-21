/**
 * @module assets/js/lib/map-focus
 * @description Keeps the map on the neighborhood: everything outside the approved boundary is dimmed (one polygon
 *     covering the world with the boundary cut out), panning stops at the area plus a margin, and zooming out stops
 *     where that whole margin fits. The ring and box math is DOM-free for tests; Leaflet is passed in.
 * @dependencies Leaflet (passed in as L)
 */
/** The world as a Leaflet ring, inside Web Mercator's latitude limit. */
export const WORLD = [[-85, -180], [-85, 180], [85, 180], [85, -180]];

/** Leaflet rings for the dimmed area: the world first, then each boundary polygon's outline as a hole. */
export function maskRings(geometry) {
  const polygons = geometry?.type === 'Polygon' ? [geometry.coordinates] : geometry?.type === 'MultiPolygon' ? geometry.coordinates : [];
  const holes = polygons.map((p) => (Array.isArray(p?.[0]) ? p[0] : []).map(([lon, lat]) => [lat, lon])).filter((r) => r.length >= 3);
  return holes.length ? [WORLD, ...holes] : null;
}

/** How far the map may pan: the area's box grown by `pad` of its size on every side (at least about 300 m). */
export function panLimit(b, pad = 0.6) {
  const dLat = Math.max((b.maxLat - b.minLat) * pad, 0.003);
  const dLon = Math.max((b.maxLon - b.minLon) * pad, 0.003);
  return [[b.minLat - dLat, b.minLon - dLon], [b.maxLat + dLat, b.maxLon + dLon]];
}

/** Dims outside the boundary (when one is approved) and limits panning and zooming out to the area (when known). */
export function focusMap(L, map, { boundary, bbox }) {
  const rings = maskRings(boundary);
  if (rings) L.polygon(rings, { className: 'outside-mask', interactive: false, stroke: false, fillRule: 'evenodd' }).addTo(map);
  if (!bbox) return;
  const limit = L.latLngBounds(panLimit(bbox));
  map.options.maxBoundsViscosity = 1;
  map.setMaxBounds(limit);
  const fit = () => map.setMinZoom(Math.max(0, Math.floor(map.getBoundsZoom(limit))));
  fit();
  map.on('resize', fit); // a rotated phone or a resized window gets its own limit
}
