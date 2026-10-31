/**
 * @module assets/js/lib/share
 * @description Shared-link checks (DOM-free). A link carries e=<event id> since 0.11; one made for another event must
 *     not show its stops (home ids are addresses, which the current event may list with other details). A link
 *     without e (made before 0.11) is treated as this event's. Reads a shared link's filters, stops, start and order.
 * @dependencies none
 */

/** True when the link names an event other than the one on the map (a damaged value counts as another event). */
export function otherEvent(linkEvent, currentEventId) {
  if (linkEvent === null || linkEvent === undefined || linkEvent === '') return false;
  return !/^\d{1,12}$/.test(String(linkEvent)) || Number(linkEvent) !== Number(currentEventId);
}

/**
 * A shared link (?f=<filters>&stops=<ids>&start=<id>&o=1&e=<event id>): its filters (cleaned by `clean`), stops, start
 * and manual order; a link for another event gives only {otherEvent: true}. A damaged filter part is ignored.
 */
export function readSharedLink(search, currentEventId, clean) {
  const q = new URLSearchParams(search);
  const out = {};
  if (otherEvent(q.get('e'), currentEventId)) { out.otherEvent = true; return out; }
  try {
    if (q.get('f')) out.filters = clean(JSON.parse(atob(q.get('f').replace(/-/g, '+').replace(/_/g, '/'))));
  } catch { /* ignore a damaged link */ }
  const ids = (q.get('stops') || '').split('.').map(Number).filter((n) => Number.isInteger(n) && n > 0).slice(0, 60);
  if (ids.length) { out.stops = ids; out.start = Number(q.get('start')) || null; out.order = q.get('o') === '1'; }
  return out;
}
