/**
 * @module assets/js/lib/time
 * @description Event-timezone clock helpers ("open now", status labels) using Intl only. The status of a home is
 *     the event type's to say (ctx.type, the module from events/index.js; Halloween when absent).
 * @dependencies lib/clock.js, events/index.js
 */
import { eventType } from '../events/index.js';

export { nowInZone, toMinutes, time12 } from './clock.js';

/** @returns {{key: string, label: string}} */
export function statusOf(home, ctx) {
  return ((ctx && ctx.type) || eventType()).statusOf(home, ctx);
}
