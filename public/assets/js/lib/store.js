/**
 * @module assets/js/lib/store
 * @description Browser-only preferences (filters, saved homes, chosen start, own stop order) namespaced by
 *     community and season year. Nothing here is sent to the server; GPS is never stored.
 */
export function createStore(communityId, year, storage = globalThis.localStorage) {
  const key = `nhm:${communityId}:${year}`;
  const defaults = { saved: [], filters: {}, start: '', order: [] };
  let state;
  try { state = { ...defaults, ...JSON.parse(storage.getItem(key) || '{}') }; } catch { state = { ...defaults }; }
  if (!Array.isArray(state.saved)) state.saved = [];
  if (!Array.isArray(state.order)) state.order = [];
  return {
    get: () => state,
    set(patch) {
      state = { ...state, ...patch };
      try { storage.setItem(key, JSON.stringify(state)); } catch { /* private mode: keep in memory */ }
      return state;
    },
    toggleSaved(id) {
      const saved = state.saved.includes(id) ? state.saved.filter((x) => x !== id) : [...state.saved, id];
      return this.set({ saved });
    },
    key,
  };
}
