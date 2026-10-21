/**
 * @module assets/js/lib/address
 * @description Address helpers for the claim and review forms (pure, testable). A request that names a
 *     registry address by id also sends its house number, which the server matches against the registry.
 */

/** House number of a display address ("12A Pumpkin Ln Unit 3" → "12A"): the registry builds it first. */
export function houseNumberOf(address) {
  return String(address ?? '').trim().split(/\s+/)[0] || '';
}
