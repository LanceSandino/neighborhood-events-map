# Changelog

All notable user-facing changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Engineering change records (with regression-test anchors) live in `CHANGES.md`.

## [Unreleased]

## [0.8.0-beta.2] - 2026-10-24

Security release. Update now (pull `0.8.0-beta.2` and recreate the stack; no data changes).

### Security
- **CVE-2026-115560** (PHP, announced 23 October 2026, no fixed PHP release yet): session upload progress stays off and the app never changes the session serializer at runtime, which together close it. PHP 8.5.12 already fixes CVE-2026-109618 and CVE-2026-109619.
- **CVE-2026-111497** (Brotli decoder out-of-bounds write): both images now carry brotli 1.2.1; the base images had not been rebuilt with it yet, so the images now take Alpine's security fixes at build time.
- Only the three organizer upload endpoints (logo, GeoJSON and CSV imports) accept files, in their own small PHP pool; every other path refuses a multipart body before PHP reads it, so PHP's file-upload code is unreachable for visitors.
- PHP hardening: no backtraces in fatal errors, no `.user.ini` files, one file per upload, assertions compiled out and no argument values in error traces in production; web workers cannot run programs or open URLs as files.
- The home list and the selected-home panel escape home ids too (found by CodeQL), so a tampered page-data value can never become markup.

### Changed
- Doctrine bundles 3 and 4 (DBAL-only, no visible change), PHPUnit 13, PHPStan 2.3.2; current base-image digests (PHP 8.5.12, PostgreSQL 18.6, composer 2.10.3) and Mailpit 1.32.0 for development.

### Added
- Security checks: CI scans the built images with Trivy and audits the end-to-end tools' npm lock file; a daily workflow re-checks the published images (newest PHP of its line, no fixed HIGH/CRITICAL package) and the lock files.

## [0.8.0-beta.1] - 2026-10-21

### Upgrade notes
- **PostgreSQL 18:** this release moves the database from PostgreSQL 17 to 18, which cannot open 17's data files. After updating the checkout and pulling the images, run `bin/upgrade-postgres.sh` **before** `docker compose up` (see `docs/runbooks/upgrade-rollback.md`). It backs up, restores into a new volume and checks every table; your old data stays in the old volume for a rollback.

### Changed
- PHP 8.5 (from 8.3) and PostgreSQL 18 (from 17): every part of the stack now runs its latest stable release.
- Development and test tools: PHPUnit 12, Node 24 LTS, Python 3.14, Mailpit 1.31.6 and current GitHub Actions.

## [0.7.0-beta.6] - 2026-10-21

### Security
- PHP 8.3.36: fixes the two critical remote code execution flaws in PHP core announced on 21 October 2026 (CVE-2026-109618, CVE-2026-109619). Update now.
- The images carry the latest CA certificates (the compromised eMudhra intermediate CAs are no longer trusted) and OpenSSL 3.5.9.
- PHP's session upload progress, which the app never used, is switched off: it was the documented way in for one of the flaws.

## [0.7.0-beta.5] - 2026-10-21

### Changed
- The privacy notice lists every cookie and storage the site uses and explains why there is no cookie banner. Sites behind Cloudflare set `CLOUDFLARE_PROXY=1` so the notice also says that Cloudflare carries requests and may set its security cookies (`__cf_bm`, `cf_clearance`).
- The privacy notice says sign-in emails go out through the organizer's email delivery service, and its "Last updated" date no longer changes every month.

## [0.7.0-beta.4] - 2026-10-21

### Added
- Optional, cookie-free **Cloudflare Web Analytics** for sites behind Cloudflare: set `CLOUDFLARE_WEB_ANALYTICS=1` and Cloudflare's visit statistics work; the footer and privacy page then say so. Off by default, and it changes nothing on sites that don't use Cloudflare.

### Changed
- The deployment guide works with any HTTPS reverse proxy or tunnel (with a Caddy example); Cloudflare is now an optional example in `docs/examples/cloudflare.md`.

## [0.7.0-beta.3] - 2026-10-21

### Fixed
- 0.7.0-beta.2's nginx served no stylesheets or scripts (every versioned asset was redirected), so pages loaded unstyled and the map did not start. The first deployment was rolled back within minutes; this release fixes it.

