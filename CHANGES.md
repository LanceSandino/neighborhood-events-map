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
```
