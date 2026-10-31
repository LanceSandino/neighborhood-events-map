/**
 * @module assets/js/events/index
 * @description The browser side of the event-type registry (src/Event/EventTypeRegistry.php): event modules by
 *     their immutable key. Core modules ask eventType(C.event.type) and never branch on a key.
 * @dependencies events/halloween.js
 */
import * as halloween from './halloween.js';

/** The type of a community whose settings name none (the server says the same). */
export const DEFAULT_EVENT_TYPE = 'halloween';

const TYPES = Object.freeze({ halloween });

/** The module of an event type; an unknown key is a programming error (the server validates event.type). */
export function eventType(key = DEFAULT_EVENT_TYPE) {
  if (!Object.hasOwn(TYPES, key)) throw new Error(`Unknown event type: ${key}`);
  return TYPES[key];
}

export const eventTypeKeys = () => Object.keys(TYPES);