## [0.7.0-beta.2] - 2026-10-21

### Added
- **Sign out everywhere** on My house ends your session on every device (organizers: `app:user:sign-out <email>`).

### Changed
- The organizer's **Export claims** is now a button (the download works as before).
- Over HTTPS the sign-in cookie is named `__Host-nhm_session`, which browsers protect from other subdomains. Upgrading signs everyone out once.

### Fixed
- The map legend no longer covers the OpenStreetMap credit on phones and tablets.

### Security
- nginx counts an IPv6 visitor's whole /64 as one client, like the app, and one client can keep at most 6 requests in PHP (2 on the email pages), so nobody can occupy every worker.
- Restoring an older backup keeps accounts deleted and organizers revoked since that backup (`bin/restore.sh` re-applies an account ledger of ids and times).
- An interrupted backup no longer leaves a partial file behind, and `bin/backup-now.sh` never leaves a truncated dump.
- The maintenance container no longer receives mail or routing credentials.
- Every base image is pinned by digest (Dependabot keeps them current).
- The GIS importer fetches over https only and refuses responses over 64 MB.
- The public map data carries nothing of a boundary that is not approved yet, not even its extent.
- Oversized GeoJSON uploads are refused before the expensive shape checks run.
- The claims export can no longer be triggered by a link.

## [0.7.0-beta.1] - 2026-10-21

### Added
- Organizers can move a zone's name on the map: drag it on the Boundary & zones page (Reset on the Community page), or use `app:zone:label`.
- A code of conduct, a roadmap for Christmas lights and garage sale maps, and ADR 0010 (generic event types, proposed).

### Changed
- Pins and list cards show the kind you filter by (tick 21+ and those homes show 🍺); the map legend now includes 🍺 21+.
- The site and its emails name the community's own event instead of always saying Halloween: organizers set the **Event name** under Organizer → Community → Event (it stays "Halloween" unless changed). Page titles, the sidebar, footer, search description and email subjects read "<community> <event name>".
- Documentation matches this release: event-neutral naming, current filters and tabs, private vulnerability reporting, and checks for pull-request refs before going public.

### Fixed
- No more thin bright line between map tiles at some zoom levels.
- Saving the Community page no longer drops zone descriptions.
- Zone names on the map sit inside their zones and no longer cover each other (a common area’s name moves just above or below a phase’s name).
- The whole `deploy/` folder is ignored by Git, as the README says, so your own community settings and boundary in `deploy/community/` cannot be committed by accident.
- Package metadata declares the MIT license like `LICENSE`, and three framework bundles are held to their current major versions.

### Security
- GitHub Actions are pinned to exact commits, checkouts keep no credentials they do not need, and a release is drafted only for an annotated tag on `main`.
- `bin/restore.sh` only accepts a plain backup file name.
- The public map data no longer names a hidden zone in its walking groups.
- The example's GIS review page shipped in the repository now uses the text-only, integrity-checked version too.
- Shared map links keep only a real "visit at" time and a zone of sensible length.

## [0.6.0-beta.2] - 2026-10-21

### Security
- Web addresses with percent-encoded characters in the path are refused. They could skip the sign-in idle timeout and a page-freshness check, sign a visitor out from another site, and slip past the server's request limits.
- PHP no longer listens on a network port: the web server reaches it through a private socket, so nothing else on the server can talk to it. Sign-in and review-request emails run in their own small worker pool, so a flood of them can't slow down the map.
- Homes in a hidden area are no longer shown when opened by number, and "you can't list here" messages never name a hidden area.
- Choosing an address from the list now also sends its house number, so nobody can collect the neighborhood's address list by trying numbers one after another.
- A home released by an earlier claimant no longer keeps that person's map pin; the next resident places their own.
- Email addresses must be plain addresses (no quoted names inside), so a sign-in link always goes to the address that signs in.
- Organizers get the same "too many requests" reply as everyone else when the hourly sign-in budget is full (their link is still sent), so the reply never reveals who is an organizer.
- Saving your listing is limited to 60 times an hour, walking-path lookups share a daily budget that protects the provider's quota, and rate-limit records are deleted as soon as they stop counting.
- A shared map link with broken filter settings can no longer break the map on your device.
- Addresses accept only letters, numbers, spaces and `. , ' - #`; anything else is refused instead of being quietly changed. Notes, review requests and organizer texts lose hidden characters that could make text read differently than it is.
- Production refuses to start in debug mode, accepts only its exact web address, and never bakes a deployment's private settings into an image.

