# AGENTS.md — development protocol for humans and AI coding agents

This file is self-contained: anyone (or any agent such as Codex or Claude Code) can follow it without other private protocols. Change history does **not** belong here — use `CHANGELOG.md` (user-facing) and `CHANGES.md` (engineering change records).

## 1. Before changing anything

1. Read `README.md`, `docs/architecture/overview.md`, the ADRs in `docs/adr/`, `DECISIONS.md`, the latest `CHANGELOG.md` section and the tests near the code you will touch.
2. **Settled decisions are settled.** Do not re-open an active entry in `DECISIONS.md` or an accepted ADR. If new evidence invalidates one, say so explicitly and supersede it with a new entry; never delete history.
3. Restate the goal, the done-condition (what command/behaviour will prove it), what is in and out of scope, and what you are unsure about. Ask only when blocked by a genuinely missing input.

## 2. Non-negotiables

- One community per install; **no neighborhood-specific code**. Names, geometry, zones, branding, dates and limits come from config/DB.
- Stack: PHP 8.3 + Symfony 7.4 LTS, PostgreSQL 17 (DBAL, no ORM), Twig, vanilla JS/CSS (ES modules, no build step), Leaflet, Docker Compose. No React/Vue, Redis, PostGIS, paid hosted auth or mandatory frontend tooling.
- **Never fabricate data:** no invented boundaries, house pins, addresses, crossings or walking paths. Demo data is fictional, labelled, gated behind `APP_DEMO=1` and a `*-demo` community id.
- Claims: first verified email wins, enforced by the `claims` primary key inside one transaction (`INSERT … ON CONFLICT DO NOTHING`). Never read-then-write. Never overwrite an owner.
- Auth: passwordless one-time links (15 min, single use, consumed only by CSRF-protected POST), server-enforced 30-minute idle sessions (configurable 5–240), no remember-me, no background request may extend a session.
- Every email-sending endpoint requires proof-of-work **and** persistent rate limits, a honeypot check and the response-time floor (`ResponseFloorSubscriber::mark()` right after proof-of-work). Budgets that protect the mail relay are spent only by mail that is actually sent. Public APIs never expose emails, owner ids, tokens or the unclaimed address inventory.
- Roles: residents are `ROLE_USER`, organizers `ROLE_ADMIN` (granted only by `app:admin:grant` or `ORGANIZER_EMAILS`). Declare the role on every controller class (`#[IsGranted]`) in addition to `access_control`. A resident's own homes are reached only through `App\Listing\ResidentHomes::of($user)`; never query claims/listings by id for a resident without it.
- Geometry is validated on the server (`App\Geo`); browser checks are hints only. GeoJSON order is `[longitude, latitude]`.
- Tokens and secrets never appear in logs, Git, CI output or analytics. `.env*` (except `.env.example`, `.env.dist`, `.env.test`) and `deploy/private/` stay untracked.
- No inline `<script>`/`style=""` (strict CSP). Escape all user content.
- UI must stay faithful to the original mock (`docs/design/`): check 375, 430, 768 and 1440 px, keyboard use, reduced motion, 44 px touch targets, no horizontal scroll.

## 3. Structure

| Path | Purpose |
|---|---|
| `src/Geo` | GeoJSON parsing/validation, point-in-polygon, zones, distances |
| `src/Community` | config schema, repository (settings + versioned geometry), bootstrap, presenter, branding |
| `src/Address`, `src/Listing`, `src/Claim`, `src/Dispute`, `src/Admin`, `src/Audit` | domain services |
| `src/Security` | users, magic links, PoW, throttles, sessions, CSRF/origin guard |
| `src/Routing` | optional pedestrian provider adapter + planner |
| `src/Controller`, `templates/`, `public/assets/` | HTTP, Twig, CSS/JS |
| `migrations/` | Doctrine migrations (SQL) |
| `tests/Unit`, `tests/Integration`, `tests/js`, `tests/e2e`, `scripts/tests` | PHPUnit, node:test, Playwright, Python importer tests |
| `examples/` | fictional Maple Hollow demo and the Ventura Reserve real-plat example (official Orange County open data) |
| `docs/` | architecture, ADRs, runbooks, design, quality |

Every source file starts with a header block (`@module`, `@description`, dependencies). Keep modules single-purpose and under ~400 lines.

## 4. Workflow

1. Branch from `main`: `feature/<slug>` or `fix/<slug>`. Never commit work directly on `main`.
2. Write or update the failing test first (unit or integration), then the code. Never delete or weaken a test to make it pass.
3. Commit in small steps with Conventional Commit messages (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`, `security:`; `!` for breaking). Plain descriptive text; no tool/AI attribution trailers.
4. For every functional change append a record to `CHANGES.md` naming the regression test that anchors it, and update `CHANGELOG.md` (`Unreleased`). Record settled choices in `DECISIONS.md` (one line) and big trade-offs as an ADR.
5. Review staged changes for secrets and unintended files before each commit.
6. Merge to `main` only after all quality gates pass.

## 5. Quality gates (same commands as CI)

```bash
docker compose exec app sh bin/test.sh                              # PHPUnit (unit + integration, PostgreSQL)
docker compose exec app vendor/bin/phpstan analyse                  # level 6, must be clean
docker compose exec app composer audit --locked
docker run --rm -v "$PWD":/w -w /w node:22-alpine node --test tests/js/*.test.mjs
docker run --rm -v "$PWD":/w -w /w python:3.12-slim sh -c "pip install -q shapely==2.0.6 && python -I -m unittest discover -s scripts/tests"
./bin/e2e.sh                                                        # Playwright, needs both dev stacks running
python3 scripts/smoke_session.py                                    # session lifecycle smoke test
```

Coverage targets: lines ≥ 85 % (enforced), methods/branches reported honestly (PHPUnit counts a method only when fully covered; pcov does not measure branches — report `n/a`, never a made-up number). Concurrency, auth, PoW, throttle, geofence and session behaviour must have tests.

**Report what you actually ran**, with commands and real results. "It should work" is not evidence. Never claim a test, deployment or integration that did not happen.

## 6. Releases

Semantic Versioning. `VERSION`, the `CHANGELOG.md` heading and the annotated tag `vX.Y.Z` must match. Tag only milestones that are implemented and pass every gate; pre-releases use `-design.N`, `-alpha.N`, `-beta.N`. The release workflow opens a **draft** GitHub Release; publishing it, making the repository public, or deploying to a live server requires the owner's explicit go-ahead. Procedure: `docs/runbooks/release.md`.

## 7. Data and privacy rules for agents

- Do not commit real residents' emails, claims or any address inventory (`deploy/private/`).
- Do not commit anything that identifies the owner's own deployment: its community name, location, geometry, street names, screenshots, host names or reports. That stays in `deploy/private/<id>/`. Examples use fictional data or a third-party real-plat example without residents' data.
- Official county GIS layers are committed only as plat polygons for examples, with the source and terms recorded in the example's README; never address points.
- Do not commit third-party map screenshots (e.g. Google Maps). Use screenshots of this app with OSM tiles or fictional demo data.
