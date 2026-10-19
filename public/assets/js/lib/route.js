/**
 * @module assets/js/lib/route
 * @description Suggested STOP ORDER (not walking directions). Stops are split into the organizer's
 *     verified walkable groups (or one group per zone, or one group when there are no zones); groups
 *     are never connected to each other. Within a group: nearest-neighbour from the chosen start (a stop,
 *     or a point such as the visitor's location), then 2-opt on straight-line distance. A visitor can
 *     reorder stops within a trip. No path geometry, distance or time is produced.
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
  // The start is either one of the stops, or a place that is not a stop (e.g. the visitor's location).
  const point = start && !stops.some((s) => s.id === start.id) ? start : null;
  const result = [];
  for (const [key, list] of grouped) {
    const startHere = !point && start && list.some((s) => s.id === start.id) ? start : null;
    const pool = startHere ? list.filter((s) => s.id !== start.id) : list;
    let ordered = orderGroup(pool, startHere || point);
    if (startHere) ordered = [startHere, ...ordered];
    result.push({ key, zones: [...new Set(list.map((s) => s.zone).filter(Boolean))], stops: ordered.slice(0, maxPerGroup), truncated: ordered.length > maxPerGroup,
      near: point && list.length ? Math.min(...list.map((s) => haversine(point, s))) : 0 });
  }
  // The trip with the chosen start (or the one nearest the start point) goes first; others keep a stable order.
  const hasStart = (g) => Number(Boolean(start) && g.stops.some((s) => s.id === start.id));
  result.sort((a, b) => (point ? a.near - b.near : hasStart(b) - hasStart(a)) || a.key.localeCompare(b.key, undefined, { numeric: true }));
  return result.map(({ near, ...g }) => g);
}

/**
 * Applies a visitor's own stop order (a list of ids) inside each trip: known stops follow that order,
 * stops added since then keep their suggested place after them. Trips are never merged.
 */
export function applyOrder(plan, order) {
  if (!order || !order.length) return plan;
  const rank = new Map(order.map((id, i) => [id, i]));
  return plan.map((g) => {
    const known = g.stops.filter((s) => rank.has(s.id)).sort((a, b) => rank.get(a.id) - rank.get(b.id));
    return { ...g, stops: [...known, ...g.stops.filter((s) => !rank.has(s.id))] };
  });
}

/** Moves one stop up (-1) or down (+1) within its trip; returns the new id order of all stops. */
export function moveStop(plan, id, delta) {
  const next = plan.map((g) => {
    const i = g.stops.findIndex((s) => s.id === id);
    const j = i + delta;
    if (i < 0 || j < 0 || j >= g.stops.length) return g;
    const stops = [...g.stops];
    [stops[i], stops[j]] = [stops[j], stops[i]];
    return { ...g, stops };
  });
  return next.flatMap((g) => g.stops.map((s) => s.id));
}
