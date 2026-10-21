# Security audit and hardening report — 0.4.0-beta.2

Date: 2026-10-19. Scope: the whole application and its container stack (PHP/Symfony app, nginx, PostgreSQL, backups, Compose), before the repository is made public.

**Method.** Three independent adversarial reviews ran against the code and two running local stacks: (1) authentication, sign-in links, sessions and authorization; (2) OWASP input/output handling; (3) bots, abuse resistance, availability and infrastructure. Every finding needed evidence: a reproduced request, or an unambiguous code or configuration path. Each finding was then fixed test-first and verified, or explicitly accepted. The owner's checklist was audited explicitly: magic-link rate limits, an identical "check your email" reply, timing, bots, link sanitisation, and the admin/role model.

## Owner's checklist

| Item | Result |
|---|---|
| Rate-limit magic links | Proof-of-work plus persistent PostgreSQL limits per IP (IPv6 keyed by /64), per mailbox (aliases folded), per address and globally; `429` with `Retry-After`. Budgets are now spent only by mail that is actually sent; organizers are exempt from the global cap. |
| Same "check your email" reply whether or not an account exists | Verified byte-identical (status, headers, body). The endpoint never looks up accounts: every valid address gets a one-time link, and the account is created only when the link is confirmed. |
| Delay so timing reveals nothing | Measured before the change: existing 121.0 ms vs. absent 121.4 ms median (no significant difference; the same code path runs). Added anyway: every response after a solved proof-of-work takes at least `AUTH_RESPONSE_FLOOR_MS` (700 ms) plus up to 250 ms random jitter, which also hides mail-relay latency. Requests without proof-of-work are never held, so the wait cannot be used to tie up workers cheaply. |
| Keep bots out | Honeypot field on every form (filled → identical success reply, nothing done), nginx per-IP request and connection limits ahead of PHP, proof-of-work, per-resident claim cap and throttle, global caps for sign-in mail and review requests. |
| Link sanitisation / OWASP | Links are built only from `APP_BASE_URL` (forged `Host`/`X-Forwarded-Host` ignored); 256-bit tokens stored as SHA-256 only; format checked before any lookup; consumed only by a CSRF-protected POST; return paths limited to `/me` and `/admin` (anchored with `\z`). XSS, SQL injection, CSRF/Origin, mass assignment and CSV formula injection were all tested live and held. |
| Admin/role model | `ROLE_USER` (resident) may only manage their own homes; `ROLE_ADMIN` (organizer) may do everything. Enforced in `access_control` **and** declared on the controllers (`#[IsGranted]`). Organizers come only from `app:admin:grant` or `ORGANIZER_EMAILS`; no escalation path was found. Resident data is reached only through the owner-scoped `ResidentHomes`, whose queries always bind `c.user_id = :owner` before the SQL is executed (live cross-resident attempts all failed). |

## Findings and remediation

Severity is from the audit. Status: **Fixed** (changed and verified), **Accepted** (documented residual risk).

