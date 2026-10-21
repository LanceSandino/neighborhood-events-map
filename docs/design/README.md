# Design references and fidelity

Source of truth: the original HTML mock and five screenshots in the original design blueprint (neither is part of this repository; desktop 1440×1000, mobile full page 390×2088, mobile first screen 390×844, mobile registration 390×844, concept board). The app keeps the mock's tokens and structure, with the later changes recorded in `DECISIONS.md`:

- Dark navy 226 px rail with the brand ("<community> <event name>"), the same three places as the phone tabs (Map · Rules · My house, D-20261020-02), and the "Handing out treats?" panel.
- White top bar with locality breadcrumb, status pill (FICTIONAL DEMO / SETUP IN REVIEW instead of PROTOTYPE) and event badge; "+ List your home".
- Hero "Little ghosts. **Big adventures.**" (configurable) and moon/ghost artwork; no summary stat cards (D-20261021-01).
- Search/filter row: search, zone select, quick filters as icon + count buttons (Candy, Sensory-friendly, 21+, Saved) and Filters for the rest, 346 px card list + map with category-coloured pins (candy/gentle/spooky), legend, tools, selected-home panel, footer note; "My route" under the map (Show on map and Share on top, saved routes), with everything outside the neighborhood dimmed.
- Mobile: map-first, then My route, then 2-column cards; fixed three-tab bottom navigation (Map · Rules · My house); bottom sheets for registration, rules and reviews; 44 px targets; no horizontal scroll; reduced-motion respected.

Deliberate changes from the mock: real Leaflet/OSM map instead of the SVG sketch; zoom/locate tools; status chips (handing out / starts later / paused / out of candy / done); "Report this listing"; brand colours from configuration; no "Demo home" data unless the fictional demo is explicitly enabled.

Appearance follows the browser/system light or dark setting unless the visitor picks Light or Dark (or larger text, more contrast, reduced motion) in the header's Appearance panel (D-20261019-16; `demo-dark-{375,1440}.png`). Screenshots of this build (Playwright, `bin/e2e.sh`): `screenshots/demo-{375,430,768,956,1180,1440}.png` (fictional Maple Hollow demo), `screenshots/ventura-{375,430,768,956,1180,1440}.png` (Ventura Reserve real-plat example, boundary pending review, no listings), `screenshots/demo-register-390.png`, `screenshots/ventura-admin-{overview,map}.png`.
