# Engineering change records

Structured change log (one record per change unit) with the regression tests that anchor each change.
User-facing release notes are in `CHANGELOG.md`. Records up to CU-20261019-02 were made during private
development; that history was consolidated into the repository's first clean commit (see CU-20261019-03),
so their `commit_ref` reads `consolidated`.

```yaml
- id: CU-20261018-01
  type: feature
  title: "Reviewed the supplied county GIS plat importer and downloaded review layers"
  description: "Fixed the supplied importer (HTTP/content-type/ArcGIS error handling, variant-name inventory, truncation detection, raw response digests, overlap and dissolve-area checks, documented sliver-hole removal on the derived union only, RFC 7946 orientation, standard provenance properties, review map) and ran it against the first deployment's county layer (results kept privately with that deployment)."
  impact: medium
  affected_modules: ["scripts/ (county plat importer; generalized in CU-20261019-03)"]
  related_tests: ["scripts/tests/test_fetch_arcgis_boundaries.py"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-18T23:47:00Z"

- id: CU-20261018-02
  type: feature
  title: "Symfony/PostgreSQL foundation: geofencing, passwordless auth, atomic claims"
  description: "Docker images and Compose stack; schema migration; community config schema/bootstrap; pure-PHP GeoJSON validation and point-in-polygon; address normalization and eligibility; persistent throttles; SHA-256 proof-of-work; POST-confirmed one-time links; server-enforced idle sessions; first-verified-claim service; disputes; organizer API."
  impact: high
  affected_modules: ["src/", "migrations/", "docker/", "config/"]
  related_tests: ["tests/Integration/AuthAndClaimFlowTest.php", "tests/Integration/SessionIdleTest.php", "tests/Integration/ClaimRaceTest.php", "tests/Integration/ProofOfWorkAndThrottleTest.php", "tests/Integration/EligibilityTest.php"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T00:47:00Z"

- id: CU-20261018-03
  type: fix
  title: "Idle-session start time was wiped by the session-fixation strategy"
  description: "Session attributes set in onAuthenticationSuccess were discarded when the 'invalidate' strategy rotated the session, so every new login looked expired. Moved to a LoginSuccessEvent listener that runs after rotation."
  impact: high
  affected_modules: ["src/Security/Session/LoginSessionSubscriber.php", "src/Security/MagicLinkAuthenticator.php"]
  related_tests: ["tests/Integration/SessionIdleTest.php::testIdleWindowIsServerEnforcedAndResetOnlyByInteractiveRequests", "tests/Integration/AuthAndClaimFlowTest.php::testSessionIdRotatesAtSignIn"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T00:47:00Z"

- id: CU-20261018-04
  type: feature
  title: "Mock-faithful public map, resident flows, organizer console, E2E suite"
  description: "Twig/vanilla JS port of the prototype with Leaflet, synchronized filters/list/pins, favorites, separated stop order, listing bottom sheet with Web Worker PoW, review requests, owner dashboard, Leaflet.draw editor, organizer pages; Playwright E2E."
  impact: high
  affected_modules: ["templates/", "public/assets/", "src/Controller/"]
  related_tests: ["tests/e2e/specs/public.spec.mjs", "tests/e2e/specs/claim.spec.mjs", "tests/e2e/specs/admin.spec.mjs", "tests/js/lib.test.mjs", "tests/js/sha256.test.mjs"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T01:17:00Z"

- id: CU-20261018-05
  type: fix
  title: "OSM tiles were refused without a Referer"
  description: "Referrer-Policy same-origin stripped the Referer that OpenStreetMap's tile policy requires; tiles showed 'Access blocked'. Now strict-origin-when-cross-origin (origin only) and Leaflet referrerPolicy set."
  impact: high
  affected_modules: ["src/Shared/SecurityHeadersSubscriber.php", "public/assets/js/home.js", "docker/nginx/default.conf"]
  related_tests: ["tests/Integration/PublicPrivacyTest.php::testSecurityHeadersAndCsp", "tests/e2e/specs/public.spec.mjs"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T01:17:00Z"

- id: CU-20261018-06
  type: fix
  title: "Boundary editor altered official geometry on save"
  description: "Leaflet toGeoJSON rounded coordinates to 6 decimals (creating self-intersections in county rings) and MultiPolygon parts were saved as separate features. Now full precision and parts regrouped by source feature: an untouched import exports byte-for-byte identically."
  impact: high
  affected_modules: ["public/assets/js/admin/map.js"]
  related_tests: ["tests/e2e/specs/admin.spec.mjs", "tests/Integration/GeometryAdminTest.php::testTwoDisconnectedAreasRoundTripExactlyAndRequireApproval"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T01:17:00Z"

- id: CU-20261018-07
  type: fix
  title: "Development opcache never revalidated"
  description: "conf.d load order (zz-app-dev.ini before zz-app.ini) disabled timestamp validation in dev, serving stale code. Dev ini renamed to load last."
  impact: low
  affected_modules: ["Dockerfile"]
  related_tests: ["manual: docker compose exec app php -i | grep opcache.validate_timestamps (On)"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T01:17:00Z"

- id: CU-20261018-08
  type: feature
  title: "Optional provider walking paths (openrouteservice)"
  description: "Server-side adapter (key never sent to browsers), cached; only for one verified walkable group and only when the organizer verified crossings."
  impact: medium
  affected_modules: ["src/Routing/", "src/Controller/Api/RouteApiController.php", "public/assets/js/home.js"]
  related_tests: ["tests/Unit/Routing/OpenRouteServiceProviderTest.php", "tests/Integration/RoutePlanTest.php"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T01:28:00Z"

- id: CU-20261018-09
  type: fix
  title: "Config export not re-importable; CSV dry run miscounted; {s} tile templates rejected"
  description: "Bootstrap now keeps zone_key on kind=zone features of a combined file; in-file CSV duplicates are reported as skipped; tile URL validation accepts {s} subdomains."
  impact: medium
  affected_modules: ["src/Community/CommunityBootstrapper.php", "src/Address/AddressCsvImporter.php", "src/Community/CommunitySchema.php"]
  related_tests: ["tests/Integration/CommandsTest.php::testBootstrapExportAndReimportRoundTrip", "tests/Integration/CommandsTest.php::testCsvImportRequiresCoordinatesAndRespectsBoundaryAndZones", "tests/Integration/ErrorPathsTest.php::testHttpsOnlyHeadersAndSubdomainTileHosts"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T01:28:00Z"

- id: CU-20261018-10
  type: fix
  title: "Sign-in tokens in logs; confirm POST blocked by Origin: null"
  description: "Monolog processor redacts t=/_csrf values; nginx access log has no query strings or Referer. /auth pages use Referrer-Policy same-origin because no-referrer made browsers send Origin: null on the confirm POST."
  impact: high
  affected_modules: ["src/Shared/LogRedactionProcessor.php", "docker/nginx/default.conf", "src/Shared/SecurityHeadersSubscriber.php"]
  related_tests: ["tests/Unit/Shared/LogRedactionProcessorTest.php", "tests/Integration/PublicPrivacyTest.php::testSecurityHeadersAndCsp", "tests/e2e/specs/claim.spec.mjs", "scripts/smoke_session.py"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T01:41:00Z"

- id: CU-20261018-11
  type: doc
  title: "Documentation, protocols, ADRs, runbooks"
  description: "README, AGENTS.md, CHANGELOG, DECISIONS, ADRs, setup/deployment/backup/upgrade/event-day/release runbooks, security policy, contributing guide, quality report."
  impact: none
  affected_modules: ["docs/", "README.md", "AGENTS.md"]
  related_tests: []
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T02:00:00Z"

- id: CU-20261019-01
  type: fix
  title: "E2E runner could not reach the stacks on Linux CI"
  description: "nginx publishes only on 127.0.0.1; host.docker.internal reaches that loopback on Docker Desktop but not on Linux. bin/e2e.sh now uses host networking and localhost URLs on Linux, forwards E2E_BLOCK_TILES into the container, and handles empty arrays on macOS bash 3.2."
  impact: low
  affected_modules: ["bin/e2e.sh"]
  related_tests: ["tests/e2e/specs/*.spec.mjs (GitHub Actions e2e job)"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T02:10:00Z"

- id: CU-20261019-02
  type: feature
  title: "Account deletion command and post-expiry return path"
  description: "app:user:delete removes a user and all personal records (anonymous audit line only). login.js remembers a safe /me or /admin destination; me.js resumes it after sign-in."
  impact: low
  affected_modules: ["src/Command/UserDeleteCommand.php", "public/assets/js/login.js", "public/assets/js/me.js"]
  related_tests: ["tests/Integration/CommandsTest.php::testUserDeletionReleasesHomesAndForgetsTheEmail", "tests/e2e/specs/admin.spec.mjs (session-expired sign-in returns an organizer)"]
  commit_ref: "consolidated"
  author: "Lance Sandino"
  timestamp: "2026-10-19T02:20:00Z"

- id: CU-20261019-03
  type: refactor
  title: "Removed private deployment data; generic ArcGIS importers; Ventura Reserve real-plat example; clean history"
  description: "The first deployment's config, county geometry, GIS report, screenshots, importer mappings and host name moved to git-ignored deploy/private/; identifying street names and coordinates in tests replaced with neutral synthetic ones. The county-specific importers became config-driven tools (fetch_arcgis_boundaries.py, fetch_arcgis_addresses.py) that reproduce the original output byte for byte with a private config. New example: Ventura Reserve (City of Orlando) from Orange County open data, three recorded plats, pending review, addresses fetched locally only. CI, E2E and screenshots use it. Workflow runs and artifacts deleted; history rebuilt as a single clean root commit (pre-scrub history kept as an offline bundle)."
  impact: medium
  affected_modules: ["scripts/fetch_arcgis_boundaries.py", "scripts/fetch_arcgis_addresses.py", "scripts/arcgis_common.py", "scripts/arcgis_config.py", "examples/ventura-reserve/", "tests/", "docs/", ".github/workflows/ci.yml"]
  related_tests: ["scripts/tests/test_fetch_arcgis_boundaries.py", "scripts/tests/test_fetch_arcgis_addresses.py", "tests/Integration/CommunityConfigsTest.php::testVenturaReserveRealPlatExample", "tests/Unit/Geo/GeoJsonParserTest.php::testOfficialVenturaReservePlatsParse", "tests/Unit/Geo/ZoneResolverTest.php::testOfficialVenturaReservePlatsDoNotOverlap", "tests/e2e/specs/admin.spec.mjs"]
  commit_ref: "v0.4.0-beta.2"
  author: "Lance Sandino"
  timestamp: "2026-10-19T03:00:00Z"

- id: CU-20261019-04
  type: security
  title: "Pre-release adversarial audit and hardening (sign-in, ownership, abuse, infrastructure)"
  description: "Three evidence-based audits (auth/sessions/authorization, OWASP input/output, bots/abuse/infrastructure) produced 23 consolidated findings (1 High, 8 Medium, 10 Low, 4 Info); all fixed or explicitly accepted. Per-resident home limit with per-user lock and claim throttle; moderation kept on the address; link budgets spent only by sent mail, organizer exemption, alias folding, IPv6 /64 keys, token retirement; response-time floor and honeypots on email endpoints; review-request validation before budgets plus a global cap; claim-status endpoint removed and lookups closed while listings are closed; confirmation page shows the listing; #[IsGranted] on controllers, owner-scoped ResidentHomes, ORGANIZER_EMAILS; absolute session lifetime; email masking in logs; logo type checks; settings ranges; production trusted_hosts and secret checks; nginx limits, timeouts, body caps and gateway-only proxy trust; container least privilege and limits; hourly maintenance purge; private backups without sessions; .dockerignore for secrets; nginx 1.30; Dependabot."
  impact: high
  affected_modules: ["src/Security/", "src/Controller/Api/", "src/Claim/ClaimService.php", "src/Listing/ResidentHomes.php", "src/Listing/ListingRepository.php", "src/Dispute/DisputeService.php", "src/Community/", "src/Command/MaintenancePurgeCommand.php", "src/Shared/LogRedactionProcessor.php", "migrations/Version20261019000001.php", "docker/", "compose.yaml", "compose.dev.yaml", ".dockerignore", "templates/", "public/assets/"]
  related_tests: ["tests/Integration/AbuseHardeningTest.php", "tests/Integration/OwnershipHardeningTest.php", "tests/Unit/Security/ClientIdentityTest.php", "tests/Unit/Security/EmailNormalizerTest.php", "tests/Integration/SessionIdleTest.php::testSessionsEndAfterTheAbsoluteLifetimeEvenWhenActive", "tests/Unit/Shared/LogRedactionProcessorTest.php::testEmailAddressesAreMaskedIncludingInsideLoggedObjects", "tests/Integration/CommandsTest.php::testSeasonArchiveAndPurgeAndPing", "tests/Integration/ErrorPathsTest.php::testBrandingStoreValidatesRasterImages", "tests/Unit/Community/CommunitySchemaTest.php::testCollectsAllErrors", "docs/security/hardening-report.md (production-stack verification)"]
  commit_ref: "v0.4.0-beta.2"
  author: "Lance Sandino"
  timestamp: "2026-10-19T04:00:00Z"

- id: CU-20261019-05
  type: feature
  title: "Light/dark appearance, accessibility and privacy pages, and measured UI polish across phone, tablet and desktop"
  description: "About page and organizer settings (about text, access status, access notes, up to 8 links) with escaping and URL validation; placeholder organizer contact in shipped configs. Colours moved to semantic tokens with a dark palette under prefers-color-scheme (color-scheme and theme-color metas, explicit select colours, softened map tiles). Fixes from a measured visual audit: field/label specificity that stretched check chips and checkboxes, inherited label fonts in the dashboard form, mismatched control/pill heights, mobile stat tiles, map footer over the credit, off-screen mobile preview, card spacing, wrapped button rows, admin header at tablet width, editor height, dates, contrast, hover states, Vercel interface-guideline items (tap highlight, touch-action, overscroll containment, tabular numbers, balanced headings, email input hints). E2E suite releases its test listing."
  impact: low
  affected_modules: ["public/assets/css/app.css", "public/assets/css/admin.css", "public/assets/js/home.js", "public/assets/js/me.js", "public/assets/js/register.js", "public/assets/js/lib/render.js", "public/assets/js/admin/", "templates/"]
  related_tests: ["tests/e2e/specs/public.spec.mjs (dark appearance; check chips; overflow/targets at 4 widths)", "tests/e2e/specs/a11y.spec.mjs (axe WCAG 2.1 AA, light and dark)", "tests/Integration/LegalPagesTest.php", "tests/Unit/Community/BrandPaletteTest.php", "tests/Unit/Community/CommunitySchemaTest.php::testAboutAccessAndLinks", "tests/e2e/specs/claim.spec.mjs (cleanup)", "tests/js/lib.test.mjs"]
  commit_ref: "v0.4.0-beta.3"
  author: "Lance Sandino"
  timestamp: "2026-10-19T05:00:00Z"

- id: CU-20261019-06
  type: feature
  title: "Testing feedback: filter icons and count, phase highlight, closed homes off lists and routes, copy fallback, shareable links, fingerprinted assets"
  description: "Filter buttons carry icons and the Filters button shows the active-filter count; choosing a zone outlines and zooms to it (zoneStyle in lib/render.js). Homes paused/out of candy/done are kept off the list and routes (toggle to show them; the route says how many were skipped). Copy uses the Clipboard API on HTTPS and an execCommand fallback elsewhere (iPhone over HTTP). Share links encode changed filters (?f=), stop ids (?stops=), an optional start home (&start=) and a manual-order flag (&o=1). Inclusive wording. Script/stylesheet URLs are /assets/v<version>.<fingerprint>/… (Twig asset_path; nginx alias, one-year immutable cache) after a stale cached module broke the page through the tunnel edge; unversioned /assets/js stays no-cache. Phone filter grid and centred search icon."
  impact: medium
  affected_modules: ["public/assets/js/home.js", "public/assets/js/lib/render.js", "public/assets/js/lib/filters.js", "public/assets/js/lib/pow.js", "public/assets/js/pow-worker.js", "public/assets/css/app.css", "templates/", "src/Twig/AssetExtension.php", "docker/nginx/default.conf"]
  related_tests: ["tests/e2e/specs/public.spec.mjs (closed homes, filter count, copy, show on map, shared link)", "tests/e2e/specs/claim.spec.mjs", "tests/js/lib.test.mjs (zoneStyle)", "tests/Unit/Twig/AssetExtensionTest.php::testUrlChangesWhenAnyAssetChanges"]
  commit_ref: "v0.4.0-beta.5"
  author: "Lance Sandino"
  timestamp: "2026-10-19T06:30:00Z"

- id: CU-20261019-07
  type: feature
  title: "Zone status (active / coming soon / hidden) and organizer approval of self-entered addresses"
  description: "zones[].status: coming_soon zones are drawn with that label but are not listable, not in the zone filter and not counted; hidden zones are left out of the public payload and the address lookup. Claims into a non-active zone are refused with zone_not_open. registration.review_new_addresses holds a self-entered address (moderation_hold) and hides its listing with moderation_reason 'pending_review'; the outcome is pending_review; organizers see a count and list on the overview and approve with Unhide (also sets reviewed_at). A pin outside the boundary but on a named non-residential or non-active zone is refused with that zone's reason; the form adds the organizer contact link to refusals the organizer can fix. Missing-address hint offers the pin flow or the contact link; the pin flow hides the list search. My house -> List my house now replaces the sheet (modal z-index by stack)."
  impact: medium
  affected_modules: ["src/Community/CommunitySchema.php", "src/Community/CommunityConfig.php", "src/Community/CommunityPresenter.php", "src/Address/AddressEligibility.php", "src/Address/AddressRegistry.php", "src/Claim/ClaimService.php", "src/Claim/ClaimOutcome.php", "src/Listing/ListingRepository.php", "src/Controller/Api/", "src/Twig/AppExtension.php", "public/assets/js/register.js", "public/assets/js/me.js", "public/assets/js/admin/", "public/assets/js/lib/modal.js", "templates/home/"]
  related_tests: ["tests/Integration/ZoneStatusAndReviewTest.php", "tests/Integration/EligibilityTest.php", "tests/e2e/specs/public.spec.mjs (My house -> List my house)", "tests/js/lib.test.mjs (coming-soon style)"]
  commit_ref: "v0.4.0-beta.5"
  author: "Lance Sandino"
  timestamp: "2026-10-19T07:00:00Z"

- id: CU-20261019-08
  type: feature
  title: "Route: start from the visitor's location, reorder stops"
  description: "planStops accepts a start point that is not a stop (nearest trip first, each trip starts at its stop nearest the point); the location is requested once, kept in memory only, never stored or put in links, and offered only in a secure context. applyOrder/moveStop give per-trip manual ordering kept in local storage (or in the shared view) with a reset to the suggested order. E2E browser uses full Chromium so the test origins count as secure."
  impact: low
  affected_modules: ["public/assets/js/lib/route.js", "public/assets/js/lib/store.js", "public/assets/js/home.js", "templates/home/_modals.html.twig", "public/assets/css/app.css", "tests/e2e/playwright.config.mjs"]
  related_tests: ["tests/js/lib.test.mjs (location start; reorder)", "tests/e2e/specs/public.spec.mjs::route: reorder stops, keep the order, share it; start from my location"]
  commit_ref: "v0.4.0-beta.5"
  author: "Lance Sandino"
  timestamp: "2026-10-19T07:20:00Z"

- id: CU-20261019-09
  type: feature
  title: "What's new dialog and /changelog page with a source-code link"
  description: "ChangelogReader parses CHANGELOG.md (plain text, empty Unreleased skipped); a native <dialog> opened from the footer version link shows the latest releases and links to the source, full changelog and releases (APP_SOURCE_URL, default the project repository, empty hides them); /changelog is the no-JS fallback. The version shows once (footer). New filter icons."
  impact: low
  affected_modules: ["src/About/ChangelogReader.php", "src/Twig/ChangelogExtension.php", "src/Controller/PageController.php", "templates/base.html.twig", "templates/partials/", "templates/legal/changelog.html.twig", "public/assets/js/whatsnew.js", "config/"]
  related_tests: ["tests/Unit/About/ChangelogReaderTest.php", "tests/e2e/specs/public.spec.mjs::What's new", "tests/e2e/specs/a11y.spec.mjs (changelog page)"]
  commit_ref: "v0.4.0-beta.5"
  author: "Lance Sandino"
  timestamp: "2026-10-19T07:40:00Z"

- id: CU-20261019-10
  type: feature
  title: "GIS importer: reassign official parcel/lot polygons between zones"
  description: "boundaries.reassign rules fetch polygons from an https ArcGIS layer, require them to lie (>= 95 %) inside from_zone, subtract them there and merge them into the nearest to_zone feature (residential only), snapped to a ~1 cm grid so the app's strict ring check passes; every rule needs a note, recorded with OBJECTIDs, response digest and area in the manifest and its owner-review list. Used privately to keep homes platted inside an amenities tract with the phase they belong to."
  impact: low
  affected_modules: ["scripts/fetch_arcgis_boundaries.py", "scripts/arcgis_config.py", "examples/gis-sources.example.json"]
  related_tests: ["scripts/tests/test_fetch_arcgis_boundaries.py::ReassignTests"]
  commit_ref: "v0.4.0-beta.5"
  author: "Lance Sandino"
  timestamp: "2026-10-19T08:00:00Z"

- id: CU-20261019-11
  type: feature
  title: "Appearance panel with accessibility options; landscape and tablet layout"
  description: "Header button opens a native dialog: theme Auto/Light/Dark and Larger text (page zoom 1.15 with the Leaflet container counter-zoomed so map hit-testing stays exact), More contrast (same overrides as prefers-contrast) and Reduce motion (CSS plus every scripted scroll/pan). Stored as nhm:appearance on the device; theme-init.js (classic head script, CSP-safe) applies it before paint. Dark tokens apply for system dark unless Light is chosen, or when Dark is chosen; both token blocks are kept identical (unit test); brand text colours follow. Supersedes the system-only decision D-20261019-08. Phones in landscape (max-height 500px) use the rail-less layout with a slim bottom bar, list and map side by side, safe-area padding; tablets get a full-width search row and nowrap action buttons. Version shown once; footer switch removed."
  impact: low
  affected_modules: ["public/assets/css/app.css", "public/assets/js/theme-init.js", "public/assets/js/appearance.js", "public/assets/js/home.js", "src/Community/BrandPalette.php", "templates/base.html.twig", "templates/admin/_layout.html.twig", "templates/home/index.html.twig"]
  related_tests: ["tests/e2e/specs/public.spec.mjs::Appearance panel", "tests/e2e/specs/public.spec.mjs::phone in landscape", "tests/e2e/specs/public.spec.mjs (overflow and targets at 375/430/956x440/768/1180/1440)", "tests/js/lib.test.mjs (dark token blocks identical)", "tests/Unit/Community/BrandPaletteTest.php", "tests/Integration/HeaderBrandingTest.php"]
  commit_ref: "v0.4.0-beta.5"
  author: "Lance Sandino"
  timestamp: "2026-10-19T08:30:00Z"

- id: CU-20261019-12
  type: feature
  title: "Route skip and nearby suggestions; reminders; city under the name; event date links to About"
  description: "Skipped stop ids (device only) are left out of routes and links, with a restore control; suggestNearby offers up to five open, filter-matching homes in the route's walkable trips within 500 m; a Saved / All matching switch when favourites exist. community.reminders (up to 12 lines; empty = built-in list) on the About page and the map guide. branding.show_locality shows City, ST under the name in the header and rail; logo images keep their transparent background and show in the phone header. The event pill links to /about."
  impact: low
  affected_modules: ["public/assets/js/lib/route.js", "public/assets/js/lib/store.js", "public/assets/js/home.js", "src/Community/CommunitySchema.php", "src/Community/CommunityPresenter.php", "templates/", "public/assets/js/admin/community.js", "public/assets/css/app.css"]
  related_tests: ["tests/js/lib.test.mjs (nearby suggestions)", "tests/e2e/specs/public.spec.mjs::route: skip stops", "tests/Integration/LegalPagesTest.php::testRemindersDefaultOrOrganizerOwnAndTheDateLinksToAbout", "tests/Integration/HeaderBrandingTest.php::testCityUnderTheNameIsAnOption"]
  commit_ref: "v0.4.0-beta.5"
  author: "Lance Sandino"
  timestamp: "2026-10-19T08:45:00Z"

- id: CU-20261019-13
  type: feature
  title: "Walking areas in the zone filter, per-choice counts, combined big-scares filter, kid-friendly label"
  description: "zone_areas() turns routing.groups (+ optional routing.group_labels, validated to fit the groups) into area:<n> filter choices; matches() resolves them through ctx.areas; zoneStyle accepts a Set so an area is highlighted and fitted as one. choiceCounts() gives the result count for every toggle and zone option (handing-out homes only). noJumps/noIntense merged into noScares with normalizeFilters() for stored and shared filters; one Big scares tag. Stroller label renamed Kid-friendly (field unchanged). Listing options for both resident forms come from partials/_listing_checks.html.twig (feature-gated, same icons and words as the filters; me.js tolerates hidden options); non-food is the Teal Pumpkin Project sign (tinted 🎃), flashing lights 🔦 everywhere. Pins/cards/tags use one vocabulary (🍬 Treats, 🧩 Sensory-friendly = isLowSensory, 👻 Spooky = big scares). About page and quick-guide sheet restructured; footer identity line; back-to-top button (to-top.js); filter-based routes show their filters (filterSummary). Route: 2-opt judges moves by their end edges (O(n²) per pass), the per-trip cap rises from 20 to 200, and a status line shows stops, trips, start, not-handing-out and skipped counts; saved-only routes say so. Summary tiles reordered with a 21+ count; server low-sensory stat aligned with the client definition."
  impact: low
  affected_modules: ["public/assets/js/lib/filters.js", "public/assets/js/lib/render.js", "public/assets/js/home.js", "public/assets/js/admin/community.js", "src/Twig/AppExtension.php", "src/Community/CommunitySchema.php", "src/Community/CommunityPresenter.php", "templates/"]
  related_tests: ["tests/js/lib.test.mjs (walking areas, normalizeFilters, choiceCounts, big scares)", "tests/Unit/Twig/AppExtensionTest.php::testWalkingAreasForTheZoneFilter", "tests/Integration/ZoneStatusAndReviewTest.php::testWalkingAreaNamesAppearInTheZoneFilterAndMustFitTheGroups", "tests/e2e/specs/public.spec.mjs::each filter shows how many homes it would list"]
  commit_ref: "v0.5.0-beta.1"
  author: "Lance Sandino"
  timestamp: "2026-10-19T09:30:00Z"

- id: CU-20261019-14
  type: security
  title: "Pre-publication review of changes since the 0.4.0-beta.2 audit; hidden zones also hide their homes; WebKit test project"
  description: "Reviewed the approval flow, zone status, lookup, shared-link parsing, admin overview, asset routing, appearance and changelog code. One low finding: listings in a zone later set to hidden stayed in the public homes list; publicHomes() now drops hidden zones (CommunityConfig::hiddenZoneKeys). Playwright gains a webkit-iphone project for public.spec (WebKit's own select styling is reported as a style-src violation and is ignored there; location tests are Chromium-only on the plain-HTTP test host)."
  impact: low
  affected_modules: ["src/Listing/ListingRepository.php", "src/Community/CommunityConfig.php", "src/Controller/PageController.php", "src/Controller/Api/PublicApiController.php", "tests/e2e/"]
  related_tests: ["tests/Integration/ZoneStatusAndReviewTest.php::testHomesAlreadyListedInAZoneThatIsLaterHiddenAreNotPublic", "tests/e2e/playwright.config.mjs (webkit-iphone)"]
  commit_ref: "v0.5.0-beta.2"
  author: "Lance Sandino"
  timestamp: "2026-10-19T08:45:00Z"

- id: CU-20261019-15
  type: refactor
  title: "Performance pass from Lighthouse and PHP-FPM/OPcache review"
  description: "gzip in nginx; leaflet.css loaded by home.js (not render-blocking) with preconnect/dns-prefetch to the tile origin; 9-11 px text raised to 11-12 px; public/robots.txt. Production image adds php-prod.ini (OPcache 192 MB, interned strings 16 MB, preload of Symfony's generated preload file, JIT off) — verified in PHP-FPM: 914 classes preloaded. PHP-FPM pool sizes come from FPM_* env (defaults unchanged). Upgrade runbook uses --force-recreate so every deploy starts with an empty OPcache (validate_timestamps=0)."
  impact: low
  affected_modules: ["docker/nginx/default.conf", "docker/php/php-prod.ini", "docker/php/fpm-pool.conf", "Dockerfile", "compose.yaml", "public/robots.txt", "public/assets/css/app.css", "public/assets/js/home.js", "templates/home/index.html.twig", "docs/runbooks/"]
  related_tests: ["tests/e2e/specs/*.mjs (68 incl. WebKit; overflow and 44 px targets at all sizes)", "Lighthouse mobile run (local, documented in CHANGELOG)", "php-fpm -tt and preload_statistics in the production image (manual check)"]
  commit_ref: "v0.5.0-beta.4"
  author: "Lance Sandino"
  timestamp: "2026-10-19T19:15:00Z"
- id: CU-20261020-02
  type: fix
  title: "Dark mode on iPhone: dark map tiles, themed zone labels and selects"
  description: "On iOS (WebKit) Auto/Dark left the map bright: the dark tile filter sat on .leaflet-tile-pane, which WebKit ignores because Leaflet's positioned tiles are separate layers; it now sits on each img.leaflet-tile. Since CU-20261019-15 home.js adds leaflet.css after app.css, so Leaflet's defaults beat the equal-specificity overrides (white #fff/#222 zone labels, #ddd map background, Helvetica map font, #333 attribution text); those overrides are now scoped under .leaflet-container/html. iOS Safari paints native selects grey and ignores background-color, so single-choice selects are drawn with appearance:none and a token-coloured chevron (native look restored under forced-colors). Verified in Playwright WebKit (iPhone 15) and Chromium at 375/430/768/1440 px, light and dark, with the new stylesheet served into the live page: tiles filtered only in dark, labels/selects use theme tokens, no horizontal scroll. The dead 11 px attribution rule was dropped (Leaflet's 12 px applied anyway)."
  impact: low
  affected_modules: ["public/assets/css/app.css", "tests/js/lib.test.mjs"]
  related_tests: ["tests/js/lib.test.mjs: the dark map also works in Safari and its overrides beat leaflet.css", "tests/js/lib.test.mjs: selects are drawn by the page in token colours, not by iOS", "Playwright WebKit/Chromium computed-style and screenshot check (manual, light and dark)"]
  commit_ref: "fix/ios-dark-map"
  author: "Lance Sandino"
  timestamp: "2026-10-20T22:30:00Z"
- id: CU-20261020-03
  type: feature
  title: "One family page: inline My route with saved routes, Map · Rules · My house tabs, map kept on the neighborhood"
  description: "The route sheet became an inline My route panel (templates/home/_route.html.twig, public/assets/js/route-panel.js, moved out of home.js): under the map on wider screens and between map and list on phones. Share and Show on map sit at the top; Copy stop list and the read-only link field are gone (the link is on the Share button's data-url). With no saved homes the panel asks for hearts or offers \"Plan with all N matching homes\" instead of repeating the whole list. Named saved routes (lib/store.js routes/activeRoute/draft): each keeps its own saved homes, filters, start, order and skipped stops; edits go to the open route; the unsaved route is kept aside while a saved one is open; a shared link can be saved as a route and opens in the unsaved route so saved ones are untouched. A remembered My current location start asks for the position only when location is already allowed (no prompt on page load). Bottom tabs are Map · Rules · My house (Rules opens the guide sheet; the bottom bar with Build my route is gone). The map dims everything outside the approved boundary (lib/map-focus.js, an evenodd world polygon with the boundary cut out, filled with the page background token) and limits panning and zooming out to the area plus a margin. Route lines remain provider-only (ADR 0007); a straight dotted line was considered and rejected by the owner in favour of road-following paths later. A link to #route or a shared stop list opens the panel after the load event (WebKit ignored the scroll while the map was still laying out). Verified: node:test 22/22; Playwright on fresh stacks of this branch (Chromium + WebKit iPhone 15 Pro) 70 passed, 2 skipped (Chromium-only location tests), 0 failed."
  impact: medium
  affected_modules: ["public/assets/js/home.js", "public/assets/js/route-panel.js", "public/assets/js/lib/store.js", "public/assets/js/lib/map-focus.js", "public/assets/css/app.css", "templates/base.html.twig", "templates/home/index.html.twig", "templates/home/_route.html.twig", "templates/home/_modals.html.twig", "tests/js/lib.test.mjs", "tests/e2e/specs/public.spec.mjs", "DECISIONS.md", "README.md", "docs/design/README.md", "docs/runbooks/event-day.md"]
  related_tests: ["tests/js/lib.test.mjs: saved routes keep their own stops, filters, start and order; edits follow the active route", "tests/js/lib.test.mjs: the map dims everything outside the boundary and stays near the neighborhood", "tests/e2e/specs/public.spec.mjs: saved routes keep their own homes and filters; switch between them, survive a reload, delete one", "tests/e2e/specs/public.spec.mjs: bottom tabs are Map, Rules and My house; Rules opens the guide; the map stays on the neighborhood", "tests/e2e/specs/public.spec.mjs: route tests updated for the inline panel (shared link saved as a route, share and show-on-map)"]
  commit_ref: "feature/one-page-route"
  author: "Lance Sandino"
  timestamp: "2026-10-21T00:30:00Z"
- id: CU-20261021-01
  type: feature
  title: "Compact filters: icon quick filters with counts, no summary cards, map zooms to matching homes"
  description: "Owner feedback: the four summary cards repeated the filter counts. They are gone on every screen size (template, CSS and the stat updates in home.js); the counts live on the filters. Candy, Sensory-friendly, 21+ refreshments and Saved are quick filters shown as icon + count on desktop and phones alike, named by visually hidden text (screen readers; an aria-label failed axe label-content-name-mismatch because the visible count was not in the name) and title (hover); 21+ and Saved moved out of the expandable panel, which keeps the worded filters (no big scares, flashing, loud audio, non-food, kid-friendly, visit time). Phones lay out search, then phase + Filters, then the four icons on one row. After any filter change the map fits the matching homes that are handing out (maxZoom 18; a typed search waits 500 ms), else the chosen phase or area, else the whole neighborhood (Reset — which previously zoomed out only through the phase highlighter, so neighborhoods without phases stayed zoomed in); remembered filters do the same on load; switching saved routes too. The route summary chips read the quick filters' names from that hidden text."
  impact: low
  affected_modules: ["templates/home/index.html.twig", "public/assets/css/app.css", "public/assets/js/home.js", "public/assets/js/route-panel.js", "tests/e2e/specs/public.spec.mjs", "tests/Integration/CommunityConfigsTest.php", "DECISIONS.md"]
  related_tests: ["tests/Integration/CommunityConfigsTest.php: testVenturaReserveRealPlatExample now checks the phase picker instead of the removed summary card", "tests/e2e/specs/public.spec.mjs: filters are icons with counts on every screen (names for screen readers); Filters expands the rest", "tests/e2e/specs/public.spec.mjs: changing filters zooms the map to the matching homes, and Reset shows the whole neighborhood again"]
  commit_ref: "feature/compact-filters"
  author: "Lance Sandino"
  timestamp: "2026-10-21T02:30:00Z"
- id: CU-20261020-04
  type: feature
  title: "Publish multi-arch app and nginx images to GitHub Container Registry; pull-based deploys"
  description: ".github/workflows/images.yml builds the prod and nginx Dockerfile targets for linux/amd64 and linux/arm64 (QEMU + buildx, GitHub Actions cache) and pushes ghcr.io/<owner>/neighborhood-events-map and -nginx: vX.Y.Z tags give :X.Y.Z and :latest, pushes to main give :edge and :sha-<short>; each image gets OCI labels, an SBOM, max-mode provenance and a signed build-provenance attestation (attest-build-provenance). compose.yaml now names those images (APP_IMAGE / NGINX_IMAGE overridable, APP_IMAGE_TAG default latest) and keeps the build sections, so `docker compose pull && up` runs a release with no build on the server while `up --build` still builds from source under the same names. Deployment, upgrade/rollback and release runbooks, README and .env.example describe pinning APP_IMAGE_TAG and pulling (D-20261020-04). Packages are made public by the owner in package settings after the first publish."
  impact: low
  affected_modules: [".github/workflows/images.yml", "compose.yaml", ".env.example", "README.md", "docs/runbooks/deployment.md", "docs/runbooks/upgrade-rollback.md", "docs/runbooks/release.md", "DECISIONS.md"]
  related_tests: ["actionlint 1.7.7 on .github/workflows/*.yml (clean)", "docker compose config --images resolves the GHCR names and a pinned APP_IMAGE_TAG"]
  commit_ref: "feature/published-images"
  author: "Lance Sandino"
  timestamp: "2026-10-21T01:30:00Z"
- id: CU-20261021-02
  type: feature
  title: "Desktop sidebar offers the same Map · Rules · My house as the phone tabs"
  description: "The sidebar's five links (map, walking route, list my house, manage my house, about) became the three phone-tab destinations: Map (#explore), Rules (the guide sheet, active on /about) and My house (the sheet with List my house / Manage my listing, active on /me and sign-in). My route is on the map page. The privacy page now points to My house (Manage my listing)."
  impact: low
  affected_modules: ["templates/base.html.twig", "templates/legal/privacy.html.twig", "tests/e2e/specs/public.spec.mjs"]
  related_tests: ["tests/e2e/specs/public.spec.mjs: bottom tabs are Map, Rules and My house; Rules opens the guide; the map stays on the neighborhood (now also checks the desktop sidebar)", "tests/e2e/specs/a11y.spec.mjs (all pages, light and dark)"]
  commit_ref: "feature/sidebar-tabs"
  author: "Lance Sandino"
  timestamp: "2026-10-21T04:00:00Z"
- id: CU-20261021-03
  type: fix
  title: "compose: maintenance reuses the app image instead of building it a second time"
  description: "With the image names on GHCR, `docker compose build` on a Docker host using the containerd image store (Ubuntu's Docker 28 / Compose 2.40 on the production host) built app and maintenance as two targets exporting one image name, and the second export failed with \"image already exists\" (the first 0.6.0-beta.1 deploy stopped there; it was finished by starting the already-built images). maintenance now has no build section and pull_policy: missing: `up --build` builds the image once for app and creates maintenance from it, and `pull` fetches it once."
  impact: low
  affected_modules: ["compose.yaml"]
  related_tests: ["docker compose -p ctest2 up --build --no-start with a never-built APP_IMAGE_TAG: app built once, maintenance created from it without a pull (local, Compose 5.5.1)", "production deploy of the next release (containerd image store) must build without the error"]
  commit_ref: "fix/compose-single-build"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-04
  type: fix
  title: "CI: pcov from its pinned GitHub release; build attestation only for public repositories"
  description: "After the 0.6.0-beta.1 push, the e2e job failed building the dev image because pecl.php.net answered \"No releases available\" / \"cannot download\" to GitHub runners (it still served other clients). The dev stage now downloads pcov v1.0.12's tagged source from GitHub, checks a pinned SHA-256 and installs it with docker-php-ext-install (the documented pattern for PECL extensions). The Images workflow pushed both images (:0.6.0-beta.1, :latest) but its attest-build-provenance step failed: GitHub refuses attestations for user-owned private repositories; the step now runs only when the repository is public (images keep their buildx provenance and SBOM)."
  impact: none
  affected_modules: ["Dockerfile", ".github/workflows/images.yml"]
  related_tests: ["docker build --target dev, then php -m lists pcov (local)", "actionlint 1.7.7 clean", "CI e2e job and Images workflow on the next push to main"]
  commit_ref: "fix/ci-pcov-attest"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:30:00Z"
- id: CU-20261021-05
  type: chore
  title: "Images: edge builds are amd64-only; releases stay amd64 + arm64"
  description: "Owner request to keep GitHub Actions minutes low while the repository is private (public repositories get standard runners free). arm64 is built under QEMU emulation and took 25-33 runner minutes per image on a cold cache (about 1 minute when cached). Pushes to main now publish :edge for linux/amd64 only and skip QEMU; vX.Y.Z tags still publish linux/amd64 + linux/arm64, which ARM servers such as the production host pull. No macOS runners are used anywhere."
  impact: none
  affected_modules: [".github/workflows/images.yml", "docs/runbooks/release.md"]
  related_tests: ["actionlint 1.7.7 clean", "next push to main: Images run builds linux/amd64 only"]
  commit_ref: "ci/edge-amd64-only"
  author: "Lance Sandino"
  timestamp: "2026-10-21T06:00:00Z"
- id: CU-20261021-06
  type: chore
  title: "Images: native arm64 runner, one build per commit, release tags reuse it (supersedes CU-20261021-05)"
  description: "Owner review of Actions minutes: one release cost about 125 runner minutes because pushing main and the tag together built the same images twice and the tag build emulated arm64 under QEMU (25-33 minutes per image), and CI's images job built them a third time. The Images workflow now builds each platform once per commit on its own native runner (ubuntu-24.04 for amd64, ubuntu-24.04-arm for arm64, standard runners in private repositories since early 2026), pushes by digest and joins them into :edge and :sha-<short>. A release tag never rebuilds: when main and the tag are pushed together the main run applies :X.Y.Z and :latest after joining; a tag pushed later copies :sha-<short> (imagetools, seconds). CI's image-build check runs only for pull requests. Edge images are multi-arch again (CU-20261021-05's amd64-only edge is no longer needed)."
  impact: none
  affected_modules: [".github/workflows/images.yml", ".github/workflows/ci.yml", "docs/runbooks/release.md"]
  related_tests: ["actionlint 1.7.7 clean", "next push to main: four native build jobs, two merge jobs, :edge and :sha-<short> multi-arch", "next release tag: promote job only (no build)"]
  commit_ref: "ci/native-arm-no-duplicates"
  author: "Lance Sandino"
  timestamp: "2026-10-21T06:30:00Z"
- id: CU-20261021-11
  type: security
  title: "Percent-encoded request paths are refused (idle-timeout skip, CSRF-token skip, cross-site logout, nginx limit bypass)"
  description: "Security audit run-1 leads 1, 2 and 15, confirmed locally and on the first deployment. Symfony routes and authorizes on the decoded path, while IdleSessionSubscriber, CsrfGuardSubscriber's token check and its /_internal_logout block compared the raw Request::getPathInfo(): an idle session still got 200 from /api/m%65, a same-origin PATCH /api/m%65/homes/{id} skipped the session CSRF token, GET /_internal_%6Cogout reached the firewall's logout listener (cross-site sign-out by top-level navigation), and nginx's per-IP API/auth limits were keyed on the raw $request_uri, so /%61pi/... ran with an empty key. No route needs an encoded path: CanonicalPathSubscriber (kernel.request priority 512, before sessions, the guard, the router and the firewall) answers 404 for any path containing '%', and nginx answers 400 first. nginx limit maps now key on the normalized $uri, and limit_req/limit_conn rejections log at info (below the error log level), so a refused /auth/confirm?t=… request line no longer lands in the error log. AdminApiController::moderate chooses hide/unhide by the matched route name, not the raw path."
  impact: high
  affected_modules: ["src/Security/CanonicalPathSubscriber.php", "src/Controller/Api/AdminApiController.php", "docker/nginx/default.conf"]
  related_tests: ["tests/Integration/RequestPathHardeningTest.php (idle timeout, CSRF token, logout, raw-path routing; failed before the fix)", "tests/Unit/Deploy/ContainerConfigTest.php::testNginxRefusesEncodedPathsAndKeysLimitsOnTheNormalizedUri", "local stack: /%61pi/community and /_internal_%6Cogout → 400 from nginx, 150 parallel /api/community calls → 98 × 429"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-12
  type: security
  title: "PHP-FPM listens only on Unix sockets shared with nginx; email-sending endpoints get their own pool"
  description: "Lead 4, confirmed on the first deployment: FPM listened on [::]:9000 with clear_env = no, and the host (and every container on the backend/egress networks) could connect to it; FastCGI can set PHP settings per request and the workers hold the app's secrets. Both pools now listen on Unix sockets in the php-socket volume (www-data:fcgi-nginx gid 101, mode 0660), mounted only by app (rw) and nginx (ro); the healthcheck pings both sockets. Lead 9: the email-sending endpoints wait out the response floor (700 ms + jitter) in a worker, so a distributed flood could hold every worker of the single pool, map included; POST /api/auth/request-link and /api/disputes now run in a separate ondemand pool (FPM_MAIL_MAX_CHILDREN, default 4) and the floor is unchanged (D-20261019-05). Side effect: nginx connects to the socket path per request, so recreating app alone no longer leaves nginx on a stale upstream (the 502 that needed an nginx restart)."
  impact: high
  affected_modules: ["docker/php/fpm-pool.conf", "docker/php/healthcheck.sh", "docker/nginx/default.conf", "docker/nginx/app-fastcgi.conf", "Dockerfile", "compose.yaml", ".env.example", "docs/runbooks/deployment.md", "docs/architecture/overview.md"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php (no TCP listener, socket owner/mode, only app and nginx mount the socket, mail endpoints on the mail pool)", "local dev and production-image stacks: no 9000 listener, a container on the egress network cannot connect, sockets srw-rw---- www-data fcgi-nginx, request-link answered by pool mail, app recreated alone → nginx still 200, fresh php-socket volume works with the read-only production images"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-13
  type: security
  title: "Hidden zones stay hidden: no home by id, no named refusals, no review requests"
  description: "Leads 3 and 16 (D-20261019-12, hidden = not public at all). ListingRepository::publicHome() had no hidden-zone filter, so GET /api/homes/{id} and route planning still returned homes in a hidden zone; publicHome now takes the hidden zone keys like publicHomes(). Claim eligibility named hidden zones and confirmed their keys: a registry row in a hidden zone is now refused exactly like an id that does not exist (not_eligible), a pin inside a hidden zone gets zone_not_open with text that never names the zone (checked before zone_mismatch), a requested hidden zone_key is unknown_zone like a key that never existed, and a pin outside the boundary inside a hidden area is simply outside_boundary. Review requests for a home that is not public (disabled, outside the boundary or in a hidden zone) answer like an unknown id. Coming-soon zones keep their named zone_not_open (they are public). Residual (inherent): any refusal inside the public boundary marks a point as not listable."
  impact: medium
  affected_modules: ["src/Listing/ListingRepository.php", "src/Controller/Api/PublicApiController.php", "src/Routing/RoutePlanner.php", "src/Community/CommunityConfig.php", "src/Address/AddressEligibility.php", "src/Dispute/DisputeService.php", "src/Controller/Api/DisputeApiController.php"]
  related_tests: ["tests/Integration/HiddenZonePrivacyTest.php (failed before the fix)", "tests/Integration/ZoneStatusAndReviewTest.php"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-14
  type: security
  title: "A claim or review request by registry id must also name the house number"
  description: "Leads 11 and 12. Registry ids are sequential; an anonymous claim by bare id mailed the address text to the caller's own mailbox (and the confirmation page showed it), and a dashboard claim by id returned it, so walking ids harvested the unclaimed inventory that the narrow lookup protects. AddressEligibility::resolve() now requires the house number the visitor looked up with any address_id (case-insensitive); a bare or mismatched id is refused exactly like one that does not exist, before any intent, mail or claim. Review requests by id do the same. The confirmation step re-checks the id it already matched. The browser sends the house number from the chosen display address (lib/address.js); display addresses always start with it (all 916 rows of the first deployment checked)."
  impact: medium
  affected_modules: ["src/Address/AddressEligibility.php", "src/Claim/ClaimService.php", "src/Dispute/DisputeService.php", "src/Controller/Api/DisputeApiController.php", "public/assets/js/register.js", "public/assets/js/lib/address.js", "tests/Support/AppTestCase.php (byId helper)"]
  related_tests: ["tests/Integration/RegistryIdBindingTest.php (failed before the fix)", "tests/js/lib.test.mjs (houseNumberOf)", "every integration test that claims by id now goes through AppTestCase::byId()"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-15
  type: security
  title: "A released, unreviewed self-entered address takes the next resident's own pin"
  description: "Lead 5. A self-entered address stays in the registry after release or unclaim; the next resident who typed it was resolved to that row and inherited the earlier claimant's unverified pin (and, with review on, got a 'published' reply while the listing stayed hidden). An unreviewed, enabled, unclaimed self_entry row is now treated as a new self-entry: the resident's pin is validated, and once their claim insert succeeds AddressRegistry::repinSelfEntry() moves the row to it (only source = self_entry AND reviewed_at IS NULL). Review applies again. Reviewed and imported addresses keep their pins."
  impact: medium
  affected_modules: ["src/Address/AddressEligibility.php", "src/Address/AddressRegistry.php", "src/Claim/ClaimService.php"]
  related_tests: ["tests/Integration/SelfEntryRepinTest.php (failed before the fix)", "tests/Integration/ClaimRaceTest.php"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-16
  type: security
  title: "Identity integrity: plain email local parts, honest organizer revoke, erasure without an account, no organizer oracle"
  description: "Lead 7: \"x<victim@evil.test>\"@attacker.test passed FILTER_VALIDATE_EMAIL and Symfony Mime delivered it to victim@evil.test, splitting the account identity (and its per-mailbox budget) from the mailbox that got the link; EmailNormalizer now accepts only dot-atom local parts of letters, digits and . _ + ' - and Notifier passes new Address() objects. Lead 8: app:admin:grant --revoke printed 'Revoked' while ORGANIZER_EMAILS kept the role; it now removes the database grant and fails with an explanation. Lead 6: app:user:delete refused anyone without a users row, so a reporter who only sent a review request could not be erased; it now deletes their review requests and pending links too. Lead 10: with the global sign-in budget full, organizers got 202 and everyone else 429, which told anyone able to fill the budget which address is an organizer's; organizers now get the same 429 while their link is still sent (D-20261019-05 unchanged: organizers are never locked out)."
  impact: medium
  affected_modules: ["src/Security/EmailNormalizer.php", "src/Mail/Notifier.php", "src/Command/AdminGrantCommand.php", "src/Command/UserDeleteCommand.php", "src/Controller/Api/AuthApiController.php"]
  related_tests: ["tests/Unit/Security/EmailNormalizerTest.php (quoted, percent and query-like local parts)", "tests/Integration/CommandsTest.php::testRevokingAnOrganizerListedInTheEnvironmentFailsLoudly", "tests/Integration/CommandsTest.php::testDeletionAlsoErasesSomeoneWhoOnlyAskedForAReview", "tests/Integration/AbuseHardeningTest.php::testOrganizersAreNotLockedOutByTheGlobalLinkBudget (now also asserts the identical reply)"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-17
  type: security
  title: "Bounded storage and quotas: listing-save limit, global walking-path budget, per-bucket purge"
  description: "Lead 13: a resident's listing saves had no limit of their own and each wrote an audit row; listing_user (RL_LISTING_PER_USER, default 60/h per account). Lead 18: walking-path provider calls (cache misses) now share route_global (RL_ROUTE_GLOBAL, default 1000/day), so many clients cannot spend the API key's quota (the provider is off by default). Lead 14: throttle events were all kept 48 h whatever their window; Throttle::purge() now deletes each bucket's events once they leave its own window (minutes for pow_ip) and unknown buckets after the longest window, which also fixes windows longer than 48 h being cut short. Expired proof-of-work challenges are purged at expiry (verify() refuses them anyway) instead of a day later."
  impact: low
  affected_modules: ["src/Security/Throttle.php", "src/Security/ProofOfWork.php", "src/Shared/RuntimeConfig.php", "src/Controller/Api/MeApiController.php", "src/Routing/RoutePlanner.php", "config/services.yaml", "compose.yaml", ".env.example", ".env.dist"]
  related_tests: ["tests/Integration/AbuseHardeningTest.php::testResidentListingSavesAreRateLimitedPerAccount", "tests/Integration/RoutePlanTest.php::testProviderCallsShareOneGlobalBudget", "tests/Integration/ProofOfWorkAndThrottleTest.php::testPurgeDropsThrottleEventsOnceTheirOwnWindowHasPassed", "tests/Integration/ProofOfWorkAndThrottleTest.php::testExpiredChallengesArePurgedRightAway"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-18
  type: security
  title: "Untrusted share-link filters are type-checked; the ArcGIS review page builds popups from text"
  description: "Lead 17: a share link with ?f= carrying {\"q\":5} or {\"zone\":7} threw inside matches() and was saved to the visitor's device, breaking the map until site data was cleared; normalizeFilters() keeps only own keys of DEFAULT_FILTERS with the right type (strings capped at 80 characters) and readSharedLink uses it. Lead 19: the importer's local review page assembled Leaflet popups from ArcGIS attributes (third-party data) as HTML strings; popups are now text nodes, and the Leaflet CDN files carry SRI hashes (the same bytes as public/vendor/leaflet)."
  impact: low
  affected_modules: ["public/assets/js/lib/filters.js", "public/assets/js/home.js", "scripts/fetch_arcgis_boundaries.py"]
  related_tests: ["tests/js/lib.test.mjs (shared or stored filters of the wrong type are dropped)", "scripts/tests/test_fetch_arcgis_boundaries.py::ReviewMapTest", "node --check on the generated review-page script"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-19
  type: security
  title: "Production configuration guards; local conveniences stay opt-in"
  description: "The production entrypoint refuses APP_DEBUG other than 0. trusted_hosts matches APP_BASE_URL's host literally (new %env(regex_quote:…)% processor; the dots were 'any character'). .dockerignore excludes a deployment's private config/community.yaml and config/*.geojson so a local build cannot bake them into an image. Local and staging only: Mailpit's UI (it shows every sign-in link) stays on 127.0.0.1 and can now require a password with MAILPIT_UI_AUTH when MAILPIT_BIND=0.0.0.0 is used for LAN testing; HTTP_BIND still defaults to 127.0.0.1."
  impact: low
  affected_modules: ["docker/php/entrypoint.sh", "src/Shared/RegexQuoteEnvVarProcessor.php", "config/packages/framework.yaml", ".dockerignore", "compose.dev.yaml", "compose.staging.yaml", ".env.example"]
  related_tests: ["tests/Unit/Shared/RegexQuoteEnvVarProcessorTest.php", "production image: APP_DEBUG=1 → FATAL, exit 64", "production image with APP_BASE_URL=http://map.example.org: Host mapXexample.org → 400 (the previous image let it through to the app)", "mailpit v1.27: MP_UI_AUTH empty → open UI, set → 401 without credentials"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:00:00Z"
- id: CU-20261021-20
  type: security
  title: "Every input sanitized: plain-character addresses, one free-text cleaner, anchored URLs"
  description: "Owner request: verify all input is sanitized; addresses never need special characters. Addresses: AddressNormalizer refused nothing before cleaning, so '1<2' became house 12, 'Peña' became 'PEA' and look-alike letters from other scripts or invisible characters could create a second same-looking address; now any address input (self-entry, organizer add/CSV import, community street list) with anything but ASCII letters, digits, spaces and . , ' - # is refused with one message, the lookup returns nothing for such a query, and an organizer's display_address edit must be a spelling of the same address (plain, house number first, same normalized key; it was free text up to 160 characters). 'Apt #4' now parses as unit 4 (it became part of the street). Free text: listing notes, review reasons, organizer notes and reasons, and every community setting go through Shared/TextInput: valid UTF-8, NFC, no control characters, no bidirectional overrides, zero-width spaces, BOM or soft hyphens (zero-width joiners stay for emoji), length-capped; the four slightly different hand-written copies are gone. The tile URL template is anchored and limited to URL-safe characters (it reaches the CSP and Leaflet), links refuse quotes, angle brackets, backslashes and backticks, and a claim transfer's dispute_id must name a review request for that home. Other inputs were checked and already strict: JSON bodies (size, depth, valid UTF-8), ids and limits (integers, clamped), enums (status, scare level, purpose), times, booleans, emails, tokens, the sign-in return path, uploaded logos, CSV and GeoJSON. Production data was checked first: every address and street uses only letters, digits and spaces."
  impact: medium
  affected_modules: ["src/Address/AddressNormalizer.php", "src/Shared/TextInput.php", "src/Listing/ListingDraft.php", "src/Dispute/DisputeService.php", "src/Admin/ClaimAdministration.php", "src/Community/CommunitySchema.php", "src/Controller/Api/PublicApiController.php", "src/Controller/Api/AdminApiController.php"]
  related_tests: ["tests/Unit/Address/AddressNormalizerTest.php::testSpecialCharactersAreRefused (13 cases, all accepted before)", "tests/Unit/Address/AddressNormalizerTest.php::testParseRefusesSpecialCharactersAndKeepsOrdinaryPunctuation", "tests/Unit/Shared/TextInputTest.php", "tests/Integration/InputSanitizationTest.php (self-entry, lookup, note, review reason and organizer email, organizer address and display edit, settings, moderation reason, transfer dispute_id)"]
  commit_ref: "fix/security-validation"
  author: "Lance Sandino"
  timestamp: "2026-10-21T07:30:00Z"
- id: CU-20261021-21
  type: fix
  title: "Zone names sit inside their zones and never cover each other"
  description: "Owner report: on the production map one phase's name sat behind a small common area's name, hard to read on phones and touching on desktop. Zone names were Leaflet tooltips bound to each zone's GeoJSON layer, which places them at the middle of the zone's bounding box; for an irregular zone that can be far from its body and on top of another zone. Each name is now its own permanent tooltip at the zone's pole of inaccessibility (lib/zone-labels.js labelPoint: the point deepest inside the largest part, avoiding holes), and after every zoom and resize a pass (resolveOverlaps) moves names that would overlap: phases that take listings keep their place, then other residential zones, and each lower name takes the nearest free spot directly above or below what it would cover, so it stays near its own zone. Checked on the production map with this branch's scripts served into the live page (WebKit iPhone 15 Pro and Chromium 1440 px, five zoom steps): no overlapping names."
  impact: low
  affected_modules: ["public/assets/js/lib/zone-labels.js", "public/assets/js/home.js", "tests/js/lib.test.mjs"]
  related_tests: ["tests/js/lib.test.mjs: zone names sit inside their zone, at its widest point, not at the middle of its bounding box", "tests/js/lib.test.mjs: overlapping zone names are moved apart; the more important name keeps its place"]
  commit_ref: "fix/zone-labels"
  author: "Lance Sandino"
  timestamp: "2026-10-21T08:00:00Z"
- id: CU-20261021-22
  type: feature
  title: "Pins and cards show the kind you filter by; 21+ has its own symbol and legend entry"
  description: "Owner request: with 21+ ticked the map should show it, and the legend should cover sensory-friendly, spooky and 21+. render.js now resolves one kind per home (KINDS: spooky, sensory, adult, treats): with a quick filter on (candy, sensory-friendly or 21+), the homes that match show that filter's symbol; otherwise the order is spooky, sensory-friendly, 21+, treats. Pins (pinHtml) and list cards (cardHtml; the no-JS Twig card mirrors the default order) use it. A 21+ pin uses the new --pin-adult token, the legend gains 🍺 21+ when the community offers 21+ refreshments, and the legend bullets now use the pin tokens instead of hard-coded colours."
  impact: low
  affected_modules: ["public/assets/js/lib/render.js", "public/assets/js/home.js", "templates/home/_card.html.twig", "templates/home/index.html.twig", "public/assets/css/app.css", "tests/js/lib.test.mjs"]
  related_tests: ["tests/js/lib.test.mjs: pins show the kind you filter by; without a filter: spooky, then sensory-friendly, then 21+, then treats"]
  commit_ref: "fix/zone-labels"
  author: "Lance Sandino"
  timestamp: "2026-10-21T09:30:00Z"
- id: CU-20261021-23
  type: fix
  title: "No bright seam between map tiles at in-between zoom levels"
  description: "With zoomSnap 0.25, tile rows can end on sub-pixel positions (measured: a tile edge at 242.6 px in Chromium at 1440 px) and Leaflet's mix-blend-mode: plus-lighter adds the map background there, leaving a one-pixel bright line across the map (WebKit at device scale 3 was not affected). Isolating the tile pane did not help; tiles now overlap by half a pixel (256.5 px) and draw with mix-blend-mode: normal. Verified on the production map with a screenshot row scan (bright single-pixel rows: before [242], after none) in Chromium and WebKit."
  impact: low
  affected_modules: ["public/assets/css/app.css", "tests/js/lib.test.mjs"]
  related_tests: ["tests/js/lib.test.mjs: map tiles overlap by half a pixel so no seam shows between them at in-between zoom levels"]
  commit_ref: "fix/zone-labels"
  author: "Lance Sandino"
  timestamp: "2026-10-21T09:30:00Z"
- id: CU-20261021-24
  type: feature
  title: "Organizers can place a zone's name on the map (drag on the Boundary & zones page, or app:zone:label)"
  description: "Generic per-zone setting zones[].label_at: [longitude, latitude] or null (CommunitySchema validates two finite numbers in range and rounds to 6 decimals; null lets the map choose). The public payload publishes it; the map puts the name there and the overlap pass never moves a placed name. On the admin Boundary & zones page every zone's name is a draggable handle at its current spot; dropping it saves label_at through the same validated settings endpoint. The Community page shows Moved on the map / Automatic per zone with a Reset, and now keeps zone fields it does not show (it used to drop description, and would have dropped label_at, on every save). app:zone:label <zone> <latitude> <longitude> | --reset sets it from the shell and writes a community.zone_label_moved audit event."
  impact: low
  affected_modules: ["src/Community/CommunitySchema.php", "src/Community/CommunityPresenter.php", "src/Command/ZoneLabelCommand.php", "public/assets/js/home.js", "public/assets/js/admin/map.js", "public/assets/js/admin/community.js", "public/assets/css/admin.css", "tests/Unit/Community/CommunitySchemaTest.php", "tests/Integration/CommandsTest.php", "tests/e2e/specs/admin.spec.mjs"]
  related_tests: ["tests/Unit/Community/CommunitySchemaTest.php: testZoneNamesCanBePinnedToAPointOnTheMap", "tests/Integration/CommandsTest.php: testZoneNamesCanBeMovedFromTheCommandLineAndReachThePublicMap", "tests/e2e/specs/admin.spec.mjs: organizer drags a zone name, the public payload gets it, Reset gives it back"]
  commit_ref: "fix/zone-labels"
  author: "Lance Sandino"
  timestamp: "2026-10-21T09:30:00Z"
- id: CU-20261021-25
  type: fix
  title: "security: actions pinned to commit SHAs, no persisted checkout credentials, release tags must be annotated and on main"
  description: "Security audit supply-chain notes: every `uses:` in ci.yml, images.yml and release.yml is pinned to the commit its current major tag points to (version in a comment, so Dependabot's github-actions updates keep working); every checkout sets persist-credentials: false except the images job that fetches release tags (a private repository needs the token for that fetch), re-applied after main's per-platform images.yml (download-artifact v8.0.2 pinned too); release.yml fetches full history and refuses a tag that is not annotated or whose commit is not on main before drafting a release; bin/e2e.sh installs the e2e dependencies with npm ci only (no npm install fallback that could resolve versions outside the lockfile)."
  impact: low
  affected_modules: [".github/workflows/ci.yml", ".github/workflows/images.yml", ".github/workflows/release.yml", "bin/e2e.sh"]
  related_tests: ["git ls-remote of each action's major tag (SHAs recorded in the workflow comments)", "/bin/bash -n bin/e2e.sh", "full e2e run through the same npm ci path (docs/quality/public-readiness-audit.md)", "release.yml checks run on the next annotated tag (not exercised locally)"]
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T04:32:00Z"
- id: CU-20261021-26
  type: feature
  title: "Event-neutral product naming: the community's event is a setting (event.name, default Halloween)"
  description: "Titles, the sidebar brand line, footer, meta description, sign-in/claim/review email subjects and headers, the confirm page and the terms/privacy wording no longer hard-code Halloween: they read \"<community> <event name>\" from the new event.name setting (≤ 40 chars, cleaned by Shared/TextInput like all community text; empty or missing means Halloween, the first supported event type). Settings saved before the field existed get the default when loaded (CommunityRepository::load), so every existing install still says Halloween; organizers can change it under Organizer → Community → Event. CommunityConfig::eventName() serves the mailer; the unconfigured placeholder reads \"Neighborhood events map\". Trick-or-treat copy (reminders, access labels, the register title, branding defaults) stays: it belongs to the Halloween event type. Code-level names: compose/Dockerfile headers and the MAIL_FROM default say \"Neighborhood events\"; the dev image is tagged neighborhood-events-map-app:dev; GIS importer User-Agents and the e2e package name use neighborhood-events-map. Kept on purpose (persisted or deployed identifiers): the halloween Compose project/database/user defaults, halloween-*.dump backup names, nhm:* localStorage keys, the nhm_session cookie, the nhm CSRF token id, the X-NHM-Background header, migration descriptions and the GHCR image names (D-20261021-18)."
  impact: low
  affected_modules: ["src/Community/CommunitySchema.php", "src/Community/CommunityRepository.php", "src/Community/CommunityConfig.php", "src/Mail/Notifier.php", "templates/", "public/assets/js/admin/community.js", "public/assets/js/route-panel.js", "config/community.example.yaml", "examples/*/community.yaml", "compose.yaml", "compose.dev.yaml", "Dockerfile", "config/services.yaml", ".env.example", ".env.dist", ".env.test", "phpunit.dist.xml", "scripts/fetch_arcgis_*.py", "tests/e2e/package*.json"]
  related_tests: ["tests/Unit/Community/CommunitySchemaTest.php: testTheEventNameDefaultsToHalloweenAndIsTheOrganizersToChange (also: cleaned by Shared/TextInput like all community text)", "tests/Integration/HeaderBrandingTest.php: testThePageNamesTheCommunitysEventNotAFixedHoliday", "tests/Integration/HeaderBrandingTest.php: testSettingsSavedBeforeTheEventNameExistedStillSayHalloween", "tests/e2e/specs/claim.spec.mjs (subject 'Confirm your Maple Hollow Halloween listing' unchanged)"]
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T04:45:00Z"
- id: CU-20261021-27
  type: fix
  title: "bin/restore.sh accepts only a plain backup file name; operator-script regression tests"
  description: "Security audit hardening note not covered by the 0.6.0-beta.2 security release (CU-20261021-11 to -19): the dump name went unquoted into `sh -c` inside the backup container. restore.sh now refuses anything but a plain *.dump name before prompting and quotes it. scripts/tests/test_operator_scripts.py also pins the production refusals added in that release (APP_DEBUG, placeholder secrets, the dev database password) and the private config exclusions in .dockerignore."
  impact: low
  affected_modules: ["bin/restore.sh", "scripts/tests/test_operator_scripts.py"]
  related_tests: ["scripts/tests/test_operator_scripts.py (RestoreTests, EntrypointTests, BuildContextTests)"]
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T07:30:00Z"
- id: CU-20261021-28
  type: fix
  title: "The whole deploy/ folder is git-ignored, so an operator's community config can never be committed"
  description: "README and setup.md tell operators to keep their community.yaml and boundary GeoJSON in deploy/community/ and called the folder git-ignored, but only deploy/private/ was: `git add .` in a fork would have committed a neighborhood's own configuration and geometry. .gitignore now ignores /deploy/ (nothing under it is tracked)."
  impact: low
  affected_modules: [".gitignore"]
  related_tests: ["scripts/tests/test_operator_scripts.py: GitIgnoreTests.test_the_whole_deployment_folder_is_ignored", "git check-ignore -v deploy/community/community.yaml → .gitignore:/deploy/"]
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:20:00Z"
- id: CU-20261021-29
  type: doc
  title: "Docs match the code for a public release: event-neutral naming, drift fixes, code of conduct, ADR 0010"
  description: "README (product name, 0.6.0-beta.1 status, current filters, Python gate, event name, image visibility and roadmap links), CONTRIBUTING (event-neutral, conduct and security links), new CODE_OF_CONDUCT.md, SECURITY.md (private vulnerability reporting, percent-path refusal, APP_DEBUG refusal, deploy/ ignored), AGENTS.md (event-neutral rule, deploy/ untracked), architecture overview (CanonicalPathSubscriber), design notes (sidebar tabs, no stat cards, icon filters, Appearance panel; the mock is not in the repository), runbooks (events.example.org, MAIL_FROM default, event-day title, restore names, pull-request refs before going public) and ADR 0010 (generic event types, proposed). Every relative link, documented path, env var, command and port was checked against the code."
  impact: none
  affected_modules: ["README.md", "CONTRIBUTING.md", "CODE_OF_CONDUCT.md", "SECURITY.md", "AGENTS.md", "ROADMAP.md", "docs/adr/", "docs/architecture/overview.md", "docs/design/README.md", "docs/runbooks/"]
  related_tests: []
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T05:45:00Z"
- id: CU-20261021-30
  type: fix
  title: "composer.json: MIT like LICENSE, and bounded constraints for the three unbounded bundles"
  description: "composer.json still carried the Symfony skeleton's \"license\": \"proprietary\" while LICENSE is MIT, and required doctrine/doctrine-bundle, doctrine/doctrine-migrations-bundle and symfony/monolog-bundle as \"*\" (composer validate warned; a `composer update` could cross a major version). They are now MIT and ^2.19 / ^3.7 / ^4.1, the locked majors; composer.lock changed only its content-hash (no package moved)."
  impact: low
  affected_modules: ["composer.json", "composer.lock"]
  related_tests: ["composer validate --no-check-publish (valid, no warnings)", "composer install --dry-run (nothing to install)", "composer audit --locked (no advisories)", "PHPUnit full suite"]
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T06:00:00Z"
- id: CU-20261021-31
  type: fix
  title: "security: the public community payload's walking groups no longer name hidden zones"
  description: "Security audit hardening note (same class as leads 3 and 16, not covered by CU-20261021-13): /api/community published routing.groups verbatim, so the key of an organizer-hidden zone stayed visible. CommunityPresenter drops hidden keys from each group and keeps the groups' positions, so group_labels still line up."
  impact: low
  affected_modules: ["src/Community/CommunityPresenter.php"]
  related_tests: ["tests/Integration/HiddenZonePrivacyTest.php: testWalkingGroupsInThePublicPayloadDoNotNameAHiddenZone"]
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T06:05:00Z"
- id: CU-20261021-32
  type: fix
  title: "The committed Ventura Reserve review map is regenerated with the escaping generator, and a test keeps it current"
  description: "CU-20261021-18 changed the GIS importer to build review-map popups from text and to pin Leaflet with SRI, but the committed examples/ventura-reserve/boundaries/review-map.html still carried the old string-built popups and no integrity attributes. It is regenerated offline from the committed GeoJSON (no network) and its manifest checksum updated; a test now regenerates it and compares byte for byte."
  impact: low
  affected_modules: ["examples/ventura-reserve/boundaries/review-map.html", "examples/ventura-reserve/boundaries/manifest.json", "scripts/tests/test_fetch_arcgis_boundaries.py"]
  related_tests: ["scripts/tests/test_fetch_arcgis_boundaries.py: ReviewMapTest.test_committed_example_review_map_is_the_current_generators_output (failed on the old file)"]
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T08:10:00Z"
- id: CU-20261021-33
  type: test
  title: "More regression coverage for percent-encoded paths: nginx in the browser suite and five more app cases"
  description: "Extra anchors for CU-20261021-11: the Playwright suite checks that the real nginx answers 400 for /%61pi/community, /%6De, /_internal_%6Cogout and /api/homes/%31 while an escaped query string still works (chromium and WebKit), and RequestPathHardeningTest covers five more encoded paths with the app's 404 (POST /auth/%63onfirm, which answered 500 before 0.6.0-beta.2, an encoded moderation verb, an encoded slash, an escaped id digit and /%6De)."
  impact: none
  affected_modules: ["tests/e2e/specs/public.spec.mjs", "tests/Integration/RequestPathHardeningTest.php"]
  related_tests: ["tests/e2e/specs/public.spec.mjs: nginx refuses percent-escaped paths before the app; escaped query strings still work", "tests/Integration/RequestPathHardeningTest.php: testOtherEncodedPathsAreRefusedBeforeAnyHandler"]
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T08:15:00Z"
- id: CU-20261021-34
  type: fix
  title: "Shared and stored filters: \"visit at\" must be any, now or HH:MM; oversized zone keys are dropped"
  description: "Hardening on top of CU-20261021-18's type check: normalizeFilters() kept any string up to 80 characters for time and zone, so a share link could store an unknown time word or an oversized zone key on the visitor's device. The time filter now keeps only any, now or a valid HH:MM, and a zone key longer than 60 characters is dropped; q is still cut at 80. The existing filter tests stay green."
  impact: low
  affected_modules: ["public/assets/js/lib/filters.js"]
  related_tests: ["tests/js/lib.test.mjs: shared filters keep only a real time choice and a zone key of sensible length", "tests/js/lib.test.mjs: shared or stored filters of the wrong type are dropped, so a crafted link cannot break the map"]
  commit_ref: "chore/public-ready"
  author: "Lance Sandino"
  timestamp: "2026-10-21T08:20:00Z"
```