| # | Sev | Finding | Status and change | Verification |
|---|---|---|---|---|
| 1 | High | A signed-in resident could claim every home in the registry (and invent self-entry homes) | **Fixed** — per-resident home limit (`registration.max_homes_per_user`, default 2, organizer-editable), enforced under a per-user advisory lock for dashboard and email-link claims; `claim_user` throttle (10/h) | `OwnershipHardeningTest` (limit via dashboard and email link, throttle) |
| 2 | Medium | Releasing and re-claiming a home undid an organizer's "hide" | **Fixed** — moderation is kept on the address (`moderation_hold`, migration `Version20261019000001`); new listings for a held address start hidden | `OwnershipHardeningTest::testHiddenListingStaysHiddenAfterReleaseAndReclaim` |
| 3 | Medium | Private address registry enumerable (sequential `claim-status` ids, lookups while closed, registry text echoed for arbitrary ids) | **Fixed** — `claim-status` endpoint removed; lookups return nothing while listings are closed; the sign-in reply never echoes registry text. *Residual:* while open, a lookup with a known house number and street still says whether that address exists and is claimed (by design; per-IP throttled) | `AbuseHardeningTest` (lookup closed, no echo), `ErrorPathsTest` (endpoint gone) |
| 4 | Medium | Failed claim requests spent the global and per-email link budgets (global lockout) | **Fixed** — eligibility first; only sent mail spends per-email/global budget; organizers exempt from the global cap. *Residual (accepted):* a distributed attacker solving proof-of-work for many sends can still exhaust the global hourly budget for residents — it protects the mail relay and is configurable (`RL_LINK_GLOBAL`) | `AbuseHardeningTest` (failed requests, organizer exemption) |
| 5 | Medium | IPv6 rotation inside one /64 multiplied every per-IP budget | **Fixed** — IPv6 clients keyed by /64 for limits and proof-of-work binding | `ClientIdentityTest` |
| 6 | Medium | Invalid review requests spent a home's daily budget; no global cap; unverified reply-to | **Fixed** — validation before per-home/global budgets; `dispute_global` 60/day; organizer email marks the reporter address UNVERIFIED | `AbuseHardeningTest` (three review-request tests) |
| 7 | Medium | Maintenance purge never scheduled; tables grew without bound; no index for the proof-of-work count | **Fixed** — hourly `maintenance` service; purge now also removes expired sessions and stale route-cache rows; index `(client_hash, created_at)` | `CommandsTest::testSeasonArchiveAndPurgeAndPing`; production stack log `Purged: …` |
| 8 | Medium | `.env.demo` (local secrets) and test caches were baked into the production image | **Fixed** — `.dockerignore` excludes `.env.*` (except documented templates), tests, docs, scripts and caches. The affected image was local-only and never pushed | Image listing on the production stack: all absent |
| 9 | Medium | nginx trusted `X-Forwarded-For` from all private ranges and `X-Forwarded-Proto` from anyone | **Fixed** — only loopback and the container's own gateway (the published `127.0.0.1` port, where the tunnel connects) plus `NGINX_TRUSTED_PROXIES`, generated at start; `X-Forwarded-Proto` honoured only from those | Production stack: host proxy headers honoured; a container on the same network spoofing them is logged at its real address, no HSTS |
| 10 | Low | Mailbox aliases (`+tag`, Gmail dots) had separate budgets; IP-literal domains accepted | **Fixed** | `EmailNormalizerTest`, `AbuseHardeningTest::testAliasesOfOneMailboxShareOneBudget` |
| 11 | Low | Someone else could author the listing that a neighbor then publishes by confirming | **Fixed** — the confirmation page shows the full listing (hours, offerings, note) before publishing | `OwnershipHardeningTest::testConfirmationPageShowsTheListingThatWillBePublished` |
| 12 | Low | No role declaration in code; ownership checks hand-written per query | **Fixed** — `#[IsGranted]` on organizer and resident controllers; owner-scoped `ResidentHomes`; optional `ORGANIZER_EMAILS` | `OwnershipHardeningTest` (roles in code, organizer list, `ResidentHomes` cannot reach another resident's home) |
| 13 | Low | Raw emails reached logs through the framework's security channel | **Fixed** — log processor masks addresses everywhere, including logged objects | `LogRedactionProcessorTest` |
| 14 | Low | No absolute session lifetime; several sign-in links valid at once | **Fixed** — sessions end 12 h after sign-in (`AUTH_SESSION_MAX_HOURS`); using one link retires the others. *Residual (closed in 0.7.0-beta.2):* no "sign out everywhere" button | `SessionIdleTest::testSessionsEndAfterTheAbsoluteLifetimeEvenWhenActive`, `AbuseHardeningTest::testUsingOneLinkRevokesTheOtherOutstandingLinks` |
| 15 | Low | No container hardening or resource limits | **Fixed** — `no-new-privileges`, `cap_drop: [ALL]`, read-only root filesystems (app, nginx, maintenance), memory/CPU/PID limits; PHP and nginx run as non-root | `docker inspect` on the production stack |
| 16 | Low | Backups world-readable and containing live session ids | **Fixed** — `umask 077`, directory `0700`, session rows excluded | Production stack: `-rw-------`, 0 session data entries in the dump |
| 17 | Low | Worker exhaustion: no FPM request timeout, synchronous mail, 6 MB bodies everywhere, no nginx limits | **Fixed** — `request_terminate_timeout` 35 s, `default_socket_timeout` 10 s, `limit_req`/`limit_conn`, 1 MB bodies (6 MB for organizer uploads), tighter client timeouts | Production stack: burst → `429`, 1.5 MB body → `413`, FPM settings read back |
| 18 | Low | Optional routing provider could hold workers (15 s) | **Fixed/Accepted** — timeout 5 s. *Residual:* no global provider budget (the provider is off by default) | Code |
| 19 | Low | Logo: raster check read only the header (polyglot files stored) | **Fixed** — decoded type must match the extension; embedded markup refused (logos are also served with `nosniff` and a sandbox CSP) | `ErrorPathsTest::testBrandingStoreValidatesRasterImages` |
| 20 | Info | Session/link minutes stored out of range (clamped only at use) | **Fixed** — rejected at save | `CommunitySchemaTest::testCollectsAllErrors` |
| 21 | Info | nginx 1.27 (end of life), mutable image tags | **Fixed/Accepted** — current nginx (1.31); Dependabot for Docker, Composer, Actions and npm. *Residual (closed in 0.7.0-beta.2):* images pinned by tag, not digest | `nginx -v` |
| 22 | Info | Host header not validated; weak secret checks | **Fixed** — production `trusted_hosts` = `APP_BASE_URL` host + loopback; the container refuses test-like secrets and the development database password | Production stack: foreign Host → `400`; entrypoint exits `64` |
| 23 | Info | `429 retry_after` shows recent link activity for an address; a relay that rejects unknown mailboxes would turn into `503` | **Accepted** — neither reveals whether an *account* exists; the response floor hides timing | — |

Not findings (verified controls): stored XSS (escaped in Twig, hex-escaped boot JSON, client `esc()`), strict CSP without `unsafe-inline`, CSRF and Origin on every write, parameterised SQL and escaped `LIKE`, mass-assignment protection, CSV formula-injection guard, JSON size/depth limits, open-redirect protection, no tokens or session ids in logs, session rotation at sign-in, server-side logout, immediate effect of role changes.

## Configuration added

| Variable | Default | Purpose |
|---|---|---|
| `ORGANIZER_EMAILS` | empty | Organizer accounts (comma-separated); they still sign in by email link |
| `AUTH_RESPONSE_FLOOR_MS` / `AUTH_RESPONSE_JITTER_MS` | 700 / 250 | Minimum response time for email-sending endpoints |
| `AUTH_SESSION_MAX_HOURS` | 12 | Absolute session lifetime |
| `RL_CLAIM_PER_USER` / `RL_DISPUTE_GLOBAL` | 10/3600 / 60/86400 | New rate-limit buckets |
| `NGINX_TRUSTED_PROXIES` | empty | Extra reverse-proxy addresses (e.g. a cloudflared container) |
| `PURGE_INTERVAL_SECONDS` | 3600 | Maintenance purge interval |
| `registration.max_homes_per_user` (community settings) | 2 | Homes one verified email can manage |

## Follow-up audit — 0.6.0-beta.1 (2026-10-21)

A second, source-only audit (six phases: recon, coverage-led hunters, verifiers, final critic) produced 19 leads and no confirmed findings, because it ran nothing. Each lead was then checked: with a test that failed on the unchanged code in a local stack, and with read-only checks against the first deployment (public GETs, container listeners, row counts; no writes, no sign-in). All 19 were real in the code, and all are fixed with regression tests (`CHANGES.md` CU-20261021-11 to -19).

| # | Lead | How it was confirmed | Fix | Regression test |
|---|---|---|---|---|
| 1 | Encoded protected paths (`/api/m%65`) skipped the idle timeout, and the session CSRF token check too | Local: idle session still got `200`; a same-origin PATCH without the token changed a listing. Live: encoded paths routed normally | Encoded paths refused (nginx `400`, app `404`) | `RequestPathHardeningTest` |
| 2 | `/_internal_%6Cogout` reached the logout listener (cross-site sign-out) | Live: `302` from the logout listener; local: session ended | Same | `RequestPathHardeningTest` |
| 3 | A home in a hidden zone was readable by id and routable | Local test | `publicHome()` filters hidden zones | `HiddenZonePrivacyTest` |
| 4 | PHP-FPM listened on `[::]:9000` (FastCGI from the host and the Docker networks) | Live: the host connected to port 9000 on both networks | Unix sockets in a volume only app and nginx mount | `ContainerConfigTest`; live checks on the local stacks |
| 5 | A released, unreviewed self-entry kept the earlier claimant's pin | Local test (also a misleading "published" reply under review) | The next resident's own pin | `SelfEntryRepinTest` |
| 6 | `app:user:delete` could not erase someone who only sent a review request | Local test | Erases review requests and pending links without an account | `CommandsTest` |
| 7 | `"x<victim@…>"@attacker…` passed validation and was delivered to the victim | Reproduced with PHP and Symfony Mime | Plain local parts only; explicit `Address` objects | `EmailNormalizerTest` |
| 8 | `--revoke` printed "Revoked" while `ORGANIZER_EMAILS` kept the role | Local test | Fails with an explanation | `CommandsTest` |
| 9 | The response floor could hold every PHP worker (map included) | Design review: one pool of 12, floor ≥ 700 ms | Email endpoints run in their own pool | `ContainerConfigTest` |
| 10 | With the global budget full, organizers got `202` and others `429` | Local test | Same `429` for everyone; the organizer's link is still sent | `AbuseHardeningTest` |
| 11 | Walking sequential registry ids mailed the address text to the caller | Local test; applies to the deployment's imported registry | Requests by id must name the house number | `RegistryIdBindingTest` |
| 12 | Dashboard claims by id returned the address text | Local test | Same | `RegistryIdBindingTest` |
| 13 | Listing saves were unlimited and each wrote an audit row | Local test | `RL_LISTING_PER_USER` (60/h per account) | `AbuseHardeningTest` |
| 14 | Throttle rows were kept 48 h whatever their window | Local test | Purged when they leave their own window; expired challenges at expiry | `ProofOfWorkAndThrottleTest` |
| 15 | nginx limits keyed on the raw `$request_uri` (`/%61pi/…` had no limit) | Live: encoded API paths answered normally | Keys on `$uri`; encoded paths refused | `ContainerConfigTest` |
| 16 | Refusals named hidden zones and confirmed their keys and outlines | Local test | Hidden zones never named; hidden rows look unknown | `HiddenZonePrivacyTest` |
| 17 | A crafted share link broke the map on the visitor's device | Node test | Filters type-checked as own keys | `tests/js/lib.test.mjs` |
| 18 | No global budget for walking-path provider calls | Code (the provider is off on the deployment) | `RL_ROUTE_GLOBAL` (1000/day, cache misses only) | `RoutePlanTest` |
| 19 | The importer's review page built popups from third-party HTML | Python test | Text-node popups, SRI on the CDN files | `ReviewMapTest` |

Also hardened: the production entrypoint refuses `APP_DEBUG`, `trusted_hosts` matches the host literally (the dots matched any character), `.dockerignore` keeps a deployment's private `config/community.yaml` and GeoJSON out of images, nginx no longer writes refused `/auth/confirm?t=…` request lines to its error log, and Mailpit's local inbox can require a password (`MAILPIT_UI_AUTH`).

*Residuals (accepted):* nginx counted IPv6 clients per address while the app counts per /64 (closed in 0.7.0-beta.2); any refusal inside the public boundary marks a point as not listable; a distributed attacker can still fill the email pool, which delays sign-in but no longer the map.

**Input sanitization review (same day).** Every input was checked. Addresses now accept only ASCII letters, digits, spaces and `. , ' - #` and refuse anything else (before, the normalizer silently stripped it: `1<2` became house 12). All free text goes through one cleaner (`Shared/TextInput`: valid UTF-8, NFC, no control or invisible text-reordering characters, length cap). The tile URL template and links are anchored to URL-safe characters, and a claim transfer's `dispute_id` must belong to that home. JSON bodies, ids, enums, times, booleans, emails, tokens, the sign-in return path and uploads were already strict. Output is always escaped as well. Regression tests: `AddressNormalizerTest`, `TextInputTest`, `InputSanitizationTest`.

## Hardening notes closed — 0.7.0-beta.2 (2026-10-21)

The public-readiness audit left eleven hardening notes open (the first in two parts). The owner chose to fix all of them before the repository goes public. Each fix has a regression test that fails on the old code (`CHANGES.md` CU-20261021-35 to -46).

| Note | Fix | Regression test |
|---|---|---|
| nginx limits keyed on the full IPv6 address; the app keys per /64 | nginx keys every limit on `$client_net`: the IPv4 address, or the IPv6 /64 written out from nginx's compressed form (IPv4-mapped as IPv4). Checked in real nginx with 771 addresses covering every zero-group shape | `ContainerConfigTest::testNginxKeysIpv6ClientsByTheirSlash64LikeTheApp` (the map's own patterns against all 256 shapes) |
| `limit_conn 40` above the www pool's 12 workers | One client network keeps at most 6 requests in PHP (pages and API) and 2 on the email endpoints; static files keep 40 | `ContainerConfigTest::testNginxCapsOneClientsRequestsInPhpBelowThePoolSizes` |
| Interrupted backups left `.partial` files that never rotated | The daily loop removes partial files older than an hour; `bin/backup-now.sh` also writes a partial file first, so a cut-off run never leaves a truncated `.dump` | `test_operator_scripts.py` `BackupLoopTests` (one loop pass against a stand-in `pg_dump`); a real backup in the `postgres:17-alpine` image |
| A restore brought back deleted accounts and revoked organizers | Account ledger (ids and times only) written with each deletion and role change; `bin/restore.sh` saves it, restores, merges it back and replays it before nginx starts | `AccountLedgerTest` (3 tests); a real backup → delete + revoke → restore on a development stack |
| The `maintenance` service inherited mail and routing secrets | It gets `MAILER_DSN=null://null` and empty `ROUTING_API_KEY` and `ORGANIZER_EMAILS` | `ContainerConfigTest::testTheMaintenanceContainerGetsNoMailOrRoutingCredentials` |
| Base images pinned by tag | Every `FROM`, `COPY --from` and Compose/CI service image is pinned by its multi-arch digest; Dependabot's docker and docker-compose ecosystems keep them current | `ContainerConfigTest::testBaseImagesArePinnedByDigest` |
| GIS importer accepted `http://` and any response size | https only, redirects included; responses over 64 MB are refused (Content-Length and the bytes actually read) | `TransportHardeningTests` (3 tests) |
| `__Host-` session cookie documented, not default | Over HTTPS the cookie is `__Host-` + `SESSION_COOKIE_NAME` automatically; plain-HTTP installs keep the plain name | `HostCookieStorageFactoryTest` |
| No "sign out everywhere" | My house → **Sign out everywhere** and `app:user:sign-out <email>` end every session that began before | `SignOutEverywhereTest` (2 tests); e2e "Sign out everywhere on My house …" |
| The public payload carried the bounding box of an unapproved boundary | `map.bbox` is null until approval; the map opens on the configured center | `EligibilityTest::testUnreviewedBoundaryClosesClaimsButNotBrowsing`, `CommunityConfigsTest::testVenturaReserveRealPlatExample` |
| GeoJSON self-intersection checks ran before the vertex cap | Positions are counted first; an upload over the cap is refused before any ring is examined | `GeoJsonParserTest::testTheVertexCapIsCheckedBeforeAnyRingIsExamined` |
| The claims CSV export was a GET that wrote an audit event | A CSRF-checked POST from a form button; GET answers 405 | `DisputeAndAdminTest::testOrganizerTransfersHidesResolvesAndEverythingIsAudited`; e2e "organizer downloads the claims list as a CSV …" |
