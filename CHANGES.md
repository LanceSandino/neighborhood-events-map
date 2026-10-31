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
- id: CU-20261021-35
  type: fix
  title: "security: nginx keys its limits per client network (IPv4 address or IPv6 /64), like the app"
  description: "Public-readiness hardening note: nginx keyed limit_req and limit_conn on $binary_remote_addr, so one IPv6 subscriber or server (a whole /64) had 2^64 separate nginx budgets, while the app's persistent limits key on the /64 (Security/ClientIdentity). A new map $client_net turns nginx's compressed IPv6 text into the /64's four groups (one pattern per compression shape; IPv4-mapped addresses become their IPv4 address) and every limit zone keys on it. Verified in real nginx 1.31 (771 addresses: every zero/non-zero group pattern three times, plus IPv4 and IPv4-mapped) and pinned by a test that applies the map's own patterns to nginx's text form of all 256 shapes."
  impact: low
  affected_modules: ["docker/nginx/default.conf"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testNginxKeysIpv6ClientsByTheirSlash64LikeTheApp (failed on the old config: no $client_net map)"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:10:00Z"
- id: CU-20261021-36
  type: fix
  title: "security: one client network can keep at most 6 requests in PHP (2 on the email endpoints)"
  description: "Public-readiness hardening note: limit_conn perip 40 applied everywhere, above the www pool's 12 workers, so one client could occupy every PHP worker. The locations that reach PHP (/ for pages and the API, /api/admin/, and the two email endpoints) now set their own caps: 6 (half the default www pool) and 2 (half the mail pool); static files keep 40. nginx applies the limits of the first location a request matches, so the caps sit there and not in the internal /index.php location."
  impact: low
  affected_modules: ["docker/nginx/default.conf", "docs/runbooks/deployment.md"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testNginxCapsOneClientsRequestsInPhpBelowThePoolSizes", "tests/Unit/Deploy/ContainerConfigTest.php: testNginxRoutesMailEndpointsToTheirPoolAndNeverToATcpUpstream"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:11:00Z"
- id: CU-20261021-37
  type: fix
  title: "Interrupted backups no longer leave .partial files behind; backup-now writes a partial file first"
  description: "Public-readiness hardening note: a dump cut off by a container stop left its hidden .partial file forever (rotation only matched finished dumps), and bin/backup-now.sh wrote straight to the final name, so an interrupted run left a truncated file that looked like a backup. The daily loop now removes partial files older than an hour before each dump (a manual backup may be running), and backup-now writes .halloween-<stamp>.dump.partial and renames it only after pg_dump succeeds. BACKUP_PATH and BACKUP_ONCE let the tests run one pass of the loop."
  impact: low
  affected_modules: ["docker/postgres/backup-loop.sh", "bin/backup-now.sh", "docs/runbooks/backup-restore.md"]
  related_tests: ["scripts/tests/test_operator_scripts.py: BackupLoopTests (3 tests)"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:12:00Z"
- id: CU-20261021-38
  type: feature
  title: "A restore keeps accounts deleted and organizers revoked after the backup (account ledger)"
  description: "Public-readiness hardening note: restoring an older backup brought back accounts deleted since (with their homes and review requests) and organizers revoked since. Deletions (Admin/AccountErasure, now shared by app:user:delete), erased review requests and organizer grants and revocations (UserRepository::setAdmin) write an append-only account_ledger row (ids and times only) in the same transaction. bin/restore.sh exports the live ledger (app:ledger:export), stops nginx, maintenance and the app, restores, starts the app (migrations run), merges the saved ledger back and replays it (app:ledger:replay), and only then starts nginx. An entry touches only rows created at or before it, so a newer row that reuses an id is safe; the latest role change per account decides; replaying again changes nothing; a malformed saved ledger changes nothing. Migration Version20261021000001 creates the table (IF NOT EXISTS, so a restore of an older backup keeps it). Real drill on a development stack: backup, delete one account and revoke another organizer, restore → both stay deleted/revoked."
  impact: medium
  affected_modules: ["src/Audit/AccountLedger.php", "src/Admin/AccountErasure.php", "src/Admin/LedgerReplay.php", "src/Command/LedgerExportCommand.php", "src/Command/LedgerReplayCommand.php", "src/Command/UserDeleteCommand.php", "src/Security/UserRepository.php", "migrations/Version20261021000001.php", "bin/restore.sh"]
  related_tests: ["tests/Integration/AccountLedgerTest.php: testARestoreCannotBringBackADeletedAccountOrARevokedOrganizer", "tests/Integration/AccountLedgerTest.php: testAnEntryNeverTouchesARowCreatedAfterIt", "tests/Integration/AccountLedgerTest.php: testAMalformedSavedLedgerChangesNothing", "tests/Integration/CommandsTest.php"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:13:00Z"
- id: CU-20261021-39
  type: fix
  title: "security: the maintenance container gets no mail or routing credentials"
  description: "Public-readiness hardening note: maintenance inherited the whole app environment, including MAILER_DSN (SMTP credentials), ROUTING_API_KEY and ORGANIZER_EMAILS, though the purge sends no mail and calls no provider. compose.yaml overrides them with null://null and empty values."
  impact: low
  affected_modules: ["compose.yaml"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testTheMaintenanceContainerGetsNoMailOrRoutingCredentials"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:14:00Z"
- id: CU-20261021-40
  type: chore
  title: "security: every base image is pinned by its multi-arch digest"
  description: "Public-readiness hardening note: base images were pinned by tag only, so a rebuild could pick up a moved or replaced tag. php:8.3-fpm-alpine, composer:2, nginxinc/nginx-unprivileged:1.31-alpine (Dockerfile), postgres:17-alpine (compose.yaml app database and backup, CI service) and axllent/mailpit:v1.27 (dev and staging overlays) now carry their index digests; the unused PHP_VERSION build argument is gone. Dependabot's docker and new docker-compose ecosystems propose new digests."
  impact: low
  affected_modules: ["Dockerfile", "compose.yaml", "compose.dev.yaml", "compose.staging.yaml", ".github/workflows/ci.yml", ".github/dependabot.yml"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testBaseImagesArePinnedByDigest"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:15:00Z"
- id: CU-20261021-41
  type: fix
  title: "security: the GIS importer fetches over https only and refuses responses over 64 MB"
  description: "Public-readiness hardening note: query_url accepted http:// layer URLs, urllib followed redirects to http://, and http_json read whole responses into memory. Layer URLs must be https://, http_json refuses a non-https URL, the default opener follows a redirect only to another https:// URL, and a response over MAX_RESPONSE_BYTES (64 MB) is refused by its Content-Length or by the bytes actually read (never more than the cap is read); these errors are not retried."
  impact: low
  affected_modules: ["scripts/arcgis_common.py"]
  related_tests: ["scripts/tests/test_fetch_arcgis_boundaries.py: TransportHardeningTests (3 tests)"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:16:00Z"
- id: CU-20261021-42
  type: fix
  title: "security: over HTTPS the session cookie is __Host-nhm_session by default"
  description: "Public-readiness hardening note: the __Host- prefix (Secure, Path=/, no Domain, so no sibling subdomain or plain-http page can set or overwrite the cookie) was only documented. Security/Session/HostCookieStorageFactory decorates the native session storage factory and names the cookie __Host-<SESSION_COOKIE_NAME> for requests that arrive over HTTPS (nginx passes HTTPS=on behind the tunnel). Plain-http installs and names that already have a __Host- or __Secure- prefix are unchanged. Upgrading signs current HTTPS sessions out once."
  impact: low
  affected_modules: ["src/Security/Session/HostCookieStorageFactory.php", "config/services.yaml", "docs/runbooks/deployment.md", "scripts/smoke_session.py"]
  related_tests: ["tests/Unit/Security/HostCookieStorageFactoryTest.php", "scripts/smoke_session.py: session cookie is __Host-nhm_session behind HTTPS proxy (Path=/, no Domain)"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:17:00Z"
- id: CU-20261021-43
  type: feature
  title: "Sign out everywhere: My house button and app:user:sign-out"
  description: "Public-readiness hardening note: logout ended only the current session. users.sessions_ended_at (migration Version20261021000001) records a \"Sign out everywhere\"; IdleSessionSubscriber ends every session that began at or before it (audit auth.session_ended; the API answers 401 \"You were signed out on every device\", pages redirect to sign-in). Residents use POST /api/me/sign-out-everywhere (CSRF-checked; My house → Sign out everywhere, after a confirmation), operators app:user:sign-out <email>. The privacy notice mentions it."
  impact: low
  affected_modules: ["src/Security/User.php", "src/Security/UserRepository.php", "src/Security/Session/IdleSessionSubscriber.php", "src/Controller/Api/MeApiController.php", "src/Command/UserSignOutCommand.php", "templates/me/index.html.twig", "public/assets/js/me.js", "templates/legal/privacy.html.twig"]
  related_tests: ["tests/Integration/SignOutEverywhereTest.php (2 tests)", "tests/e2e/specs/claim.spec.mjs: Sign out everywhere on My house ends the resident's session on their other devices"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:18:00Z"
- id: CU-20261021-44
  type: fix
  title: "security: nothing of an unapproved boundary is public, not even its extent"
  description: "Public-readiness hardening note: /api/community withheld an unapproved boundary's outline but published its bounding box (map.bbox). It is now null until the boundary is approved; the public map opens on the configured center (or the boundary's middle) and zoom, as it already did without a boundary. EligibilityTest's earlier \"map can still fit the area\" expectation is replaced by this rule."
  impact: low
  affected_modules: ["src/Community/CommunityPresenter.php"]
  related_tests: ["tests/Integration/EligibilityTest.php: testUnreviewedBoundaryClosesClaimsButNotBrowsing", "tests/Integration/CommunityConfigsTest.php: testVenturaReserveRealPlatExample"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:19:00Z"
- id: CU-20261021-45
  type: fix
  title: "security: GeoJSON uploads are checked against the vertex cap before any ring is examined"
  description: "Public-readiness hardening note: GeoJsonParser counted vertices only after every ring had been validated, including the self-intersection check that compares every pair of segments, so an oversized upload (many rings just under the 5,000-position ring cap) still cost all of that work. Positions are now counted first, without validating anything, and an upload over 20,000 is refused at once."
  impact: low
  affected_modules: ["src/Geo/GeoJsonParser.php"]
  related_tests: ["tests/Unit/Geo/GeoJsonParserTest.php: testTheVertexCapIsCheckedBeforeAnyRingIsExamined (failed on the old code: three self-intersection errors instead of the size error)"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:20:00Z"
- id: CU-20261021-46
  type: fix
  title: "security: the claims CSV export is a CSRF-checked POST"
  description: "Public-readiness hardening note: GET /api/admin/export/claims.csv wrote an audit event, so a prefetch or a cross-site link could add one. The route accepts only POST with the CSRF token as a form field; the organizer overview shows an Export claims button in a small form (the download keeps the page); GET answers 405."
  impact: low
  affected_modules: ["src/Controller/Api/AdminApiController.php", "public/assets/js/admin/overview.js", "public/assets/css/admin.css", "docs/runbooks/event-day.md"]
  related_tests: ["tests/Integration/DisputeAndAdminTest.php: testOrganizerTransfersHidesResolvesAndEverythingIsAudited", "tests/e2e/specs/admin.spec.mjs: organizer downloads the claims list as a CSV (a CSRF-checked POST, never a plain link)"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:21:00Z"
- id: CU-20261021-47
  type: fix
  title: "The map legend no longer covers the OpenStreetMap credit"
  description: "Since 0.7.0-beta.1 added 🍺 21+ to the legend, the legend overlapped Leaflet's attribution line (the OpenStreetMap credit, which must stay readable) at every width up to 1180 px, and at 375 px it wrapped mid-label over it. Measured on the demo stack: overlap at 375, 390, 430, 768, 956 and 1180 px. The legend now sits above the 20 px credit line (bottom 28 px, 26 px on phones), may wrap between items but never inside one, and is capped to the map's width; no overlap remains at any tested width."
  impact: low
  affected_modules: ["public/assets/css/app.css"]
  related_tests: ["tests/e2e/specs/public.spec.mjs: no horizontal overflow and 44px targets at <width>px (+ screenshots) — the legend never covers the OpenStreetMap credit (chromium and WebKit, six widths, both stacks)"]
  commit_ref: "fix/hardening-notes"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:40:00Z"
- id: CU-20261021-48
  type: fix
  title: "nginx serves versioned assets again: a named capture the limit maps cannot overwrite"
  description: "0.7.0-beta.2 added the $client_net map (a regex on $remote_addr). nginx evaluates it when limit_conn is checked, after the location is chosen, and a map regex that matches overwrites the numbered captures: the versioned-assets location's alias /app/public/assets/$1 then pointed at the assets directory, and every CSS and JS request got a 301 to a directory URL (http, port 8080, trailing slash) that the CSP blocked. Live for about five minutes after the first deployment, then rolled back to 0.7.0-beta.1. The location now uses a named capture ($asset), which later regexes leave alone. The local and end-to-end stacks had run the nginx image built before the change, so the browser suite never saw the new config: the development overlay now mounts docker/nginx/default.conf and app-fastcgi.conf into nginx."
  impact: high
  affected_modules: ["docker/nginx/default.conf", "compose.dev.yaml"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testNginxUsesNoNumberedCapturesThatALaterMapRegexCouldOverwrite (fails on the 0.7.0-beta.2 config)", "tests/e2e (full suite, 78 passed / 2 skipped, against stacks running the checkout's nginx config)"]
  commit_ref: "fix/nginx-asset-capture"
  author: "Lance Sandino"
  timestamp: "2026-10-21T13:50:00Z"
- id: CU-20261021-49
  type: feature
  title: "Optional cookie-free Cloudflare Web Analytics (CLOUDFLARE_WEB_ANALYTICS, off by default); Cloudflare documented as one optional example"
  description: "Cloudflare's automatic Web Analytics setup injects a beacon script from static.cloudflareinsights.com into pages of a site it proxies; the strict Content-Security-Policy blocked it, so it measured nothing. CLOUDFLARE_WEB_ANALYTICS=1 (env, default 0) adds that host to script-src in SecurityHeadersSubscriber; connect-src stays 'self' because a proxied site's beacon reports to its own /cdn-cgi/rum. The app never adds the beacon itself, so the setting does nothing without Cloudflare. When it is on, the footer reads \"No ads · Cookie-free visit statistics\" and the privacy page describes Cloudflare Web Analytics (Twig global cloudflare_web_analytics). Docs: the deployment runbook now covers any HTTPS reverse proxy or tunnel (with a Caddy example), and the Cloudflare tunnel, free-plan settings, the Bot Fight Mode CSP note and Web Analytics moved to the optional docs/examples/cloudflare.md; README, SECURITY.md, the architecture overview and config comments no longer present Cloudflare as the way to deploy."
  impact: low
  affected_modules: ["src/Shared/SecurityHeadersSubscriber.php", "config/services.yaml", "config/packages/twig.yaml", "templates/base.html.twig", "templates/legal/privacy.html.twig", "compose.yaml", ".env.example", ".env.dist", "docs/examples/cloudflare.md", "docs/runbooks/deployment.md", "README.md"]
  related_tests: ["tests/Integration/PublicPrivacyTest.php: testCloudflareWebAnalyticsIsOffUnlessConfigured", "tests/Integration/PublicPrivacyTest.php: testCloudflareWebAnalyticsWhenConfiguredIsAllowedAndDisclosed (failed before the setting existed)"]
  commit_ref: "feature/cloudflare-web-analytics"
  author: "Lance Sandino"
  timestamp: "2026-10-21T14:20:00Z"
- id: CU-20261021-50
  type: fix
  title: "Privacy notice: a cookies section, Cloudflare's delivery and security cookies (CLOUDFLARE_PROXY), the email delivery service, a fixed date"
  description: "Behind Cloudflare with Bot Fight Mode, Cloudflare sets its own strictly necessary security cookies (__cf_bm; cf_clearance after a challenge), while the privacy notice said browsing sets no cookies and named no delivery provider. The notice now has a \"Cookies and storage on your device\" section (the sign-in cookie, Cloudflare's security cookies when applicable, local-storage preferences, no advertising or analytics cookies, and why there is no cookie banner). CLOUDFLARE_PROXY=1 (env, default 0; implied by CLOUDFLARE_WEB_ANALYTICS) adds \"Delivered through Cloudflare\" with Cloudflare's privacy policy and cookie list and drops the \"no cookies\" claim. The email bullet says messages go out through the organizer's email delivery service. \"Last updated\" was the current month on every visit; it is now a fixed date."
  impact: low
  affected_modules: ["templates/legal/privacy.html.twig", "config/services.yaml", "config/packages/twig.yaml", "compose.yaml", ".env.example", ".env.dist", "docs/examples/cloudflare.md"]
  related_tests: ["tests/Integration/PublicPrivacyTest.php: testThePrivacyNoticeListsEveryCookieAndWhyThereIsNoBanner", "tests/Integration/PublicPrivacyTest.php: testThePrivacyNoticeDisclosesCloudflareWhenTheSiteIsDeliveredThroughIt (both failed before the change)"]
  commit_ref: "feature/privacy-cookie-notice"
  author: "Lance Sandino"
  timestamp: "2026-10-21T14:45:00Z"
- id: CU-20261021-51
  type: fix
  title: "security: PHP 8.3.36 (CVE-2026-109618, CVE-2026-109619) and current CA certificates; session upload progress off"
  description: "PHP's out-of-band release of 21 October 2026 fixes two critical remote code execution flaws in PHP core, with exploitation attempts reported; production ran 8.3.35. The php:8.3-fpm-alpine base digest now pins 8.3.36, whose Alpine 3.24 layer carries ca-certificates 20261020 (the eMudhra intermediate CA compromise) and OpenSSL 3.5.9; the composer:2 digest is refreshed too. docker/php/php.ini sets session.upload_progress.enabled = 0: the app never reports upload progress, and PHP names it as the mitigation for CVE-2026-109619. A test fails on any supported PHP line older than its fixed release."
  impact: high
  affected_modules: ["Dockerfile", "docker/php/php.ini"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testPhpHasTheOctober2026SecurityFixesAndNoUploadProgress (fails on PHP 8.3.35)", "scripts/smoke_session.py (13/13 on 8.3.36)"]
  commit_ref: "fix/php-8.3.36"
  author: "Lance Sandino"
  timestamp: "2026-10-21T20:50:00Z"
- id: CU-20261021-52
  type: feature
  title: "PHP 8.5 and PHPUnit 12"
  description: "The images move from PHP 8.3 to 8.5.12, the latest stable line (it already contains the October 2026 security fixes). OPcache is part of PHP itself since 8.5, so it is no longer installed as an extension; pcov still builds. The deprecated session.sid_length and session.sid_bits_per_character settings are gone from php.ini (PHP 8.4+ uses fixed 128-bit session ids). Composer's platform is PHP 8.5.0 and CI runs 8.5. PHPUnit moves from 11.5 to 12.5. One test counted a boolean attribute in serialized HTML: PHP 8.4+'s HTML5 parser writes it back as data-whats-new=\"\", so it now counts the element in the DOM."
  impact: medium
  affected_modules: ["Dockerfile", "docker/php/php.ini", "composer.json", "composer.lock", ".github/workflows/ci.yml", "tests/Integration/HeaderBrandingTest.php"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testPhpHasTheOctober2026SecurityFixesAndNoUploadProgress", "PHPUnit 12: 272 tests on PHP 8.5.12", "tests/Integration/HeaderBrandingTest.php: testCityUnderTheNameIsAnOption"]
  commit_ref: "chore/latest-stable"
  author: "Lance Sandino"
  timestamp: "2026-10-21T21:10:00Z"
- id: CU-20261021-53
  type: feature
  title: "PostgreSQL 18, a new data volume, and bin/upgrade-postgres.sh"
  description: "PostgreSQL moves from 17 to 18.6 (app database, backup service, CI) and DATABASE_URL names serverVersion=18. The official 18 image keeps data in /var/lib/postgresql/18/docker, so the stack mounts a new postgres-data volume at /var/lib/postgresql; the old pgdata volume is no longer used and stays untouched. bin/upgrade-postgres.sh moves an install over: it reads the target major from compose.yaml and the running server's major, refuses without a running server, asks before anything, stops nginx/maintenance/app, dumps through a one-off backup container (partial file, renamed when complete), starts the new server on the empty volume, refuses to restore over existing tables, restores in one transaction, compares every table's row count and only then starts the stack. Rehearsed on both development stacks (17.11 → 18.6, 15 tables each, identical counts); running it again is a no-op."
  impact: high
  affected_modules: ["compose.yaml", ".env.dist", ".github/workflows/ci.yml", "bin/upgrade-postgres.sh", "docs/runbooks/upgrade-rollback.md"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testThePostgresImageVolumeAndServerVersionAgree", "scripts/tests/test_operator_scripts.py: UpgradePostgresTests (3 tests)", "bin/upgrade-postgres.sh rehearsal on both development stacks"]
  commit_ref: "chore/latest-stable"
  author: "Lance Sandino"
  timestamp: "2026-10-21T21:15:00Z"
- id: CU-20261021-54
  type: chore
  title: "Latest stable tools: Node 24 LTS, Python 3.14, shapely 2.2.0, Mailpit 1.31.6, current GitHub Actions"
  description: "Test tooling moves to Node 24 (the current LTS) and Python 3.14 with shapely 2.2.0 (2.0.6 has no 3.14 build); Mailpit (development and staging) to v1.31.6 by digest. GitHub Actions move to their latest releases, still pinned by commit: docker/build-push-action 7.4.0, setup-buildx-action 4.4.1, metadata-action 6.2.0, login-action 4.6.0, attest-build-provenance 4.2.2 and setup-php 2.40.0. The new majors switch to the Node 24 runtime and drop options this project never used. They could not run in CI yet: GitHub Actions is not starting jobs for this account (billing)."
  impact: low
  affected_modules: [".github/workflows/ci.yml", ".github/workflows/images.yml", "compose.dev.yaml", "compose.staging.yaml", "AGENTS.md", "README.md"]
  related_tests: ["node:test 29/29 on Node 24", "scripts/tests on Python 3.14 (45 tests)", "tests/e2e full suite with Mailpit 1.31.6"]
  commit_ref: "chore/latest-stable"
  author: "Lance Sandino"
  timestamp: "2026-10-21T21:20:00Z"
- id: CU-20261024-01
  type: fix
  title: "security: PHP hardening for CVE-2026-109619 and CVE-2026-115560; file uploads only in an upload pool behind an nginx multipart gate"
  description: "CVE-2026-115560 (23 October 2026, no fixed PHP release) needs session upload progress on and a runtime switch of the session serializer; php.ini keeps session.upload_progress.enabled = 0, pins session.serialize_handler = php, and a test fails if any PHP source under src/, config/ or public/ calls ini_set() on a session setting. PHP's other partial mitigation for CVE-2026-109619 on 8.5 is applied (fatal_error_backtraces = Off), user_ini.filename is empty, max_file_uploads = 1, and php-prod.ini sets zend.assertions = -1 and zend.exception_ignore_args = On (production was running with assertions on and trace arguments kept). PHP-FPM gets a third, on-demand upload pool (FPM_UPLOAD_MAX_CHILDREN, default 2) that alone accepts files; the www and mail pools set file_uploads off; every pool disables exec, passthru, shell_exec, system, proc_open, popen and pcntl_exec and sets allow_url_fopen off (the routing provider uses CurlHttpClient; the CLI keeps its defaults). nginx routes POST /api/admin/community/logo, /geometries/import and /addresses/import by exact location to upload.sock (limit_conn 1) and answers 415 to a multipart body anywhere else, keyed on $content_type (the CONTENT_TYPE PHP receives) and the normalized $uri with no numbered captures. The healthcheck pings all three pools. The development entrypoint hands a fresh branding volume to www-data (it was root's, so logo uploads failed in the dev stack; production already owns it)."
  impact: high
  affected_modules: ["docker/php/php.ini", "docker/php/php-prod.ini", "docker/php/fpm-pool.conf", "docker/php/healthcheck.sh", "docker/php/entrypoint.sh", "docker/nginx/default.conf", "Dockerfile", "compose.yaml", "docs/runbooks/deployment.md", "SECURITY.md"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testPhpHasTheOctober2026SecurityFixesAndNoUploadProgress, testProductionPhpCompilesAssertionsOutAndHidesTraceArguments, testOnlyTheUploadPoolAcceptsFilesAndNoWebWorkerRunsPrograms, testNginxSendsMultipartBodiesOnlyToTheUploadPool, testPhpFpmListensOnlyOnUnixSocketsReachableByNginx, testNginxCapsOneClientsRequestsInPhpBelowThePoolSizes (6 fail on the 0.8.0-beta.1 config)", "tests/e2e/specs/admin.spec.mjs: organizer downloads the claims list as a CSV … and uploads a logo through the upload pool (upload and removal succeed; multipart to four other paths answers 415)"]
  commit_ref: "feature/security-release-0.8.0-beta.2"
  author: "Lance Sandino"
  timestamp: "2026-10-24T13:10:00Z"
- id: CU-20261024-02
  type: fix
  title: "security: brotli 1.2.1 (CVE-2026-111497) via apk upgrade in both runtime images; Trivy and npm audit in CI; daily security checks"
  description: "A Trivy scan of the 0.8.0-beta.1 images found brotli-libs 1.2.0-r1 (CVE-2026-111497, Brotli decoder out-of-bounds write) in the app and nginx images: Alpine ships 1.2.1-r0 but the pinned php:8.5-fpm-alpine and nginx-unprivileged:1.31-alpine images were not rebuilt with it yet. Both runtime stages now run apk upgrade --no-cache, so Alpine's security fixes never wait for a base-image rebuild; digests still pin the base. CI's PR image job saves the built images and scans them with Trivy 0.76.1 (pinned by digest, no socket or token), failing on any fixed HIGH/CRITICAL package, and the js job runs npm audit on the end-to-end tools' lock file. A new daily workflow (security.yml) checks the published :latest and :edge images (PHP is the newest release of its line per php.net's releases API; Trivy) and runs composer audit and npm audit on the lock files, so a new advisory fails a run without a push."
  impact: high
  affected_modules: ["Dockerfile", ".github/workflows/ci.yml", ".github/workflows/security.yml", "SECURITY.md"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testRuntimeImagesTakeAlpineSecurityFixesAndAreScanned, testBaseImagesArePinnedByDigest (workflow tool images)", "Trivy 0.76.1 on the built prod and nginx images: 1 HIGH each before (brotli-libs 1.2.0-r1), exit 0 after"]
  commit_ref: "feature/security-release-0.8.0-beta.2"
  author: "Lance Sandino"
  timestamp: "2026-10-24T13:15:00Z"
- id: CU-20261024-03
  type: fix
  title: "security: escape home ids in the list and selected-home markup (CodeQL js/xss-through-dom)"
  description: "CodeQL default setup (enabled 2026-10-23) reported js/xss-through-dom at public/assets/js/home.js:155 and :218: home ids from the page's boot JSON (DOM text) went into data-id, data-save and data-report attributes of innerHTML without escaping. Ids are server integers today, so nothing was exploitable, but cardHtml and selectedHtml now pass every page-data value, ids included, through esc()."
  impact: low
  affected_modules: ["public/assets/js/lib/render.js"]
  related_tests: ["tests/js/lib.test.mjs: cards and the selected-home panel escape every value taken from the page data, ids included (fails before the change)"]
  commit_ref: "feature/security-release-0.8.0-beta.2"
  author: "Lance Sandino"
  timestamp: "2026-10-24T13:20:00Z"
- id: CU-20261024-04
  type: chore
  title: "Latest stable dependencies: DoctrineBundle 3, DoctrineMigrationsBundle 4, PHPUnit 13, PHPStan 2.3.2; current base-image digests; Mailpit 1.32.0"
  description: "Following D-20261021-36 (latest stable everywhere; Symfony stays on 7.4 LTS): doctrine/doctrine-bundle 2.19.1 → 3.3.2 and doctrine/doctrine-migrations-bundle 3.7.1 → 4.0.1 (DBAL-only; the removed use_savepoints option is dropped because DBAL 4 always uses savepoints, and the bundle's server_version now says 18 like DATABASE_URL), phpunit/phpunit 12.5.40 → 13.4.3 (EligibilityTest's private attempt() helper is renamed tryToList() because PHPUnit 13 adds a final TestCase::attempt()), phpstan 2.3.2. Base digests: php:8.5-fpm-alpine (PHP 8.5.12, rebuilt 21 October), composer:2 (2.10.3), postgres:18-alpine (18.6); Mailpit v1.32.0 for development and staging."
  impact: medium
  affected_modules: ["composer.json", "composer.lock", "config/packages/doctrine.yaml", "config/reference.php", "Dockerfile", "compose.yaml", "compose.dev.yaml", "compose.staging.yaml", ".github/workflows/ci.yml", "tests/Integration/EligibilityTest.php"]
  related_tests: ["PHPUnit 13.4.3: 276 tests, 5398 assertions on PHP 8.5.12 (lines 94.03 %)", "migrations up/down/up on a blank database", "tests/e2e full suite (78 passed, 2 skipped)", "scripts/smoke_session.py 13/13"]
  commit_ref: "feature/security-release-0.8.0-beta.2"
  author: "Lance Sandino"
  timestamp: "2026-10-24T13:25:00Z"
- id: CU-20261024-05
  type: fix
  title: "fix: 0.8.0-beta.3 — disable_functions at PHP startup in the prod image, not per FPM pool (0.8.0-beta.2 answered 500 with OPcache preloading)"
  description: "Incident 2026-10-24 19:14–19:19 UTC: 0.8.0-beta.2 deployed to the live server answered every request with 500 (ArgumentCountError: posix_getpid() expects exactly 0 arguments, 1 given, raised from spl_object_id() in DBAL's TypeRegistry). php_admin_value[disable_functions] in the FPM pools is applied when a pool starts, after the master process has preloaded the code with OPcache (opcache.preload, production image only); removing functions then shifts the internal function table under the preloaded calls, so they reach the wrong functions. The development and CI images do not preload, so PHPUnit, Playwright and the session smoke test all passed. The live site was rolled back to 0.8.0-beta.1 in about five minutes (all six asset URLs 200). Fix: the pools no longer set disable_functions; docker/php/php-prod-final.ini sets it at PHP startup, before preloading, and the prod stage copies it after its last RUN (composer dump-autoload and cache:warmup run programs). It covers PHP-FPM and the production console; the console commands (migrations, app:db:ping, app:maintenance:purge) still run. allow_url_fopen and file_uploads stay per pool (plain ini flags, no function-table change)."
  impact: high
  affected_modules: ["docker/php/fpm-pool.conf", "docker/php/php-prod-final.ini", "Dockerfile", "DECISIONS.md"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testOnlyTheUploadPoolAcceptsFilesAndNoWebWorkerRunsPrograms (no pool sets disable_functions; php-prod-final.ini sets it and is copied after the prod stage's last RUN)", "bin/smoke-prod-image.sh: fails on the published 0.8.0-beta.2 images (nginx unhealthy, /healthz 500), passes on the fixed images (7/7 checks)"]
  commit_ref: "fix/prod-disable-functions"
  author: "Lance Sandino"
  timestamp: "2026-10-24T19:45:00Z"
- id: CU-20261024-06
  type: feature
  title: "Production-image smoke test gates publishing and deploys (bin/smoke-prod-image.sh)"
  description: "bin/smoke-prod-image.sh starts the given prod app and nginx images with compose.yaml under a throwaway project with random secrets (production settings, OPcache preloading, read-only containers), then checks /healthz (status and database ok), the home page, every /assets/v… URL on it, 415 for a multipart body to /, /api/auth/request-link, /api/me/homes and /api/admin/community, that the logo upload endpoint reaches PHP (401/403 without a session), that all three FPM pools answer their ping, and that console commands run (app:db:ping, app:maintenance:purge); it prints the app log on failure and always removes the stack and its volumes. CI's pull-request image job runs it on the images it builds; the Images workflow gains a smoke job on amd64 and arm64 that pulls the just-built images by digest and must pass before the merge job applies :sha, :edge, a version or :latest. The release runbook and AGENTS.md list it, including a run against the published tag before a deploy."
  impact: medium
  affected_modules: ["bin/smoke-prod-image.sh", ".github/workflows/ci.yml", ".github/workflows/images.yml", "docs/runbooks/release.md", "AGENTS.md"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testProductionImagesAreSmokeTestedBeforeTheyArePublished", "bin/smoke-prod-image.sh red on 0.8.0-beta.2, green on the fix"]
  commit_ref: "fix/prod-disable-functions"
  author: "Lance Sandino"
  timestamp: "2026-10-24T19:50:00Z"
- id: CU-20261030-01
  type: chore
  title: "security: 0.8.0-beta.4 — curl 8.23.0 in the images (CVE-2026-92392), Symfony 7.4.22 (CVE-2026-45070), PHPUnit 13.4.5; current digests and actions"
  description: "The daily Security checks workflow failed from 2026-10-26 to 2026-10-30: Trivy found curl/libcurl 8.22.0-r0 (CVE-2026-92392, HIGH, fixed in 8.23.0-r0) in the published :latest and :edge app images, and from 2026-10-27 composer audit reported phpunit/phpunit 13.4.3 (GHSA-9vw2-f4vp-x7gf, fixed in 13.4.4) and from 2026-10-29 symfony/mime 7.4.19 (CVE-2026-45070, fixed in 7.4.22). Rebuilt images take curl 8.23.0-r0 through the existing `apk upgrade` (D-20261024-02). `composer update` within the existing constraints: Symfony 7.4.22 (all components), phpunit 13.4.5, phpunit-bridge 8.1.10, doctrine-bundle 3.3.4, phpstan 2.3.4, symfony/flex 2.12.0 (composer.json lower bounds bumped by bump-after-update). Base digests: php:8.5-fpm-alpine (PHP 8.5.12, rebuilt), postgres:18-alpine (18.6, rebuilt), composer:2 (2.11.0). GitHub Actions docker/login-action 4.7.0 and actions/setup-python 7.1.0 (the open Dependabot PRs). Both Trivy loops (CI and daily) now scan every image and fail at the end, because `bash -e` stopped the daily loop at the app image and never scanned nginx (the published nginx image carried curl 8.22.0-r0 too)."
  impact: medium
  affected_modules: ["composer.json", "composer.lock", "Dockerfile", "compose.yaml", ".github/workflows/ci.yml", ".github/workflows/security.yml", ".github/workflows/images.yml", "VERSION", "CHANGELOG.md"]
  related_tests: ["tests/Unit/Deploy/ContainerConfigTest.php: testRuntimeImagesTakeAlpineSecurityFixesAndAreScanned (each scan records its failure and the step exits after both; fails on the 0.8.0-beta.3 workflows)", "composer audit --locked: no advisories", "Trivy 0.76.1 on the built prod and nginx images: 0 fixed HIGH/CRITICAL", "bin/smoke-prod-image.sh nhm-app nhm-nginx smoke: 7/7", "PHPUnit 13.4.5: 278 tests, 5411 assertions (lines 94.39 %)"]
  commit_ref: "fix/security-0.8.0-beta.4"
  author: "Lance Sandino"
  timestamp: "2026-10-30T11:45:00Z"
- id: CU-20261030-02
  type: fix
  title: "Compact list keeps one line per home on the event day: the status chip takes the hours' place"
  description: "CI's Playwright run on 2026-10-30 failed in webkit-iphone (compact row 80.2 px, limit < 80): the demo community's event date is 2026-10-30, and only on the event day does each card carry a status chip (Starts …, Handing out, Done for tonight). Next to the hours and tag icons it wrapped every such compact row to a second line in both engines (WebKit 80.2 px, Chromium 79.2 px, versus 57.7 px on other days); Chromium passed by 0.8 px. metaLine now wraps the hours in span.card-hours, and in the compact list a meta line with a status chip hides the hours visually (same visually-hidden technique as .tag-text, so screen readers still read them). The test pinned nothing, so it only met the event-day layout by chance: it now fixes the browser clock to the community's event day (atEventTime helper, from /api/event) at 12:00, 18:00 and 23:00 and requires every row under 70 px (one line of facts)."
  impact: low
  affected_modules: ["public/assets/js/lib/render.js", "public/assets/css/app.css", "tests/e2e/specs/public.spec.mjs", "tests/e2e/specs/helpers.mjs"]
  related_tests: ["tests/e2e/specs/public.spec.mjs: compact list: one row per home with icons, remembered after reload; long notes are clamped on cards (fails before the change on chromium 79.2 px and webkit-iphone 80.2 px)", "tests/js: 30/30"]
  commit_ref: "fix/security-0.8.0-beta.4"
  author: "Lance Sandino"
  timestamp: "2026-10-30T12:30:00Z"
- id: CU-20261030-03
  type: chore
  title: "Symfony 8.1 (from 7.4 LTS): all components on 8.1.x, Flex recipes taken where they fit, decision D-20261030-01"
  description: "The owner chose the Symfony 8.x line (2026-10-30), superseding the Symfony clause of D-20261021-36. composer.json requires every Symfony component (and extra.symfony.require) as 8.1.*; `composer update symfony/* -W` moved 41 packages from 7.4.x to 8.1.x (8.1.10 for most) and dropped symfony/polyfill-php83 and masterminds/html5. The test suite already failed on any deprecation, so no app code changed. Flex recipe updates: framework-bundle 8.1 adds Kernel::getAllowedEnvs() (prod, dev, test; no install uses another APP_ENV) and a generic AGENTS.md, which was declined in favour of the project's own; security-bundle's dev firewall pattern (which would also exempt /assets and /build) was declined; doctrine-bundle's ORM config was declined (DBAL only, D-20261018-02); doctrine-migrations-bundle's move to App\\Migrations under src/Migrations was not applied because production databases record the DoctrineMigrations version names. config/reference.php regenerated by Symfony 8.1. Docs (AGENTS.md, README, architecture overview) name Symfony 8."
  impact: medium
  affected_modules: ["composer.json", "composer.lock", "symfony.lock", "src/Kernel.php", "config/reference.php", "DECISIONS.md", "AGENTS.md", "README.md", "docs/architecture/overview.md", "VERSION", "CHANGELOG.md"]
  related_tests: ["tests/Unit/Deploy/FrameworkPolicyTest.php: testSymfonyComponentsShareTheMinorFlexIsToldToUse (fails on the 7.4 composer.json), testMigrationsKeepTheNamespaceProductionDatabasesRecord", "PHPUnit 13.4.5: 280 tests on Symfony 8.1.10 (lines 94.35 %)", "lint:container, lint:yaml, lint:twig; dev and prod cache warmup; debug:container --deprecations: none", "tests/e2e full suite on both dev stacks: 78 passed, 2 skipped", "scripts/smoke_session.py 13/13", "bin/smoke-prod-image.sh 7/7 (Symfony 8.1.10 in the prod image)", "Trivy: 0 fixed HIGH/CRITICAL"]
  commit_ref: "feature/symfony-8.1"
  author: "Lance Sandino"
  timestamp: "2026-10-30T21:00:00Z"
- id: CU-20261031-01
  type: docs
  title: "README status names the released version again (0.9.0-beta.1, PHP 8.5, Symfony 8.1, PostgreSQL 18); a test keeps it in step with VERSION"
  description: "Baseline step of the event-types roadmap. The README status line still said 0.8.0-beta.1 after 0.9.0-beta.1 shipped. It now names the version and stack, and ReleaseConsistencyTest compares the status line with VERSION so it cannot drift again (red on the old README, green after). Baseline before any event-type change, on fresh dev stacks at 0.9.0-beta.1: PHPUnit 280 passing (the new README test was the 281st, red), lines 93.96 %; PHPStan clean; composer audit clean; node:test 30/30; Python 45/45; Playwright 78 passed, 2 skipped; session smoke 13/13. The public API JSON of both stacks and the tiles-blocked e2e screenshots were kept as the parity baseline for the event-type extraction."
  impact: none
  affected_modules: ["README.md", "tests/Unit/About/ReleaseConsistencyTest.php"]
  related_tests: ["tests/Unit/About/ReleaseConsistencyTest.php: testTheReadmeStatusNamesTheCurrentVersion (fails on the 0.8.0-beta.1 status line)"]
  commit_ref: "feature/event-types-p1"
  author: "Lance Sandino"
  timestamp: "2026-10-31T05:45:00Z"
- id: CU-20261031-02
  type: feature
  title: "Event-type registry and the Halloween event type: listing details, statuses, filters, copy and Twig fragments move out of the shared code (ROADMAP Stage 1)"
  description: "App\\Event\\EventType is the contract of a code-defined event type (key, default name/branding/copy, schedule kind and maximum days, hour choices, statuses with a day-scoped flag, feature switches, strict normalizeDetails(), strict publicDetails(), legacyFields(), filters, legend, stats, route profiles, whitelisted Twig fragments). EventTypeRegistry collects the tagged implementations by immutable key (KEYS halloween, holiday_lights, garage_sale; ENABLED halloween only; problem() for configuration errors; builtIn() for code without a container). HalloweenType (+ HalloweenCopy) carries everything Halloween: the nine details, the 0.9 coercion and offerings rule, statuses, the eight filters, legend, stats, reminders and words. ListingDraft keeps only the shared fields (hours, status, note, visibility) and asks the community's type for the details; input may send them as a `details` object (canonical) or, during the compatibility window, as the flat Halloween fields; unknown keys are refused at either level (D-20261031-02). A draft carries `details` next to the flat fields; a 0.9 claim intent without details gets them from its flat fields (ListingDraft::withDetails). ListingRepository reads `details` and projects it through the type; public homes keep exactly the 0.9 keys (D-20261031-04). CommunityPresenter takes reminders and stats from the type. Templates ask the type through Twig/EventTypeExtension: event_type(key) for words, statuses, filters, legend and hours, event_fragment(key, name) for the whitelisted templates/event/halloween/ fragments (hero art, card symbol and tags, confirmation tags, the listing form fields, the check chips moved from partials/). The server-rendered pages of both dev stacks are byte-identical to 0.9 apart from whitespace between flex items and the new event.type in the page data."
  impact: high
  affected_modules: ["src/Event/", "src/Listing/ListingDraft.php", "src/Listing/ListingRepository.php", "src/Listing/ResidentHomes.php", "src/Listing/LegacyListingColumns.php", "src/Community/CommunityPresenter.php", "src/Claim/ClaimService.php", "src/Command/DemoSeedCommand.php", "src/Controller/", "src/Routing/RoutePlanner.php", "src/Twig/", "templates/"]
  related_tests: ["tests/Unit/Event/EventTypeRegistryTest.php", "tests/Unit/Event/HalloweenTypeTest.php", "tests/Unit/Listing/ListingDraftParityTest.php (600 seeded inputs against the frozen 0.9 ListingDraft in tests/Support/Legacy)", "tests/Unit/Listing/ListingDraftTest.php (the five 0.9 tests unchanged + details object, unknown fields, 0.9 drafts)", "tests/Integration/GoldenApiParityTest.php (0.9.0-beta.1 answers of both dev stacks)", "tests/Integration/PublicPrivacyTest.php (unchanged: exact public home keys)"]
  commit_ref: "feature/event-types-p1"
  author: "Lance Sandino"
  timestamp: "2026-10-31T07:00:00Z"
- id: CU-20261031-03
  type: feature
  title: "listings.details JSONB, backfilled from the Halloween columns; writes keep both; a trigger keeps details right after an image-only rollback"
  description: "Migration Version20261031000001 adds `listings.details` (JSONB, NOT NULL, DEFAULT '{}', CHECK object), fills it from the nine Halloween columns, and creates listings_details_from_columns() with the BEFORE INSERT OR UPDATE trigger listings_details_sync that merges the columns into details on every write. The app writes details and the columns (LegacyListingColumns) with the same values; reads use details. Without the trigger, a rollback to the 0.9 image (which writes only the columns) followed by an upgrade would leave details stale, because the migration has already run. Down drops the trigger, the function and the column only (D-20261031-03)."
  impact: medium
  affected_modules: ["migrations/Version20261031000001.php", "src/Listing/ListingRepository.php", "src/Listing/ResidentHomes.php", "src/Listing/LegacyListingColumns.php"]
  related_tests: ["tests/Integration/ListingDetailsMigrationTest.php: testEveryAppWriteKeepsTheLegacyColumnsAndDetailsEqual, testRowsWrittenThe09WayGetTheirDetailsFromTheColumns, testThePublicMapReadsDetails, testTheMigrationGoesDownAndUpAgainAndBackfillsEveryRow", "migrations up/down/up on the test database"]
  commit_ref: "feature/event-types-p1"
  author: "Lance Sandino"
  timestamp: "2026-10-31T07:00:00Z"
- id: CU-20261031-04
  type: feature
  title: "event.type in the community settings (default halloween) and in GET /api/event and /api/community"
  description: "CommunitySchema validates event.type through the registry: absent or empty means halloween; an unknown key or one this version does not enable is a configuration error naming the choices; the type supplies the defaults for the event name, subtitle, branding and its feature switches (same values and order as before for Halloween). Settings saved without event.type load as Halloween (CommunityRepository). The public payload's event gains `type`; every other key keeps its value and order. The shipped configs name `type: halloween`."
  impact: low
  affected_modules: ["src/Community/CommunitySchema.php", "src/Community/CommunityConfig.php", "src/Community/CommunityRepository.php", "src/Community/CommunityPresenter.php", "config/community.example.yaml", "examples/"]
  related_tests: ["tests/Unit/Community/CommunitySchemaTest.php: testTheEventTypeDefaultsToHalloweenAndAnUnknownOrDisabledTypeIsRefused", "tests/Unit/Event/EventTypeRegistryTest.php", "tests/Integration/GoldenApiParityTest.php: testTheEventNamesItsTypeWhileHomesKeepExactlyTheirKeys"]
  commit_ref: "feature/event-types-p1"
  author: "Lance Sandino"
  timestamp: "2026-10-31T07:00:00Z"
- id: CU-20261031-05
  type: refactor
  title: "Browser event modules: filters, render, time, home, register, My house and the route panel ask the Halloween module"
  description: "public/assets/js/events/index.js is the browser registry (eventType(key), unknown keys throw); events/halloween.js holds the filter toggles and predicates, search words, tags, pin symbol and class, statusOf, route notes, copy, hour choices and the form helpers (formDetails, fillForm, validateDetails). lib/filters.js keeps search, zone, time and saved and builds the filter object from the type (defaultFilters; DEFAULT_FILTERS is Halloween's, unchanged); lib/render.js draws the type's tags and symbols; lib/time.js asks the type for a status (clock helpers moved to lib/clock.js, re-exported). home.js, route-panel.js, register.js and me.js take the type from the page data or /api/me; the listing forms send the details as a `details` object. Escaping is unchanged (esc() on every value, now also on the type's tag words)."
  impact: medium
  affected_modules: ["public/assets/js/events/", "public/assets/js/lib/filters.js", "public/assets/js/lib/render.js", "public/assets/js/lib/time.js", "public/assets/js/lib/clock.js", "public/assets/js/home.js", "public/assets/js/register.js", "public/assets/js/me.js", "public/assets/js/route-panel.js"]
  related_tests: ["tests/js/events.test.mjs (400 seeded homes, filters and moments against the frozen 0.9 libraries in tests/js/legacy)", "tests/js/lib.test.mjs (unchanged)", "tests/e2e full suite on both stacks"]
  commit_ref: "feature/event-types-p1"
  author: "Lance Sandino"
  timestamp: "2026-10-31T07:10:00Z"
- id: CU-20261031-06
  type: feature
  title: "Organizers see the event type: Community → Event lists the types this version offers; features and labels come from the type"
  description: "GET /api/admin/community also returns event_types (the enabled types: key, name) and event_type (the current type's feature switches and console labels). The Community page shows an Event type select (Halloween only in this version), saves event.type, builds the Features panel from the type's switches plus the shared ones (zone filter, suggested stop order) and takes the reminders label and the gated-open access label from the type. A type this version does not enable is refused with the configuration error. The overview page is unchanged."
  impact: low
  affected_modules: ["src/Controller/Api/AdminApiController.php", "public/assets/js/admin/community.js"]
  related_tests: ["tests/Integration/AdminConsoleTest.php: testTheConsoleShowsTheEventTypeAndOnlyOffersEnabledTypes", "tests/e2e/specs/admin.spec.mjs"]
  commit_ref: "feature/event-types-p1"
  author: "Lance Sandino"
  timestamp: "2026-10-31T07:15:00Z"
- id: CU-20261031-07
  type: chore
  title: "0.10.0-beta.1: release notes, decisions D-20261031-01 to -04, ROADMAP Stage 1 done, event-type architecture note, rollback note, test report"
  description: "VERSION, the CHANGELOG heading and the README status name 0.10.0-beta.1. docs/architecture/event-types.md explains the module contract (PHP type, registry, Twig fragments, browser module), the listing data (details object, strict keys, compatibility window, trigger) and how a later type is added; the overview, AGENTS.md structure table, setup runbook and upgrade/rollback runbook (image-only rollback to 0.9 is enough) follow. Gates on the final tree are in docs/quality/test-report.md."
  impact: none
  affected_modules: ["VERSION", "CHANGELOG.md", "README.md", "ROADMAP.md", "AGENTS.md", "docs/architecture/", "docs/runbooks/setup.md", "docs/runbooks/upgrade-rollback.md", "docs/quality/test-report.md"]
  related_tests: ["tests/Unit/About/ChangelogReaderTest.php: testRealChangelogStartsWithTheCurrentVersion", "tests/Unit/About/ReleaseConsistencyTest.php", "PHPUnit 308 tests (lines 94.79 %)", "tests/e2e: 78 passed, 2 skipped", "scripts/smoke_session.py 13/13", "bin/smoke-prod-image.sh 7/7"]
  commit_ref: "feature/event-types-p1"
  author: "Lance Sandino"
  timestamp: "2026-10-31T07:40:00Z"
- id: CU-20261031-08
  type: feature
  title: "Event occurrences and event-scoped listings: event_occurrences, event_listings, event_listing_windows, backfilled losslessly from listings; a trigger carries the previous release's writes forward"
  description: "Migration Version20261031000002 adds the three tables exactly as designed (one active occurrence by the partial unique index uniq_one_active_event; UNIQUE(event_id, address_id); windows keyed by listing and local date, end after start) plus indexes for the public query and by address. Backfill: one Halloween occurrence per distinct listings.event_date, name and default hours from the community settings; the configured date is active, earlier ones archived, later ones drafts; without listings the configured event is still created active; a database without settings gets none (the bootstrap creates it). Every listings row is copied with its details, status, note, publication, moderation and timestamps, and one window (its date and hours). The legacy table stays. listings_bridge_to_events (AFTER INSERT/UPDATE/DELETE on listings) copies writes made by the previous release after an image-only rollback into the active one-day Halloween occurrence (a row moved off its day or deleted leaves it), skipping the app's own mirror writes (nhm.bridge_writer). Down drops only the new tables and the function."
  impact: high
  affected_modules: ["migrations/Version20261031000002.php", "tests/Support/AppTestCase.php", "tests/Support/RunsMigrations.php"]
  related_tests: ["tests/Integration/EventOccurrencesMigrationTest.php: testTheBackfillKeepsEverySeasonApartAndThePublicMapExactlyAsItWas (seeded 0.10 database: two past seasons, a future date, hidden, pending review, unpublished, out of boundary, moderation hold; counts, every copied field and the public projection equal 0.10's frozen query; down and up again), testWithoutListingsTheConfiguredEventStillBecomesTheActiveOne, testAnInstallThatIsNotSetUpYetGetsNoEvent", "tests/Integration/LegacyBridgeTest.php: testRowsThe09ImageWritesReachTheActiveEvent, testTheAppsOwnMirrorIsNotCopiedBackAndAnotherDaysRowsStayOut"]
  commit_ref: "feature/event-types-p2"
  author: "Lance Sandino"
  timestamp: "2026-10-31T09:00:00Z"
- id: CU-20261031-09
  type: feature
  title: "Listings are read and written per event: the active occurrence (CommunityConfig::event) scopes the map, My house, claims, the demo seed, the season archive, the organizer's counts, address list, export and moderation; the legacy row is mirrored"
  description: "CommunityRepository loads the active occurrence (when this release offers its type) into CommunityConfig::event and puts its name, day and hours into the settings' event block; saving settings writes the block to the occurrence first (Event/OccurrenceSettingsSync: the first save creates the first occurrence; later saves change name and hours, and dates only while nobody is listed; the type of an existing event never changes) and the stored block mirrors it. EventBlockSchema (moved out of CommunitySchema) also accepts starts_on/ends_on for types with several days; a one-day event's block is unchanged. ListingRepository reads and writes event_listings with windows; public homes are the active event's only. ResidentHomes is owner-scoped per event (all/find/update/create/owns). ClaimService lists for the active event and refuses a claim intent prepared for another event. DemoSeedCommand lists the fictional homes for the active event (again, from the file, when an organizer started a new event on the demo stack). SeasonArchiveCommand unpublishes legacy rows before the active event. Organizer counts, pending addresses, the address list, the claims export and hide/unhide use the active event's listing; the hold stays on the address. LegacyListingBridge mirrors every write of the active one-day Halloween event into the legacy row (0.10's exact values), unpublishes an archived day's rows and deletes purged ones. Registration is closed and the home page is a neutral 'No event on the map right now' page (home/no_event.html.twig, no listing action, no map scripts) when no usable event is active."
  impact: high
  affected_modules: ["src/Event/EventOccurrence.php", "src/Event/EventOccurrenceRepository.php", "src/Event/OccurrenceSettingsSync.php", "src/Event/OccurrenceSettings.php", "src/Community/", "src/Listing/", "src/Claim/ClaimService.php", "src/Command/DemoSeedCommand.php", "src/Command/SeasonArchiveCommand.php", "src/Admin/ClaimAdministration.php", "src/Address/AddressRegistry.php", "src/Controller/", "templates/base.html.twig", "templates/home/no_event.html.twig"]
  related_tests: ["tests/Integration/LegacyBridgeTest.php: testEveryFlowKeepsTheLegacyTableEqualToTheActiveEvent (claim, edit, status, visibility, pending review, hide/unhide, release, erasure)", "tests/Integration/OwnershipHardeningTest.php: testResidentHomesNeverReachAnotherResidentsHome (same assertions on the event-scoped API, plus create and the event row)", "the whole 0.10 suite unchanged otherwise (PublicPrivacyTest last-season case, CommandsTest season archive, ListingDetailsMigrationTest)"]
  commit_ref: "feature/event-types-p2"
  author: "Lance Sandino"
  timestamp: "2026-10-31T09:00:00Z"
- id: CU-20261031-10
  type: feature
  title: "Schedule core and display status: windows checked per schedule kind; the display status derived by shared rules in PHP and JS from one fixture file"
  description: "Event/Schedule/ScheduleWindows validates a listing's windows against its occurrence for every kind (single_day: exactly the event day; nightly_range up to 62 days; sale_days up to 3; the organizer's max_days cap; dates inside the event, no duplicates, end after start on the same day — no overnight windows —, hour bounds) and picks the window that answers 'when' (today, else next, else last). ListingDraft accepts `windows` only for types with several days (Halloween input unchanged: `windows` is an unknown field) and turns a draft into windows (windowsFor) and a day-scoped status into its local date (statusDate). Event/Schedule/DisplayStatus and public/assets/js/lib/schedule.js derive the display status from the stored status, status_date, windows and now in the community time zone, by the type's rule table (EventType::displayRules(); Halloween's gives exactly statusOf's words open/later/done/paused/out); tests/fixtures/schedule/display-status.json holds 42 cases (DST change days in New York and Chicago, New Year's Eve, month ends, day-scoped statuses, before/inside/after a window, other days) and the rules of all three types."
  impact: medium
  affected_modules: ["src/Event/Schedule/", "src/Event/EventType.php", "src/Event/Type/HalloweenType.php", "src/Listing/ListingDraft.php", "public/assets/js/lib/schedule.js", "public/assets/js/events/halloween.js", "tests/fixtures/schedule/display-status.json", "tests/Support/TestEventType.php"]
  related_tests: ["tests/Unit/Event/Schedule/DisplayStatusTest.php (the shared cases)", "tests/js/schedule.test.mjs (the same cases; 2,000 seeded homes and moments where Halloween's rules equal statusOf)", "tests/Unit/Event/Schedule/ScheduleWindowsTest.php", "tests/Unit/Listing/ListingDraftWindowsTest.php"]
  commit_ref: "feature/event-types-p2"
  author: "Lance Sandino"
  timestamp: "2026-10-31T09:00:00Z"
- id: CU-20261031-11
  type: feature
  title: "Public API names the event: /api/event gains the occurrence's id, dates, default hours, schedule kind, filters, words and rules; homes gain event_id, event_type, details, windows and display_status after their 0.9 keys"
  description: "CommunityPresenter builds the event block explicitly: the 0.9 keys keep their order (date, name, ends_at, starts_at … timezone), then type, id, active, starts_on, ends_on, default_hours, schedule_kind, filters (the type's filters the community offers), copy (the type's public words) and rules (the occurrence's own). Between events active is false and no type or occurrence is named. A public home keeps its 0.9 keys and values (the Halloween details flat, the window's hours) and then exactly event_id, event_type, details (the type's strict projection), windows [{date,start,end}] and display_status; status is the effective one. /api/homes also names event_id. PublicPrivacyTest's whitelist is widened on purpose with a test that the 0.9 keys and values are unchanged; the 0.11 answers of the demo are pinned in tests/fixtures/golden/0.11.0-beta.1 (frozen test clock)."
  impact: medium
  affected_modules: ["src/Community/CommunityPresenter.php", "src/Listing/ListingRepository.php", "src/Controller/Api/PublicApiController.php", "tests/fixtures/golden/0.11.0-beta.1/"]
  related_tests: ["tests/Integration/PublicPrivacyTest.php: testOnlyOptedInCurrentSeasonListingsArePublic (0.9 keys then exactly the event keys), testTheNewKeysLeaveThe09KeysAndValuesUnchanged, testNoDraftOrArchivedEventIsEverPublic", "tests/Integration/GoldenApiParityTest.php: the 0.9 baselines unchanged, testTheEventAndHomesAddExactlyTheDeliberateKeys, testTheEventScopedAnswersAreThe011Ones"]
  commit_ref: "feature/event-types-p2"
  author: "Lance Sandino"
  timestamp: "2026-10-31T09:00:00Z"
- id: CU-20261031-12
  type: feature
  title: "Route profiles: walking stays the default and keeps its cache key; driving only when the type allows it, the event enables it and a provider is configured"
  description: "RoutingProviderInterface::route(points, profile); OpenRouteServiceProvider asks foot-walking or driving-car. RoutePlanner::plan(stops, community, profile = walking): walking is unchanged (verified crossings, one walkable group, the 0.9 fingerprint 'provider|foot-walking|points'); driving requires the type's routeProfiles, the active event's settings.route_modes and an enabled, configured provider, may join areas, and is cached under its own profile. POST /api/routes/plan takes an optional profile. No driving UI yet; Halloween routes are walking routes."
  impact: low
  affected_modules: ["src/Routing/", "src/Controller/Api/RouteApiController.php", "tests/Support/FixtureRoutingProvider.php"]
  related_tests: ["tests/Integration/RouteProfileTest.php", "tests/Integration/RoutePlanTest.php (unchanged)"]
  commit_ref: "feature/event-types-p2"
  author: "Lance Sandino"
  timestamp: "2026-10-31T09:00:00Z"
- id: CU-20261031-13
  type: feature
  title: "Organizer → Events: list events with opted-in and public counts, prepare drafts, preview, put a draft on the map (archiving the current event in one locked transaction), end an event, export one event's listings, delete an archived event's listings"
  description: "EventsAdminApiController (ROLE_ADMIN, CSRF): GET /api/admin/events, POST /api/admin/events (a draft of a type this version offers; Event/OccurrenceInput validates name, days — one for a one-day type, at most 62 —, default hours and the shared settings rules, route_modes and max_days; unknown fields refused), PUT /api/admin/events/{id} (a draft or the active event; the type never changes; moving the active event while homes are listed needs move_listings and moves a one-day event's windows with its day, mirrored to the legacy rows), POST …/activate {confirm, replaces} (Event/EventLifecycle: the occurrences advisory lock, the draft FOR UPDATE, the event it replaces must still be the active one — otherwise 409 changed —, the current one archived and its day's legacy rows unpublished, the draft activated, the settings mirror written; a unique violation is a 409 too), POST …/archive {confirm} (any type, including one this version does not offer), POST …/export (a CSRF-checked form POST; Event/EventListingExport, no owner emails, formula cells quoted), POST …/purge-listings {confirm: DELETE} (archived events only; their legacy rows go too unless the active event shares the day). Every change is audited (event.created/updated/activated/archived/exported/listings_purged). The console page /admin/events (admin/events.js) and an organizer-only draft preview /admin/events/{id}/preview (the public page with the draft's type, words and dates, no homes, no map scripts, no-store, noindex). The overview names the event on the map; Community → Event explains that the next season is a new event. Community → Event saves still write the active event (name, hours; dates only while nobody is listed)."
  impact: high
  affected_modules: ["src/Event/EventLifecycle.php", "src/Event/OccurrenceInput.php", "src/Event/EventListingExport.php", "src/Event/EventConflictException.php", "src/Controller/Api/EventsAdminApiController.php", "src/Controller/AdminPageController.php", "templates/admin/", "public/assets/js/admin/events.js", "public/assets/js/admin/overview.js", "public/assets/js/admin/community.js", "public/assets/css/admin.css"]
  related_tests: ["tests/Integration/EventsConsoleTest.php: testAnOrganizerPreparesPreviewsAndSwitchesToTheNextEventWithoutCarryingAnythingOver, testEndingTheEventLeavesANeutralMapAndRetentionDeletesItsListings, testTheActiveEventMovesOnlyWithItsHomesAndTheCommunityFormIsAViewOfIt, testAnEventOfATypeThisVersionDoesNotOfferBreaksNothingAndCanBeArchived, testOnlyOrganizersReachEventsAndThePreview", "tests/Integration/EventActivationRaceTest.php (forked processes: four drafts at once → one winner; the same draft three times → one activation; the index refuses a second active row)", "tests/Integration/EventBootstrapTest.php"]
  commit_ref: "feature/event-types-p2"
  author: "Lance Sandino"
  timestamp: "2026-10-31T11:00:00Z"
- id: CU-20261031-14
  type: feature
  title: "My house: each claimed home shows its listing for the event on the map, or \"List for <event>\" with an empty form; claim requests belong to their event"
  description: "GET /api/me names the event (id, name, type, active, dates, default hours) and each home's can_list; a home's listing is only the active event's. POST /api/me/homes/{id}/listing lists an owned home for the active event from the submitted fields only (ResidentHomes::create; the address's hold applies; 409 already_listed; closed between events or when listings are closed); PATCH of a home not listed for the event answers 404 not_listed instead of reviving last season's listing. me.js hides the status buttons and the form of such a home and offers \"List for <event>\": the form opens with the event's default hours and nothing else filled in. The client never sends an event id. A claim intent records the event it was made for and is refused if another event is active when it is confirmed."
  impact: medium
  affected_modules: ["src/Controller/Api/MeApiController.php", "src/Listing/ResidentHomes.php", "src/Claim/ClaimService.php", "templates/me/index.html.twig", "public/assets/js/me.js", "public/assets/css/app.css"]
  related_tests: ["tests/Integration/EventsConsoleTest.php: testAnOrganizerPreparesPreviewsAndSwitchesToTheNextEventWithoutCarryingAnythingOver (listing null, can_list, PATCH 404, List for starts empty, 409 twice, last season kept apart)", "tests/e2e/specs/season.spec.mjs"]
  commit_ref: "feature/event-types-p2"
  author: "Lance Sandino"
  timestamp: "2026-10-31T11:00:00Z"
- id: CU-20261031-15
  type: feature
  title: "Saved homes, filters and routes are kept per event (nhm:<community>:<event id>); the 0.10 year store moves in once; shared links carry e=<event id> and a link for another event drops its stops"
  description: "lib/store.js migrateYearStore moves nhm:<community>:<year> into the active Halloween event's store once and removes it (the Halloween module sets migratesYearStore), so current visitors keep their saved homes and named routes and no later event inherits them. home.js opens the store by event id. route-panel.js puts e=<event id> on every shared link; lib/share.js otherEvent recognizes a link for another event (or a damaged value), and home.js then opens the map without that link's stops or filters and says so; links made before 0.11 (no e) open as before."
  impact: low
  affected_modules: ["public/assets/js/lib/store.js", "public/assets/js/lib/share.js", "public/assets/js/home.js", "public/assets/js/route-panel.js", "public/assets/js/events/halloween.js"]
  related_tests: ["tests/js/event-storage.test.mjs", "tests/js/lib.test.mjs (unchanged)", "tests/e2e/specs/season.spec.mjs: a link for the archived event shows the notice and no stops", "tests/e2e/specs/public.spec.mjs: the store key names the event"]
  commit_ref: "feature/event-types-p2"
  author: "Lance Sandino"
  timestamp: "2026-10-31T11:00:00Z"
- id: CU-20261031-16
  type: chore
  title: "Rollback drill for event-scoped listings (bin/drill-event-upgrade.sh), bin/restore.sh replaces the whole schema in one transaction, the event-types migration runbook"
  description: "bin/drill-event-upgrade.sh runs on a disposable Compose project (port 8198, Mailpit 8197, always down -v): the published 0.9.0-beta.1 images with the fictional demo, a backup (bin/backup-now.sh), the upgrade to the given images with the startup migrations, the same /api/homes, a resident edit and a new self-entered home on the new release (scripts/drill_listing.py: real proof-of-work, link from Mailpit, a pin inside the boundary away from every home), path (a) image rollback to 0.9.0-beta.1 with the database kept (starts, reports the newer migrations, shows the bridged homes; a resident edits on 0.9), forward again (the 0.9 edit reached the event tables), path (b) the pre-upgrade backup restored with bin/restore.sh on 0.9.0-beta.1 (exactly the pre-upgrade homes, no event tables) and a second upgrade of the restored database. bin/restore.sh used pg_restore --clean, which drops only the objects in the backup: it cannot drop claims while event_listings references it, and tables, functions and triggers added later would stay; it now replaces the schema in one transaction (D-20261031-15). docs/runbooks/event-types-migration.md explains what the migration changes, how to check it, both rollback paths with their exact commands and limits, and the drill; the release, upgrade and backup runbooks link it. bin/smoke-prod-image.sh now bootstraps the fictional demo community (no homes) and requires the home page to be the map page (home.js), because an install without an active event shows the neutral page and would have left the map page's assets unchecked. The end-to-end suite gains a `season` project that runs after all others (tests/e2e/specs/season.spec.mjs: the Events console, the draft preview, the switch, last season's homes gone, a shared link for the ended event, My house \"List for\" from an empty form); bin/e2e.sh lists the fictional demo homes again for the active event before and after each run."
  impact: medium
  affected_modules: ["bin/drill-event-upgrade.sh", "scripts/drill_listing.py", "bin/restore.sh", "bin/e2e.sh", "bin/smoke-prod-image.sh", "tests/e2e/", "docs/runbooks/"]
  related_tests: ["bin/drill-event-upgrade.sh nhm-app nhm-nginx smoke: all 8 steps passed (docs/quality/test-report.md)", "tests/Unit/Deploy/RollbackDrillTest.php", "scripts/tests/test_drill_listing.py", "scripts/tests/test_operator_scripts.py (restore refusal paths unchanged)", "bin/smoke-prod-image.sh: 7/7 with the map page", "tests/e2e/specs/season.spec.mjs"]
  commit_ref: "feature/event-types-p2"
  author: "Lance Sandino"
  timestamp: "2026-10-31T13:00:00Z"
```
