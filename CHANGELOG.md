# Changelog

All notable user-facing changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Engineering change records (with regression-test anchors) live in `CHANGES.md`.

## [Unreleased]

## [0.4.0-beta.3] - 2026-10-19

### Added
- Light and dark appearance on every page, following the browser/system setting (semantic colour tokens; dark changes colours only, never layout); the map's tiles are softened in dark mode while pins and outlines keep their colours.
- Organizer address list pages through results (25 per page).
- Privacy notice, terms of use and accessibility statement, linked from every page, with a "Clear saved data on this device" control. Public pages set no cookies and load no third-party scripts (tested).
- Automated WCAG 2.1 AA checks (axe-core) on every public page type in light and dark.
- Brand colours chosen by an organizer are turned into contrast-checked text and button-ink colours automatically, in both themes; support for high-contrast and forced-colour modes.

### Changed
- Visual polish from a measured audit at 375/430/768/1440 px: compact, aligned check chips and form controls of one height; one-line top bar on phones; stat tiles in an even 2×2 grid on phones and a full-width row on desktop; the map footer no longer covers the map credit; tapping a home on a phone brings its preview into view; even card spacing with the favourite button in the corner; single-column button stacks on phones; organizer tabs on one scrollable row on tablets; readable dates and review-request text in the console; a taller, sticky boundary editor.
- Stronger contrast for small grey text; hover states on pointer devices; tabular figures; balanced headings; email fields without autocorrect; "Report this listing" has its own wording.
- The "List another home" card adapts when a resident has no homes yet.

### Fixed
- Test listings created by the E2E suite are released afterwards, so they never appear in screenshots.

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
