/**
 * @module assets/js/lib/zone-labels
 * @description Where zone names go on the map. labelPoint: the point deepest inside a zone's largest part (its "pole
 *     of inaccessibility", so an L-shaped or wrapped zone is named inside itself, not at the middle of its bounding
 *     box). resolveOverlaps: vertical nudges that keep names from covering each other, moving less important names
 *     (common areas before phases that take listings). DOM-free for tests; the map calls them with screen boxes.
 */

/** Shoelace area of a ring of [x, y] points (absolute). */
function ringArea(ring) {
  let a = 0;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) a += (ring[j][0] + ring[i][0]) * (ring[j][1] - ring[i][1]);
  return Math.abs(a / 2);
}

/** Squared distance from point p to segment a–b. */
function segDist2(p, a, b) {
  let [x, y] = a;
  let dx = b[0] - x; let dy = b[1] - y;
  if (dx || dy) {
    const t = ((p[0] - x) * dx + (p[1] - y) * dy) / (dx * dx + dy * dy);
    if (t > 1) { [x, y] = b; } else if (t > 0) { x += dx * t; y += dy * t; }
  }
  dx = p[0] - x; dy = p[1] - y;
  return dx * dx + dy * dy;
}

/** Signed distance from p to the polygon's edges: positive inside (outside the holes), negative outside. */
function signedDist(p, rings) {
  let inside = false; let min = Infinity;
  for (const ring of rings) {
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const a = ring[i]; const b = ring[j];
      if ((a[1] > p[1]) !== (b[1] > p[1]) && p[0] < ((b[0] - a[0]) * (p[1] - a[1])) / (b[1] - a[1]) + a[0]) inside = !inside;
      min = Math.min(min, segDist2(p, a, b));
    }
  }
  return (inside ? 1 : -1) * Math.sqrt(min);
}

/**
 * The label point of a GeoJSON Polygon or MultiPolygon as Leaflet [lat, lon]: the pole of inaccessibility of its
 * largest part, found by recursive cell subdivision (precision: 1 % of the part's size). Null without a polygon.
 */
export function labelPoint(geometry) {
  const polys = geometry?.type === 'Polygon' ? [geometry.coordinates] : geometry?.type === 'MultiPolygon' ? geometry.coordinates : [];
  const usable = polys.filter((p) => Array.isArray(p?.[0]) && p[0].length >= 4);
  if (!usable.length) return null;
  // Work in a flat local projection so a degree of longitude and of latitude weigh the same.
  const lat0 = usable[0][0][0][1] * Math.PI / 180;
  const k = Math.cos(lat0) || 1;
  const rings = usable
    .map((p) => p.map((r) => r.map(([lon, lat]) => [lon * k, lat])))
    .sort((a, b) => ringArea(b[0]) - ringArea(a[0]))[0];
  const outer = rings[0];
  const xs = outer.map((q) => q[0]); const ys = outer.map((q) => q[1]);
  const minX = Math.min(...xs); const minY = Math.min(...ys); const maxX = Math.max(...xs); const maxY = Math.max(...ys);
  const size = Math.min(maxX - minX, maxY - minY);
  const precision = Math.max(maxX - minX, maxY - minY) / 100;
  if (!size) return [outer[0][1], outer[0][0] / k];
  const cell = (x, y, h) => { const d = signedDist([x, y], rings); return { x, y, h, d, max: d + h * Math.SQRT2 }; };
  const queue = [];
  for (let x = minX; x < maxX; x += size) for (let y = minY; y < maxY; y += size) queue.push(cell(x + size / 2, y + size / 2, size / 2));
  // Start from the middle of the bounding box; any cell that is deeper inside replaces it.
  let best = cell((minX + maxX) / 2, (minY + maxY) / 2, 0);
  for (let guard = 0; queue.length && guard < 20000; guard++) {
    let i = 0;
    for (let j = 1; j < queue.length; j++) if (queue[j].max > queue[i].max) i = j;
    const c = queue.splice(i, 1)[0];
    if (c.d > best.d) best = c;
    if (c.max - best.d <= precision) continue;
    const h = c.h / 2;
    queue.push(cell(c.x - h, c.y - h, h), cell(c.x + h, c.y - h, h), cell(c.x - h, c.y + h, h), cell(c.x + h, c.y + h, h));
  }
  return [best.y, best.x / k];
}

/**
 * Vertical offsets (px) so that no two labels overlap. Boxes are {x, y, w, h, priority} in screen pixels; higher
 * priority keeps its place. Each lower one takes the nearest free spot directly above or below the names it would
 * cover (the shorter move keeps it near its own zone). Returns offsets in input order.
 */
export function resolveOverlaps(boxes, { gap = 2 } = {}) {
  const order = boxes.map((b, i) => i).sort((a, b) => (boxes[b].priority - boxes[a].priority) || (a - b));
  const placed = [];
  const dy = boxes.map(() => 0);
  const across = (b, p) => b.x < p.x + p.w + gap && p.x < b.x + b.w + gap;
  const hits = (b, y, p) => across(b, p) && y < p.y + p.h + gap && p.y < y + b.h + gap;
  for (const i of order) {
    const b = boxes[i];
    const near = placed.filter((p) => across(b, p));
    const spots = [b.y, ...near.flatMap((p) => [p.y - b.h - gap, p.y + p.h + gap])]
      .sort((m, n) => Math.abs(m - b.y) - Math.abs(n - b.y));
    const y = spots.find((s) => !near.some((p) => hits(b, s, p))) ?? b.y;
    dy[i] = y - b.y;
    placed.push({ x: b.x, y, w: b.w, h: b.h });
  }
  return dy;
}
