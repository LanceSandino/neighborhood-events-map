# Design references and fidelity

Source of truth: the original HTML mock (`prototype/index.html`) and five screenshots in the original design blueprint (desktop 1440×1000, mobile full page 390×2088, mobile first screen 390×844, mobile registration 390×844, concept board). The app keeps the mock's tokens and structure:

- Dark navy 226 px rail with brand, Map / My walking route / List my house / Event info (+ "Manage my house"), footer "Handing out treats?" panel.
- White top bar with locality breadcrumb, status pill (FICTIONAL DEMO / SETUP IN REVIEW instead of PROTOTYPE) and event badge; "+ List your home".
- Hero "Little ghosts. **Big adventures.**" (configurable), moon/ghost artwork, four stat chips (homes, candy, low-sensory, zones or hours).
- Search/filter row (search, zone select, Candy, Low sensory, Filters), expandable chips, 346 px card list + map with category-coloured pins (candy/gentle/spooky), legend, tools, selected-home panel, footer note; "My route" under the map (Show on map and Share on top, saved routes), with everything outside the neighborhood dimmed.
- Mobile: map-first, then My route, then 2-column cards; fixed three-tab bottom navigation (Map · Rules · My house); bottom sheets for registration, rules and reviews; 44 px targets; no horizontal scroll; reduced-motion respected.

Deliberate changes from the mock: real Leaflet/OSM map instead of the SVG sketch; zoom/locate tools; status chips (handing out / starts later / paused / out of candy / done); "Report this listing"; brand colours from configuration; no "Demo home" data unless the fictional demo is explicitly enabled.

Appearance follows the browser/system light or dark setting (`demo-dark-{375,1440}.png`). Screenshots of this build (Playwright, `bin/e2e.sh`): `screenshots/demo-{375,430,768,1440}.png` (fictional Maple Hollow demo), `screenshots/ventura-{375,430,768,1440}.png` (Ventura Reserve real-plat example, boundary pending review, no listings), `screenshots/demo-register-390.png`, `screenshots/ventura-admin-{overview,map}.png`.
