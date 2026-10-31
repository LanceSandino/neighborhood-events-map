# Test and verification report

## Latest run — 2026-10-31, release 0.11.0-beta.1

Event-scoped listings (ROADMAP Stages 2 and 5; CU-20261031-08 to -17), on PHP 8.5.12 + PostgreSQL 18.6, Symfony 8.1.10.
The bar was data integrity and the rollback path first: the backfill lossless on a seeded 0.10 database, the legacy
table equal to the active Halloween event in both directions, and both rollback paths run for real.

| Gate | Result |
|---|---|
| Baseline at 0.10.0-beta.1 (branch start, dev stacks) | PHPUnit **OK — 308 tests, 10970 assertions** |
| PHPUnit 13.4.5 | **OK — 385 tests, 13332 assertions** (77 new; the migration, bridge, display-status, privacy-widening and console tests were run red before the code) |
| Coverage (pcov) | Lines **94.16 %** ✓ ≥ 85 · Methods 81.07 % · Branches n/a · ScheduleWindows, DisplayStatus, ResidentHomes 100 % lines, ListingRepository 99 %, EventLifecycle 91 %, OccurrenceInput 78 %, EventBlockSchema 69 % (the several-days branches wait for the types that use them) |
| Migration fidelity (`EventOccurrencesMigrationTest`) | seeded 0.10 database (9 listings over 4 dates: hidden, pending review, unpublished, out of boundary, a hold, two past seasons, a future date): 4 occurrences (2 archived, 1 active, 1 draft), every row and window copied field for field, `/api/homes` equal to 0.10's frozen query; down drops only the new tables and leaves the legacy rows identical; up again gives the same result |
| Bridge (`LegacyBridgeTest`) | legacy table = active event after claim, edit, status, visibility, pending review, hide/unhide, release and erasure; 0.9-style inserts/updates/moves/deletes reach the event tables; guarded app writes are not copied back |
| Display status | 42 shared cases (DST in New York and Chicago, New Year's Eve, month ends, day-scoped statuses, before/inside/after) green in PHPUnit and node:test; 2,000 seeded homes/moments: Halloween's rules = `statusOf` |
| Activation race (`EventActivationRaceTest`, forked processes) | 4 drafts at once → 1 activated, 3 refused (`changed`); the same draft 3× → 1 activation, 1 audit event; a second active row refused by the unique index |
| PHPStan level 6 · `composer audit --locked` · `lint:twig` · `lint:yaml` · `lint:container` | No errors · no advisories · 38 files OK · 15 files OK · OK |
| Migrations up / down to zero / up (test database) | OK |
| node:test on Node 24 · Python 3.14 + shapely 2.2.0 | **44/44** · **47/47** |
| Playwright E2E on both dev stacks (chromium, webkit-iphone, then `season`) | **81 passed, 2 skipped** on the final tree (the new season test: Events console, draft preview, switch, last season gone, a shared link for the ended event, My house "List for" from an empty form; the year-store move on both engines) |
| Session smoke test | **13/13** |
| `bin/smoke-prod-image.sh` on the built prod and nginx images | **7/7 passed** (0.11.0-beta.1; the home page is now the demo's map page, 6 versioned assets) |
| `bin/drill-event-upgrade.sh nhm-app nhm-nginx smoke` | **all 8 steps passed** (1 min 52 s): published 0.9.0-beta.1 with 14 fictional homes → backup → upgrade (startup migrations; same 14 homes, keys and values; 1 occurrence, 14 event listings) → edit + new self-entered home on 0.11 → images back to 0.9.0-beta.1 ("You have 2 previously executed migrations…", "Already at the latest version"; same 15 homes incl. the edit and the new home) → edit on 0.9 → forward again (the 0.9 edit is in event_listings) → 0.9 + `bin/restore.sh` of the pre-upgrade dump (exactly the 14 pre-upgrade homes, no event tables, 3 migrations recorded) → upgraded again (same 14 homes) |
| Trivy | not run locally (CI scans the images) |

## Run — 2026-10-31, release 0.10.0-beta.1

Event types, Halloween first (ROADMAP Stage 1; CU-20261031-01 to -06), on PHP 8.5.12 + PostgreSQL 18.6, Symfony 8.1.10.
The acceptance bar was Halloween parity with 0.9.0-beta.1, measured against a baseline taken first on fresh dev stacks.

| Gate | Result |
|---|---|
| Baseline at 0.9.0-beta.1 (fresh stacks, before any change) | PHPUnit 280 passing (the new README test was the 281st, red) · lines 93.96 % · PHPStan and `composer audit` clean · node:test 30/30 · Python 45/45 · Playwright **78 passed, 2 skipped** · session smoke 13/13 |
| PHPUnit 13.4.5 | **OK — 308 tests, 10970 assertions** (27 new; 20 of them — registry, Halloween type, parity, migration, schema and golden tests — were run red before the code) |
| Coverage (pcov) | Lines **94.79 %** ✓ ≥ 85 · Methods 81.64 % · Branches n/a · new `src/Event` classes and ListingDraft/ListingRepository/ResidentHomes 100 % lines |
| Golden API parity (`GoldenApiParityTest`, and live on both rebuilt stacks) | `/api/event`, `/api/community`, `/api/homes`, `/api/homes/{id}` of the demo and Ventura stacks: every 0.9 key with the same value in the same order; the only new key is `event.type` |
| Normalization parity | 600 seeded listing inputs: same drafts and same field errors as the frozen 0.9 ListingDraft · 400 seeded homes: same filters, statuses, symbols, tags, pins, cards and panels as the frozen 0.9 browser libraries |
| Server-rendered pages (7 per stack) | identical to 0.9 apart from whitespace between flex items and `event.type` in the page data |
| E2E screenshots, tiles blocked, fresh stacks | **16 of 17 pixel-identical** to the 0.9 baseline; `ventura-admin-overview` differs only in the geometry version's creation time (the stack was created later) |
| PHPStan level 6 · `composer audit --locked` · `lint:twig` · `lint:yaml` · `lint:container` | No errors · no advisories · 35 files OK · 15 files OK · OK |
| Migrations up / down to zero / up (test database) | OK; the migration test also goes down and up with listings in place and backfills every row |
| 0.9.0-beta.1 image against the migrated database | start-up migration step warns about one newer executed migration, "Already at the latest version", exit 0; `app:db:ping` ok |
| node:test on Node 24 · Python 3.14 + shapely 2.2.0 | **37/37** · **45/45** |
| Playwright E2E on both dev stacks (chromium + webkit-iphone) | **78 passed, 2 skipped** on the new code before the version bump (fresh stacks) and again on the final tree |
| Session smoke test | **13/13** |
| `bin/smoke-prod-image.sh` on the built prod and nginx images | **7/7 passed** (0.10.0-beta.1) |
| Trivy | not run locally (CI scans the images) |

## Run — 2026-10-30, release 0.9.0-beta.1

Symfony 8.1 (CU-20261030-03), on PHP 8.5.12 + PostgreSQL 18.6:

| Gate | Result |
|---|---|
| PHPUnit 13.4.5 on Symfony 8.1.10 | **OK — 280 tests** (the new `FrameworkPolicyTest` fails on the 7.4 composer.json) |
| Coverage (pcov) | Lines **94.35 %** ✓ ≥ 85 · Methods 81.39 % · Branches n/a |
| PHPStan level 6 · `composer audit --locked` · migrations up/down/up | No errors · no advisories · OK |
| `lint:container` · `lint:yaml config` · `lint:twig` · dev/prod cache warmup · container deprecations | OK · OK · 28 files OK · OK · none |
| node:test on Node 24 · Python 3.14 + shapely 2.2.0 | **30/30** · **45/45** |
| Playwright E2E on both dev stacks (chromium + webkit-iphone) | **78 passed, 2 skipped**; no deprecation or error in the app logs |
| Session smoke test | **13/13** |
| `bin/smoke-prod-image.sh` on the built prod and nginx images | **7/7 passed** (Kernel 8.1.10) |
| Trivy 0.76.1 on the built images (fixed HIGH/CRITICAL) | **0** |

## Run — 2026-10-30, release 0.8.0-beta.4

Security release (CU-20261030-01): curl 8.23.0, Symfony 7.4.22, PHPUnit 13.4.5, current digests.

| Gate | Result |
|---|---|
| Trivy 0.76.1 on the built prod and nginx images (fixed HIGH/CRITICAL) | 1 HIGH (curl/libcurl 8.22.0-r0, CVE-2026-92392) in the published images · **0 after** |
| `bin/smoke-prod-image.sh` on the built prod and nginx images | **7/7 passed** |
| PHPUnit 13.4.5 on PHP 8.5.12 + PostgreSQL 18.6 | **OK — 278 tests, 5411 assertions** (the new scan assertion fails on the 0.8.0-beta.3 workflows) |
| Coverage (pcov) | Lines **94.39 %** ✓ ≥ 85 · Methods 81.58 % · Branches n/a |
| PHPStan level 6 · `composer audit --locked` · migrations up/down/up | No errors · no advisories · OK |
| node:test on Node 24 | **30/30** |
| Playwright compact-list test pinned to the event day (chromium + webkit-iphone) | **2/2 passed** (fails before CU-20261030-02: rows 79.2 / 80.2 px, limit < 70) |

## Run — 2026-10-24, release 0.8.0-beta.3

Fix for the 0.8.0-beta.2 production outage (CU-20261024-05/-06):

| Gate | Result |
|---|---|
| `bin/smoke-prod-image.sh` on the built prod and nginx images | **7/7 passed** (on the published 0.8.0-beta.2 images it fails: nginx unhealthy, /healthz 500) |
| PHPUnit 13.4 on PHP 8.5.12 + PostgreSQL 18.6 | **OK — 278 tests, 5411 assertions** (the two new `ContainerConfigTest` tests fail on the 0.8.0-beta.2 config) |
| PHPStan level 6 · `composer audit --locked` | No errors · no advisories |
| Playwright E2E (chromium + webkit-iphone) | **78 passed, 2 skipped** |
| Session smoke test | **13/13** |

## Run — 2026-10-24, release 0.8.0-beta.2 (withdrawn: answered 500 in production, see CU-20261024-05)

Security release (CU-20261024-01 to -04) on both development stacks, rebuilt on the new base digests:

| Gate | Result |
|---|---|
| PHPUnit 13.4 on PHP 8.5.12 + PostgreSQL 18.6 | **OK — 276 tests, 5398 assertions** (`ContainerConfigTest` 14/14; 6 of its tests fail on the 0.8.0-beta.1 config) |
| Coverage (pcov) | Lines **94.03 %** ✓ ≥ 85 · Methods 79.95 % · Branches n/a |
| PHPStan level 6 · `composer audit --locked` · migrations up/down/up | No errors · no advisories · OK |
| node:test on Node 24.22.1 | **30/30** (the id-escaping test fails before the fix) |
| Python 3.14 + shapely 2.2.0 | **45/45** |
| Playwright E2E (chromium + webkit-iphone, Mailpit 1.32.0) | **78 passed, 2 skipped** (a clean run on the final tree; the logo upload goes through the upload pool) |
| Session smoke test | **13/13** |
| Trivy 0.76.1 on the built prod and nginx images (fixed HIGH/CRITICAL) | 1 HIGH each before (brotli-libs 1.2.0-r1, CVE-2026-111497) · **0 after** |
| Live checks on the dev stack | multipart body to `/`, `/api/auth/request-link`, `/api/me/homes`, `/api/admin/community` → **415**; logo upload and removal through the upload pool → 200; `php-fpm -tt` shows file_uploads off on www and mail, allow_url_fopen off and the disabled functions on all three pools |

## Run — 2026-10-21, release 0.8.0-beta.1

Latest stable stack (CU-20261021-52 to -54) on the development stacks, both moved to PostgreSQL 18 with `bin/upgrade-postgres.sh`
(17.11 → 18.6, 15 tables each, identical row counts):

| Gate | Result |
|---|---|
| PHPUnit 12.5 on PHP 8.5.12 + PostgreSQL 18.6 | **OK — 273 tests, 5162 assertions** |
| Coverage (pcov) | Lines **94.08 %** ✓ ≥ 85 · Branches n/a |
| PHPStan level 6 · `composer audit --locked` · `composer validate` | No errors · no advisories · valid |
| node:test on Node 24 | **29/29** |
| Python 3.14 + shapely 2.2.0 (importers, operator scripts incl. the upgrade script's refusals) | **45/45** |
| Playwright E2E (chromium + webkit-iphone, Mailpit 1.31.6) | **78 passed, 2 skipped** (a clean run with no edits during it) |

## Run — 2026-10-21, release 0.7.0-beta.6

PHP 8.3.36 security release (CU-20261021-51), dev stacks rebuilt on the new base image: PHPUnit **272 tests, 5153 assertions** OK;
PHPStan no errors; `composer audit --locked` no advisories; session smoke **13/13**.

## Run — 2026-10-21, release 0.7.0-beta.5

0.7.0-beta.4 plus the privacy notice's cookie section and `CLOUDFLARE_PROXY` (CU-20261021-50): PHPUnit **271 tests, 5150 assertions**
OK; PHPStan no errors. With `CLOUDFLARE_PROXY=1` and `CLOUDFLARE_WEB_ANALYTICS=1` on the demo stack: no horizontal overflow on the
privacy page at 430 px, axe checks 20/20.

## Run — 2026-10-21, release 0.7.0-beta.4

0.7.0-beta.3 plus optional Cloudflare Web Analytics (CU-20261021-49): PHPUnit **269 tests, 5130 assertions** OK; PHPStan no errors.
With `CLOUDFLARE_WEB_ANALYTICS=1` on the demo stack: the policy's `script-src` adds only `https://static.cloudflareinsights.com`, the
privacy page and footer disclose it (no horizontal overflow at 375 and 1440 px, light and dark), and the axe checks pass (20/20).

## Run — 2026-10-21, release 0.7.0-beta.3

0.7.0-beta.2 plus the nginx asset fix (CU-20261021-48). The development overlay now mounts the checkout's nginx config, so these
runs exercise it: Playwright **78 passed, 2 skipped** (chromium + webkit-iphone); `ContainerConfigTest` 8/8 (the new numbered-capture
test fails on the 0.7.0-beta.2 config). The other gates are unchanged from 0.7.0-beta.2 below.

## Run — 2026-10-21, release 0.7.0-beta.2

**Note:** these browser runs used nginx images built before the release's nginx change, so they missed the asset regression fixed
in 0.7.0-beta.3.

Gates on `fix/hardening-notes` (0.7.0-beta.1 + the closed hardening notes), dev stacks of that tree:

| Gate | Result |
|---|---|
| PHPUnit (unit + integration, PostgreSQL 17) | **OK — 266 tests, 5116 assertions** |
| Coverage (pcov) | Lines **94.08 %** (2607/2771) ✓ ≥ 85 · Methods 79.95 % (only fully covered methods count) · Branches **n/a** (pcov has no branch metric) |
| PHPStan level 6 · `composer audit --locked` | No errors · no advisories |
| Migrations | `Version20261021000001` down and up again on the test database |
| node:test | **29/29 pass** |
| Python (GIS importers, operator scripts) | **42/42 pass** |
| Playwright E2E (chromium + webkit-iphone) | **78 passed, 2 skipped** (location tests need HTTPS in WebKit); the layout tests at six widths re-run after the legend fix: 34/34 |
| Session smoke test (simulated HTTPS proxy) | **13/13 checks**, cookie `__Host-nhm_session` |
| nginx `$client_net` in real nginx 1.31 | 771 IPv6 addresses (every zero-group shape) → their /64, IPv4 and IPv4-mapped → the IPv4 address |
| Backup and restore drill | Real backups in `postgres:17-alpine`; backup → delete one account, revoke one organizer → `bin/restore.sh` → both stay deleted/revoked |

## Run — 2026-10-21, release 0.7.0-beta.1

Final gates on the release branch (0.6.0-beta.2 + map fixes + public-readiness work), dev stacks of that tree:
PHPUnit 254 tests / 1904 assertions OK; PHPStan level 6 no errors; node:test 29/29; Playwright 76 passed, 2 skipped
(WebKit location tests need HTTPS) on Chromium and WebKit iPhone 15 Pro. The sections below are the public-readiness
pass's detailed run on its branch just before the rebase.

### Earlier run — 2026-10-21, `chore/public-ready` (0.6.0-beta.2 + the zone-label fix + Unreleased)

On the development Mac (Docker Desktop), with two dev stacks: the Ventura Reserve example and the fictional demo. The commands are the ones in AGENTS.md §5; only the ports differ. Details and the public-readiness checks are in [public-readiness-audit.md](public-readiness-audit.md).

| Gate | Result |
|---|---|
| PHPUnit (unit + integration, PostgreSQL 17) | **OK — 252 tests, 1887 assertions** |
| Coverage (pcov) | Lines **94.28 %** (2472/2622) ✓ ≥ 85 · Methods 81.14 % (only fully covered methods count) · Branches **n/a** (pcov has no branch metric) |
| PHPStan level 6 | **No errors** |
| `composer audit --locked` · `npm audit --package-lock-only` (tests/e2e) | No advisories · 0 vulnerabilities |
| node:test | **27/27 pass** |
| Python (GIS importers, operator scripts) | **36/36 pass** |
| Playwright E2E (chromium + webkit-iphone) | **76 passed, 2 skipped** (location tests need HTTPS in WebKit) |
| Session smoke test | **12/12 checks** |

## Earlier run — 0.4.0-beta.2

Recorded 2026-10-19 for 0.4.0-beta.2 (after the security hardening) on the development Mac (Docker Desktop). Commands are the ones in AGENTS.md §5; results are the actual outputs. The E2E stacks were the Ventura Reserve real-plat example (port 8087) and the fictional Maple Hollow demo (port 8088).

| Gate | Command | Result |
|---|---|---|
| PHPUnit (unit + integration on PostgreSQL 17) | `docker compose exec app sh bin/test.sh` | **OK — 177 tests, 1319 assertions** |
| Coverage (pcov) | `php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text` | Lines **93.13 %** (2222/2386) ✓ ≥ 85 · Methods 77.24 % (PHPUnit counts only *fully* covered methods; below the 85 % target) · Branches **n/a** (pcov has no branch metric) |
| Static analysis | `vendor/bin/phpstan analyse` (level 6) | **No errors** |
| Dependency audit | `composer audit --locked` | No advisories |
| Browser library unit tests | `node --test tests/js/*.test.mjs` | **10/10 pass** |
| GIS importer unit tests | `python -m unittest discover -s scripts/tests` | **26/26 pass** (no network) |
| Importer equivalence | generic importers with the first deployment's private config vs. the original scripts' output | All 11 GeoJSON files and the address CSV byte-identical (SHA-256) |
| Real-plat import | `fetch_arcgis_boundaries.py` / `fetch_arcgis_addresses.py --config examples/ventura-reserve/gis-sources.json` | 3 valid plats, no overlaps, 1 contiguous part; 152 address points (private) imported with `app:addresses:import` (57/42/53 per phase) |
| Playwright E2E (Ventura Reserve + demo stacks, new nginx limits) | `./bin/e2e.sh` | **32/32 pass** (incl. 18 axe WCAG 2.1 AA checks in light and dark, dark-appearance and check-chip layout tests) |
| Session smoke test | `python3 scripts/smoke_session.py` | **12/12 checks** (Secure/HttpOnly/SameSite cookie, restart durability, logout revocation, link reuse) |
| Migrations | up → down to zero → up (test DB) | down leaves only the migrations table; up restores everything including `moderation_hold` and `idx_pow_client_created` |
| Production-mode stack | `compose.yaml` only, port 8089 | All services healthy (app, nginx, postgres, backup, maintenance); read-only roots, `cap_drop: ALL`, no-new-privileges, memory/CPU/PID limits; PHP as `www-data`, nginx as uid 101; image without `.env.*` secrets/tests/docs; foreign `Host` → 400; burst → 429; 1.5 MB body → 413; FPM `request_terminate_timeout = 35s`; purge logged hourly; backup `0600` in `0700`, no session rows; test-like `APP_SECRET` / dev DB password → exit 64; forwarded headers honoured from the host gateway, ignored from a container on the same network |
| Backup/restore drill | `bin/backup-now.sh`, delete volume, `bin/restore.sh` | Identical counts (0.4.0-beta.1; restore path unchanged, dumps now exclude session rows) |
| Token-in-logs check | grep app + nginx logs after confirm requests | 0 occurrences (0.4.0-beta.1); emails now masked in every log line (unit-tested) |
| Module structure | module-structure fitness check (external tool; `vendor`, `var`, `node_modules`, fonts excluded) | **87.5 / 100 PASS**; code_root 71.4 (deviation D-20261018-13), testability 13.4 (deviation D-20261018-14), headers 95.2, module size 100, cycles 0 |

Security audit and hardening: [docs/security/hardening-report.md](../security/hardening-report.md).

## What the suites cover (by acceptance item)

- Auth/claims (#3, #6, #7, #9): GET never consumes, POST consumes once, 15-minute expiry, replay 410, CSRF on confirm, neutral responses, session rotation, immediate publication, already-claimed conflict without owner identity.
- Race (#8): 8 forked processes with separate DB connections → exactly one owner and one listing; E2E second claimant gets "already claimed".
- PoW/throttles (#4, #5): missing/malformed/expired/replayed/wrong-purpose/stolen/locked challenges; per-email, per-IP (persistent across kernels), per-address, PoW-issuance throttles with Retry-After; adaptive difficulty.
- Sessions (#21, lifecycle doc): 29:59 works and resets, 30:00 → 401 session_expired, passive probe and background requests do not extend, community-configured 20 minutes respected, logout revokes server session (stolen cookie fails), expired admin action makes no writes, fresh session after expiry.
- Owner/admin (#10–#12): own home only, CSRF/Origin enforced, resident blocked from organizer API/pages, transfer/hide/resolve audited with old/new user ids, organizer role not self-assignable, CSV export.
- Geography (#13, #16, #17, #18): Polygon/MultiPolygon/holes/edges/overlaps, malformed/unclosed/out-of-range/self-intersecting/hole-outside/`[lat,lon]` swap rejected, two disconnected areas, exact export→import→export, versions, approval gate, boundary shrink confirmation, non-claimable and unknown zones, real Orange County plats (Ventura Reserve: three phases, no overlaps, pending approval, eligibility after approval), generic no-zone community.
- Abuse and least privilege (hardening report): failed requests spend no mail budget, organizer exemption, alias folding, IPv6 /64 keys, honeypots, response-time floor (and no floor without proof-of-work), link retirement, review-request validation before budgets and global cap, registry closed while listings are closed, per-resident home limit under a lock (dashboard and email link), moderation that survives release/re-claim, confirmation page showing the listing, roles declared on controllers, organizers from configuration, owner-scoped `ResidentHomes` refusing another resident's home, 12-hour absolute session lifetime, email masking in logs, logo type checks.
- Public privacy (#2): only opted-in, current-season, unmoderated, in-boundary listings; field allow-list; CSP/headers.
- Frontend (#14, #15): filters drive list and pins together and persist after reload, favorites, keyboard selection, route sheet with separate trips, readable list when Leaflet fails, no horizontal overflow and ≥ 40 px targets at 375/430/768/1440, reduced motion.

## Not verified here (needs real-world inputs)

Real SMTP deliverability, the production hostname/tunnel, iOS Safari on a physical phone, VoiceOver, and organizer approval of geography. Mutation testing (Infection) was not run in this pass.
