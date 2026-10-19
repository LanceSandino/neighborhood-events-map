# ADR 0002 — No PostGIS: geometry in PHP

**Context.** Eligibility needs point-in-polygon for at most ~1,000 addresses and a few polygons; boundary uploads need strict validation.

**Decision.** Store GeoJSON in JSONB; validate on write in `App\Geo\GeoJsonParser` (closed rings, ranges, finite numbers, non-zero area, no self-intersection, holes inside shells, vertex/size limits, likely `[lat, lon]` swaps against the configured centre). Evaluate containment with an edge-inclusive even-odd test (`PolygonSet`). Zone overlaps are estimated by grid sampling and surfaced as warnings; a point inside two zones is rejected as ambiguous instead of guessed.

**Consequences.** No extra database extension; tests cover Polygon, MultiPolygon, holes, edges, overlaps and real county plats (the Ventura Reserve example; the PHP overlap estimate agrees with Shapely within ~10 % on real plat data). Validation of very large polygons is O(n²) but bounded by limits (5,000 vertices/ring, 20,000 total).

**Alternatives.** PostGIS (heavier operations, not needed), client-side Turf only (not authoritative).
