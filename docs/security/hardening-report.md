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
| 14 | Low | No absolute session lifetime; several sign-in links valid at once | **Fixed** — sessions end 12 h after sign-in (`AUTH_SESSION_MAX_HOURS`); using one link retires the others. *Residual:* no "sign out everywhere" button (logout ends the current session; an organizer can delete an account) | `SessionIdleTest::testSessionsEndAfterTheAbsoluteLifetimeEvenWhenActive`, `AbuseHardeningTest::testUsingOneLinkRevokesTheOtherOutstandingLinks` |
| 15 | Low | No container hardening or resource limits | **Fixed** — `no-new-privileges`, `cap_drop: [ALL]`, read-only root filesystems (app, nginx, maintenance), memory/CPU/PID limits; PHP and nginx run as non-root | `docker inspect` on the production stack |
| 16 | Low | Backups world-readable and containing live session ids | **Fixed** — `umask 077`, directory `0700`, session rows excluded | Production stack: `-rw-------`, 0 session data entries in the dump |
| 17 | Low | Worker exhaustion: no FPM request timeout, synchronous mail, 6 MB bodies everywhere, no nginx limits | **Fixed** — `request_terminate_timeout` 35 s, `default_socket_timeout` 10 s, `limit_req`/`limit_conn`, 1 MB bodies (6 MB for organizer uploads), tighter client timeouts | Production stack: burst → `429`, 1.5 MB body → `413`, FPM settings read back |
| 18 | Low | Optional routing provider could hold workers (15 s) | **Fixed/Accepted** — timeout 5 s. *Residual:* no global provider budget (the provider is off by default) | Code |
| 19 | Low | Logo: raster check read only the header (polyglot files stored) | **Fixed** — decoded type must match the extension; embedded markup refused (logos are also served with `nosniff` and a sandbox CSP) | `ErrorPathsTest::testBrandingStoreValidatesRasterImages` |
| 20 | Info | Session/link minutes stored out of range (clamped only at use) | **Fixed** — rejected at save | `CommunitySchemaTest::testCollectsAllErrors` |
| 21 | Info | nginx 1.27 (end of life), mutable image tags | **Fixed/Accepted** — nginx 1.30 stable; Dependabot for Docker, Composer, Actions and npm. *Residual:* images pinned by tag, not digest | `nginx -v` → 1.30.6 |
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
