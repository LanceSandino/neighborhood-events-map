# ADR 0007 — Suggested stop order, never invented paths

**Context.** Straight lines between homes can cross lakes, fences, gates or a major road. An invalid crossing route is worse than none.

**Decision.** The default "route" is an ordered stop list: stops are split into organizer-configured walkable groups (or one group per zone; one group without zones), ordered by nearest-neighbour + 2-opt on straight-line distance, and shown as separate trips with no path, distance or time. A provider path (openrouteservice, server-side key, cached) is available only when the organizer enabled it **and** verified crossings, and only within one group.

**Consequences.** Families get useful order immediately without any fabricated geometry.
