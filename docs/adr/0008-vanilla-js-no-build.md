# ADR 0008 — Vanilla JS modules, vendored assets, no build step

**Context.** Low maintenance, auditable, strict CSP, privacy (no third-party CDNs at runtime).

**Decision.** ES modules served as-is; DOM-free libraries (`public/assets/js/lib/`) are unit-tested with `node:test`; Leaflet 1.9.4, Leaflet.draw 1.0.4 and the Nunito/DM Sans fonts are vendored under `public/`. Node is used only for tests (Playwright, node:test), never to build the app.

**Consequences.** No bundler; modern browsers required (ES modules, Web Workers).
