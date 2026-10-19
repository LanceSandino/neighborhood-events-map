# ADR 0006 — GeoJSON boundary editor, versioning and approval gate

**Context.** Organizers must draw or import real boundaries (disconnected areas allowed) and optional zones; imported official plats may not equal HOA eligibility.

**Decision.** One canonical FeatureCollection format (features tagged `kind: boundary|zone`, zones by `zone_key`) for import, export and the Leaflet.draw editor. Every save creates a new version (history kept), resets approval and recomputes address membership; shrinking the boundary around existing claims needs explicit confirmation and hides affected listings without deleting ownership. Claims open only when the active boundary is approved and registration is enabled. The editor splits MultiPolygons into editable parts and regroups them on save at full precision, so unchanged geometry round-trips byte-for-byte.

**Consequences.** Safe iteration on geometry; imported real-plat boundaries (e.g. the Ventura Reserve example) ship "pending review".