### Changed
- `app:admin:grant --revoke` now fails and explains why when `ORGANIZER_EMAILS` still lists the address; `app:user:delete` also erases someone who only sent a review request.
- Local and staging only: Mailpit's inbox page can require a password (`MAILPIT_UI_AUTH`) for testing from phones on your network.

### Fixed
- Building from source on Docker hosts that use the containerd image store no longer fails with "image already exists" (the maintenance service reuses the app image).
- Recreating only the app container no longer leaves the web server answering 502.
- "Apt #4" is read as unit 4.

## [0.6.0-beta.1] - 2026-10-21

### Added
- Ready-made images for every release (amd64 and arm64) on GitHub Container Registry: a server runs `docker compose pull && docker compose up -d` with the release pinned in `APP_IMAGE_TAG`, so nothing is built on the server. Building from source still works.
- `compose.staging.yaml`: run the production image and settings with Mailpit for sign-in emails, to test behind a tunnel before real SMTP is set up.

### Changed
- "What's new" never shows unreleased changes.
- One page for families: **My route** now sits right under the map (between the map and the list on phones), with **Share** and **Show on map** at the top. The copy-as-text button and the link box are gone.
- Save routes by name (for example "Little kids" and "Big kids"): each keeps its own saved homes and filters, and you switch between them from a short list. They stay on your phone only.
- The bottom tabs are now **Map · Rules · My house**, and the sidebar on computers offers the same three.
- The map stays on the neighborhood: everything outside it is dimmed, and you can't pan or zoom far away.
- Simpler filters, the same on phones and computers: Candy, Sensory-friendly, 21+ and Saved are icons with their counts (the summary cards above them are gone); Filters opens the rest. The map zooms to the homes that match whenever you change a filter.

### Fixed
- Dark mode on iPhone and iPad: the map now turns dark too, the zone labels on the map follow the theme, and drop-down menus no longer show as grey boxes.

## [0.5.0-beta.4] - 2026-10-19

### Changed
- Faster pages: nginx compresses text responses (about 70 % smaller), the map's stylesheet no longer blocks the first paint, and the browser connects to the map-tile server early. Lighthouse (mobile, development stack): Performance 79 → 91.
- Production PHP preloads Symfony's most-used classes into OPcache when PHP-FPM starts; the PHP-FPM worker pool is set from `.env` (`FPM_MAX_CHILDREN`…). The upgrade runbook recreates containers on every deploy, which is what clears OPcache.
- Small text is at least 12 px on phones (11 px for tags and badges), so it passes Lighthouse's legibility check.
- `robots.txt` lets search engines index the public map, About and policy pages but not the API, sign-in, resident or organizer pages.

## [0.5.0-beta.3] - 2026-10-19

### Changed
- The project is now **neighborhood-events-map** (repository renamed from neighborhood-halloween-map) so it can grow beyond Halloween; the old GitHub address redirects. The default source link (`APP_SOURCE_URL`) points to the new name.

## [0.5.0-beta.2] - 2026-10-19

### Fixed
- Homes already listed in a zone the organizer later sets to "Hidden" are no longer public (map, list, counts and API).

