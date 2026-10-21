# Test and verification report

## Latest run — 2026-10-21, release 0.7.0-beta.2

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
