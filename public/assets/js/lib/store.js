/**
 * @module assets/js/lib/store
 * @description Browser-only preferences (filters, saved homes, chosen start, own stop order, skipped stops) namespaced by
 *     community and event (one-time move of the 0.10 year store, migrateYearStore), plus named saved routes: each keeps its own copy of those route fields, and edits go
 *     to the active route (or to the unsaved draft). Nothing here is sent to the server; GPS is never stored.
 */
const ROUTE_FIELDS = ['saved', 'filters', 'start', 'order', 'skipped', 'routeAll'];
const MAX_ROUTES = 12;
const NAME_MAX = 40;

const pick = (s) => Object.fromEntries(ROUTE_FIELDS.map((k) => [k, s[k]]));
const ids = (v) => (Array.isArray(v) ? v.filter((n) => Number.isInteger(n) && n > 0) : []);
/** One route's fields, repaired: anything damaged falls back to an empty value. */
function clean(r) {
  return {
    saved: ids(r.saved), order: ids(r.order), skipped: ids(r.skipped),
    filters: r.filters && typeof r.filters === 'object' && !Array.isArray(r.filters) ? r.filters : {},
    start: typeof r.start === 'string' ? r.start : '', routeAll: r.routeAll === true,
  };
}

/**
 * Since 0.11 the store is namespaced by community and EVENT id (nhm:<community>:<eventId>); 0.10 and earlier used the
 * season's year. This moves a year store into the active Halloween event's store once and removes it, so visitors keep
 * their saved homes and routes and the old store can never reach a later event. Returns true when it moved one.
 */
export function migrateYearStore(communityId, year, eventId, storage = globalThis.localStorage) {
  const from = `nhm:${communityId}:${year}`;
  const to = `nhm:${communityId}:${eventId}`;
  if (from === to) return false;
  try {
    const old = storage.getItem(from);
    if (old === null) return false;
    if (storage.getItem(to) === null) storage.setItem(to, old);
    storage.removeItem(from);
    return true;
  } catch { return false; /* private mode: nothing stored, nothing to move */ }
}

/** @param {string} namespace the event id (0.10 and earlier: the season's year) */
export function createStore(communityId, namespace, storage = globalThis.localStorage) {
  const key = `nhm:${communityId}:${namespace}`;
  const defaults = { saved: [], filters: {}, start: '', order: [], skipped: [], routeAll: false, view: 'cards', routes: [], activeRoute: null, draft: null };
  let state;
  try { state = { ...defaults, ...JSON.parse(storage.getItem(key) || '{}') }; } catch { state = { ...defaults }; }
  state = { ...state, ...clean(state) };
  state.routes = (Array.isArray(state.routes) ? state.routes : [])
    .filter((r) => r && typeof r === 'object' && typeof r.id === 'string' && typeof r.name === 'string' && r.name.trim())
    .slice(0, MAX_ROUTES).map((r) => ({ id: r.id, name: r.name.slice(0, NAME_MAX), ...clean(r) }));
  if (!state.routes.some((r) => r.id === state.activeRoute)) state.activeRoute = null;
  if (state.draft && typeof state.draft === 'object') state.draft = clean(state.draft); else state.draft = null;

  const persist = () => { try { storage.setItem(key, JSON.stringify(state)); } catch { /* private mode: keep in memory */ } return state; };
  return {
    get: () => state,
    set(patch) {
      state = { ...state, ...patch };
      // Route fields belong to the active saved route, so every edit is kept with it.
      if (state.activeRoute && ROUTE_FIELDS.some((k) => k in patch)) {
        state.routes = state.routes.map((r) => (r.id === state.activeRoute ? { ...r, ...pick(state) } : r));
      }
      return persist();
    },
    toggleSaved(id) {
      const saved = state.saved.includes(id) ? state.saved.filter((x) => x !== id) : [...state.saved, id];
      return this.set({ saved });
    },
    /**
     * Saves a route under a name and makes it active: the current stops, filters, start and order, or the given
     * fields (a route someone shared). Returns its id, or null without a name.
     */
    saveRoute(name, fields = null) {
      const label = String(name || '').trim().slice(0, NAME_MAX);
      if (!label) return null;
      const id = `r${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
      const draft = state.activeRoute ? state.draft : pick(state);
      const route = { id, name: label, ...(fields ? clean({ ...clean({}), ...fields }) : pick(state)) };
      state = { ...state, ...clean(route), draft, activeRoute: id, routes: [...state.routes, route].slice(-MAX_ROUTES) };
      persist();
      return id;
    },
    /** Opens a saved route (or, with null, the unsaved draft as it was left). */
    switchRoute(id) {
      const target = id ? state.routes.find((r) => r.id === id) : null;
      if (id && !target) return state;
      const draft = state.activeRoute ? state.draft : pick(state);
      const fields = target ? clean(target) : clean(draft || {});
      state = { ...state, ...fields, draft, activeRoute: target ? target.id : null };
      return persist();
    },
    deleteRoute(id) {
      if (state.activeRoute === id) this.switchRoute(null); // back to the draft before the route goes
      state = { ...state, routes: state.routes.filter((r) => r.id !== id) };
      return persist();
    },
    key,
  };
}
