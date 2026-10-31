/**
 * @module assets/js/lib/share
 * @description Shared-link checks (DOM-free). A link carries e=<event id> since 0.11; one made for another event must
 *     not show its stops (home ids are addresses, which the current event may list with other details). A link
 *     without e (made before 0.11) is treated as this event's.
 * @dependencies none
 */

/** True when the link names an event other than the one on the map (a damaged value counts as another event). */
export function otherEvent(linkEvent, currentEventId) {
  if (linkEvent === null || linkEvent === undefined || linkEvent === '') return false;
  return !/^\d{1,12}$/.test(String(linkEvent)) || Number(linkEvent) !== Number(currentEventId);
}