### Added
- Automated tests now also run the main visitor flows in WebKit (Safari's engine) on an iPhone-sized screen.

## [0.5.0-beta.1] - 2026-10-19

### Added
- **Walking areas in the zone filter**: the organizer's walkable groups appear as choices such as "Main (1–3)", "Second (4)" or "Phases 6A, 6B"; choosing one shows, outlines and routes all its zones together. Groups can be named (`routing.group_labels`, or "Name: zone-a, zone-b" in Organizer → Community); unnamed one-zone groups stay plain zone choices.
- **Counts on every filter**: each filter button, chip and zone choice shows how many homes handing out it would list, given the other filters.
- Route trips are named after their walking area, and a filter-based route shows the filters it used ("Filtered by: Main (1–3) · 🍬 Candy") with a link to change them.
- A small "back to top" button on long pages.
- The route sheet opens with a status line ("25 stops in 3 trips · from your location · 7 not handing out now · 2 skipped — Bring back") and says plainly when it shows only your saved homes.

### Fixed
- Routes no longer stop at 20 homes per trip, and ordering stays quick for hundreds of homes.
- The zone menu keeps its 📍 icon whatever is selected.

### Changed
- "No jump scares" and "No intense scares" are one filter, **No big scares**; cards show one "😱 Big scares" tag for either. Filters saved or shared by earlier versions carry over.
- **Kid-friendly** replaces "Stroller-friendly" in filters, tags and the listing form.
- One vocabulary for pins, legend, cards, filters and the listing form: 🍬 Treats, 🧩 Sensory-friendly (was "Gentle"/"Low sensory"; shown exactly when the filter would include the home) and 👻 Spooky; non-food treats show a teal pumpkin, the Teal Pumpkin Project's sign for allergy-friendly homes.
- About page: links, contact and "Open the map" at the top, then getting-around notes, About and reminders in clear sections. The map's quick guide is shorter: date and hours, access, getting around, three reminders and a link to the full guide.
- Footer: "Community Halloween · v… · What's new" on one line.
- The listing form ("Treats & environment") offers exactly the map's filter options with the same icons and words, from one shared template, and only those the community has turned on; flashing lights are 🔦 everywhere.
- Summary tiles: zones (or suggested hours), participating homes, low-sensory stops and 21+ refreshments (with candy when 21+ is turned off). The low-sensory count now matches the filter (intense setups are not low sensory).

## [0.4.0-beta.5] - 2026-10-19

### Added
- **Share a link to an exact view**: the route sheet's Share button and link field reproduce the same stops, filters, starting point and stop order for whoever opens it. Copy works on every phone, including iPhone over plain HTTP.
- **Start from your location**: the route can start from "📍 My current location" (asked once, kept only in the browser, never sent or put in links); the nearest trip and stop come first. Browsers allow location only over HTTPS, so the option appears only there.
- **Reorder stops**: ↑/↓ buttons move a stop within its trip; your order is kept on the device and in shared links, with "Use the suggested order" to undo.
- **Skip stops and add nearby ones**: ✕ removes a stop from your route (with "Bring them back"); with saved homes, the route offers nearby homes in the same walking area to add, and can switch between "Saved" and "All matching".
- **Show on map** from the route sheet; numbered pins follow the stop order.
- **Appearance panel** (🎨 in the header): Auto, Light or Dark, plus Larger text, More contrast and Reduce motion. Saved on the device only, applied before the page draws; your device's own accessibility settings still apply.
- **Trick-or-treat reminders** on the About page and in the map guide (porch lights, one piece of candy unless a sign says otherwise, look don't touch, watch for cars…); organizers can replace them with their own (`community.reminders`).
- Optional city under the community name (`branding.show_locality`), and the logo in the phone header (logo and name link to the map); logos keep a transparent background and a replaced logo shows at once.
- **Compact list** (☰ next to the count) for neighborhoods with many homes: one row per home with icons instead of tag words; long notes are cut to two lines on cards and shown as 📝 in the compact list (full note in the home's preview).
- Filter buttons have icons, and the Filters button shows how many filters are on.
- Choosing a phase/zone outlines it, shades the rest and zooms to it.
- **Zone status**: an organizer can mark a zone "Coming soon" (drawn with that label, homes cannot be listed yet, e.g. no houses built) or "Hidden" (not shown at all).
- **Approving new addresses**: with `registration.review_new_addresses`, a home that residents add themselves (address and pin) waits for an organizer's approval before it appears. Organizers see the waiting homes on the overview and approve them on the Addresses page; the resident sees "waiting for approval" on My house.
- When an address is not on the list, the form offers to add it with a pin (where allowed) or links to the organizer's contact. Adding a pin hides the list search until you go back to it.
- A refused pin says why (for example "Clubhouse is not a residential area" or "North Village is not open for listings yet") and links to the organizer, who can fix outdated map data.
- "What's new" dialog with these release notes and a link to the source code (version link in the footer and sidebar; also at `/changelog`). `APP_SOURCE_URL` points it at a fork or hides it.
- GIS importer: `reassign` moves official parcel or lot polygons from one zone to another (for example homes platted inside an amenities tract), with an explanatory note in the review manifest.

### Changed
- Homes that are paused, out of candy or done for the night no longer appear in the list or on routes (the map still shows them greyed); "Show N homes not handing out right now" lists them on request, and the route says how many were left out.
- Wording is for everyone (groups, friends or one person), not only families.
- On phones the filters sit in an even two-column grid so every label fits; the search icon is centred.
- Clearer icons: 😱 no jump scares, 🔦 no flashing lights, 🔊 no loud audio, 🎭 no intense scares, a teal pumpkin for non-food treats, as in the Teal Pumpkin Project (a heart now only means saved), 👶 stroller-friendly, 🍺 21+, 🧡 saved.
- The version appears once, in the footer next to the community name ("No ads · No tracking"; the OpenStreetMap credit at the end of the map page's footer); the event date in the header opens the About page.
- Phones in landscape get the phone layout (bottom bar, no side rail) with the list and map side by side, clear of the notch; tablets put the search on its own row and keep button labels on one line.
- Script and stylesheet URLs carry the version and a fingerprint of the files, so browsers and any CDN or tunnel cache always load matching code after an update (and can cache it for a year).

### Fixed
- "List my house" from the My house sheet opened behind it, so the sheet seemed stuck; it now replaces the sheet.
- A page could break after an update when a cached older script was mixed with new ones ("does not provide an export named …").
- A route set to start from "My current location" started from the first stop on the next visit until the option was picked again; it now asks for the position straight away.

## [0.4.0-beta.4] - 2026-10-19

### Added
- `HTTP_BIND` (and `MAILPIT_BIND` in development) to reach a local test stack from phones and other computers on the home network; production keeps `127.0.0.1`.

### Dependencies
- nginx 1.31, symfony/phpunit-bridge 8.1 (development only), @axe-core/playwright 4.14. Dependabot no longer skips nginx mainline releases or Symfony major versions; every update is proposed and checked by CI.

## [0.4.0-beta.3] - 2026-10-19

### Added
- Light and dark appearance on every page, following the browser/system setting (semantic colour tokens; dark changes colours only, never layout); the map's tiles are softened in dark mode while pins and outlines keep their colours.
- Organizer address list pages through results (25 per page).
- **About page** (`/about`, linked in the navigation and footer) with the organizer's description, event date and hours, how visitors may reach the homes (open to the public, gated but open for trick-or-treating, residents and guests, residents only), access notes, and links such as a neighborhood Facebook group; all editable in Organizer → Community. The map's info sheet shows the access status too.
- Privacy notice, terms of use and accessibility statement, linked from every page, with a "Clear saved data on this device" control. Public pages set no cookies and load no third-party scripts (tested).
- Automated WCAG 2.1 AA checks (axe-core) on every public page type in light and dark.
- Brand colours chosen by an organizer are turned into contrast-checked text and button-ink colours automatically, in both themes; support for high-contrast and forced-colour modes.

### Changed
- Visual polish from a measured audit at 375/430/768/1440 px: compact, aligned check chips and form controls of one height; one-line top bar on phones; stat tiles in an even 2×2 grid on phones and a full-width row on desktop; the map footer no longer covers the map credit; tapping a home on a phone brings its preview into view; even card spacing with the favourite button in the corner; single-column button stacks on phones; organizer tabs on one scrollable row on tablets; readable dates and review-request text in the console; a taller, sticky boundary editor.
- Stronger contrast for small grey text; hover states on pointer devices; tabular figures; balanced headings; email fields without autocorrect; "Report this listing" has its own wording.
- The "List another home" card adapts when a resident has no homes yet.

### Fixed
- Test listings created by the E2E suite are released afterwards, so they never appear in screenshots.
- The pin-placement map in "List your home" fits the neighborhood outline once the sheet has laid out (it could start too small on some screens).

### Dependencies
- Playwright 1.64.1 (test image and package together), GitHub Actions v7; Dependabot ignores Symfony major versions (the app stays on 7.4 LTS) and nginx mainline releases (the image tracks the stable branch).

## [0.4.0-beta.2] - 2026-10-19

First release whose whole history is free of private deployment data, so the repository can be made public as is. Not approved for production use; see *Known limitations* in 0.1.0-design.1.

### Added
- Real-data example **Ventura Reserve (Orlando, FL)**: the three official Orange County recorded-subdivision plats (Phases 1–3) imported with the generic importer, provenance manifest and review map. The boundary imports pending review; address points are fetched locally from Orange County's open data into the git-ignored `deploy/private/` and never committed.
- Generic ArcGIS importers `scripts/fetch_arcgis_boundaries.py` (plat polygons → zones, boundary, manifest, review map) and `scripts/fetch_arcgis_addresses.py` (address points inside the boundary → private CSV), driven by a JSON source config (`examples/gis-sources.example.json`).

### Changed
- The repository no longer contains any data about the first real deployment (configuration, geometry, street names, reports, screenshots, host names); deployments keep that in git-ignored `deploy/private/<id>/`.
- The repository history was rebuilt from the scrubbed tree so the repository can be made public as is; the earlier pre-release tags `v0.1.0-design.1` and `v0.4.0-beta.1` were withdrawn.

### Removed
- Neighborhood-specific importer scripts (replaced by the generic ones above).

### Security
- Pre-release adversarial audit and hardening; details in `docs/security/hardening-report.md`.
- A resident can manage at most `registration.max_homes_per_user` homes (default 2), and dashboard claims are rate-limited per resident.
- An organizer's "hide" now survives the owner releasing and re-claiming the home.
- Sign-in links: failed claim requests no longer use up the per-email or global budget; organizers are never locked out by the global cap; mailbox aliases share one budget; IP-literal and dot-less email domains are refused; using one link retires the others; the reply never echoes registry text for an address id.
- Email-sending endpoints answer no faster than a configurable floor (default 700 ms + jitter) after proof-of-work, and every form has a honeypot field.
- Review requests: invalid requests no longer use up a home's budget; new global daily cap; the organizer email marks the reporter address as unverified.
- The id-based claim-status endpoint was removed and address lookups work only while listings are open.
- Per-IP limits and proof-of-work binding key IPv6 clients by /64.
- The confirmation page shows the exact listing that confirming will publish.
- Organizer and resident controllers declare their role in code; resident data is reached only through the owner-scoped `ResidentHomes`; organizers can be listed in `ORGANIZER_EMAILS`.
- Sessions end 12 hours after sign-in (`AUTH_SESSION_MAX_HOURS`) however active; emails are masked in every log line.
- Production accepts only the `APP_BASE_URL` host; the container refuses test-like secrets and the development database password.
- nginx: per-IP request and connection limits, 1 MB request bodies (6 MB for organizer uploads), shorter client timeouts, forwarded headers trusted only from the host proxy path; nginx 1.30.
- Containers: no-new-privileges, dropped capabilities, read-only root filesystems (app, nginx, maintenance), memory/CPU/process limits; PHP-FPM request timeout and short outbound socket timeouts.
- Hourly `maintenance` service purges expired links, challenges, intents, throttle events, sessions and route-cache rows; backups are `0600` and exclude session rows; secrets and test files are no longer copied into images.
- Logo uploads must decode as the claimed image type and may not carry markup; out-of-range session/link settings are rejected.
- Dependabot keeps Docker images, Composer packages, GitHub Actions and the E2E toolchain up to date.

## [0.4.0-beta.1] - 2026-10-19

Promoted after the release gates ran on GitHub Actions (Linux) and a clean `git archive` export passed the README quick start and the full PHPUnit suite. Not approved for production use; see *Known limitations* in 0.1.0-design.1.

### Added
- `app:user:delete <email> --confirm` removes a resident's account, homes, listings, pending links and review requests on request (privacy).
- After a "session expired" sign-in, organizers and residents return to the page they were on (non-sensitive path kept in browser storage only).

### Fixed
- E2E test runner works on Linux CI runners (host networking) and honours `E2E_BLOCK_TILES`.

## [0.1.0-design.1] - 2026-10-18

First tagged snapshot, for design and functional review. The original HTML mock is translated to Twig/CSS/vanilla JS with a real Leaflet map (screenshots at 375, 430, 768 and 1440 px in `docs/design/screenshots/`). Blueprint milestones M1–M4 were developed together on one branch, so this snapshot already contains the full feature set; no separate 0.2/0.3 tags were cut. Not approved for production use until the items under *Known limitations* are resolved.

### Added
- Self-hosted Docker Compose stack: PHP 8.3 FPM (Symfony 7.4 LTS), PostgreSQL 17 on an internal network, unprivileged nginx bound to 127.0.0.1, daily `pg_dump` backups with retention, development overlay with Mailpit.
- One-community configuration (`community.yaml` + GeoJSON) for name, locality, time zone, event date/hours, branding (emoji/logo/colours/headline), features, optional named zones, registration mode and routing groups; editable in the organizer console and exportable.
- Public family map faithful to the original mock: hero, stats, search and filters (zone, candy, low-sensory, no jump scares, no flashing, no loud audio, no intense scares, non-food, 21+, stroller-friendly, saved only, open now, visit at), list and Leaflet map kept in sync, favorites, per-season browser storage, readable list when the map fails, mobile bottom navigation and bottom sheets.
- Suggested stop order that never connects separate walkable groups, copy/share stop list; optional provider-reported walking path (openrouteservice) only after crossings are verified.
- Resident listing flow: private address registry lookup or allow-listed street + pin, listing details, email confirmation with browser SHA-256 proof-of-work; 15-minute one-time links consumed only by an explicit POST; immediate publication for the first verified claimant; already-claimed notice with review request.
- Resident dashboard: hours, offerings, sensory details, status (handing out / paused / out of candy / done), hide/unhide, release.
- Server-enforced 30-minute inactivity timeout for residents and organizers (configurable 5–240 minutes), PostgreSQL-backed sessions that survive restarts, session rotation at sign-in, server-side logout.
- Organizer console: setup checklist, settings and logo upload, Leaflet.draw boundary/zone editor with GeoJSON import/export and approval gate, address registry (CSV import with dry run, add/edit), claim transfer/unclaim, listing hide/unhide, review requests, audit log, private claims CSV export.
- CLI: `app:community:bootstrap`, `app:community:export`, `app:admin:grant`, `app:addresses:import`, `app:maintenance:purge`, `app:season:archive`, `app:demo:seed`, `app:db:ping`.
- Importer for official county recorded-plat polygons (provenance manifest, review map) and a private address-inventory builder from county address points, used for the first deployment.
- Fictional "Maple Hollow" demo community (no zones, different branding/date) proving the generic install path.
- Tests: PHPUnit unit + PostgreSQL integration (including a multi-process claim race and frozen-clock session expiry), node:test browser-library tests, Python importer tests, Playwright E2E, session smoke test; GitHub Actions CI and a draft-release workflow.
- Project documentation: README, AGENTS.md protocol, ADRs, runbooks (setup, deployment with Cloudflare Tunnel, backup/restore, upgrade/rollback, event day, release), security policy, contributing guide, proposed MIT license.

### Security
- Proof-of-work plus persistent per-email, per-IP, per-address and global rate limits on every email-sending endpoint, with `Retry-After`.
- CSRF tokens for signed-in mutations and Origin/Referer checks on every state-changing request; strict Content-Security-Policy without inline script or style.
- Sign-in tokens are stored hashed, never logged (Monolog redaction, query-less nginx access log) and never sent cross-site (`Referrer-Policy`).
- Public APIs expose only opted-in listings for the current event date; no emails, owner ids or unclaimed address inventory.

### Known limitations
- The first deployment's boundary, address inventory and eligibility await owner review; house listings stay closed until the boundary is approved.
- Production hostname, Cloudflare Tunnel route and SMTP relay are not configured yet.
- No pedestrian crossings are verified; routing groups only order stops, and the provider path is disabled.
- Email control is not proof of residence; disputes are handled manually.
