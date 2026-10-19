/**
 * @module assets/js/lib/route
 * @description Suggested STOP ORDER (not walking directions). Stops are split into the organizer's
 *     verified walkable groups (or one group per zone, or one group when there are no zones); groups
 *     are never connected to each other. Within a group: nearest-neighbour from the chosen start, then
 *     2-opt on straight-line distance. No path geometry, distance or time is produced.
 */
export function haversine(a, b) {
  const R = 6371008.8, toRad = (d) => (d * Math.PI) / 180;
  const dLat = toRad(b.lat - a.lat), dLon = toRad(b.lon - a.lon);
  const s = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLon / 2) ** 2;
  return 2 * R * Math.asin(Math.min(1, Math.sqrt(s)));
}

export function groupStops(stops, groups, zonesEnabled) {
  const out = new Map();
  for (const s of stops) {
    let key = 'all';
    if (zonesEnabled && s.zone) {
      const gi = groups.findIndex((g) => g.includes(s.zone));
      key = gi >= 0 ? `group-${gi}` : `zone-${s.zone}`;
    } else if (zonesEnabled) {
      key = 'zone-none';
    }
    if (!out.has(key)) out.set(key, []);
    out.get(key).push(s);
  }
  return out;
}

const pathLength = (p) => p.slice(1).reduce((sum, s, i) => sum + haversine(p[i], s), 0);

export function orderGroup(stops, start = null) {
  if (stops.length <= 2) return start && stops.length === 2 && haversine(start, stops[1]) < haversine(start, stops[0]) ? [stops[1], stops[0]] : [...stops];
  const remaining = [...stops];
  let current = start || remaining.reduce((w, s) => (s.lon < w.lon || (s.lon === w.lon && s.lat > w.lat) ? s : w), remaining[0]);
  const path = [];
  if (!start) { path.push(current); remaining.splice(remaining.indexOf(current), 1); }
  while (remaining.length) {
    let best = 0;
    for (let i = 1; i < remaining.length; i++) if (haversine(current, remaining[i]) < haversine(current, remaining[best])) best = i;
    current = remaining.splice(best, 1)[0];
    path.push(current);
  }
  // 2-opt on the open path (start point, if any, is fixed in front).
  const anchor = start ? [start] : [];
  let improved = true;
  let route = [...anchor, ...path];
  const first = start ? 1 : 0;
  for (let pass = 0; improved && pass < 50; pass++) {
    improved = false;
    for (let i = first; i < route.length - 1; i++) {
      for (let k = i + 1; k < route.length; k++) {
        const candidate = [...route.slice(0, i), ...route.slice(i, k + 1).reverse(), ...route.slice(k + 1)];
        if (pathLength(candidate) + 1e-6 < pathLength(route)) { route = candidate; improved = true; }
      }
    }
  }
  return start ? route.slice(1) : route;
}

/**
 * @returns {Array<{key: string, zones: string[], stops: object[], truncated: boolean}>}
 */
export function planStops(stops, { groups = [], zonesEnabled = false, start = null, maxPerGroup = 20 } = {}) {
  const grouped = groupStops(stops, groups, zonesEnabled);
  const result = [];
  for (const [key, list] of grouped) {
    const startHere = start && list.some((s) => s.id === start.id) ? start : null;
    const pool = startHere ? list.filter((s) => s.id !== start.id) : list;
    let ordered = orderGroup(pool, startHere);
    if (startHere) ordered = [startHere, ...ordered];
    result.push({ key, zones: [...new Set(list.map((s) => s.zone).filter(Boolean))], stops: ordered.slice(0, maxPerGroup), truncated: ordered.length > maxPerGroup });
  }
  // The group containing the chosen start goes first; others keep a stable order.
  result.sort((a, b) => (start ? Number(b.stops.some((s) => s.id === start.id)) - Number(a.stops.some((s) => s.id === start.id)) : 0) || a.key.localeCompare(b.key, undefined, { numeric: true }));
  return result;
}
