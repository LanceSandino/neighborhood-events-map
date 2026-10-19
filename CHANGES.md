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
```
