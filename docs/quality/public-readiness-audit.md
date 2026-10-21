# Public-readiness audit — 2026-10-21

Scope: everything that becomes public when the GitHub repository's visibility is switched to public. That means the tree, every commit and tag on GitHub, pull-request refs, workflow runs and artifacts, releases and the container images. It also covers the production defaults a stranger gets when they run the project. The release route is recorded in DECISIONS D-20261019-03: the history carries no private data, so going public is a visibility switch.

Released as 0.7.0-beta.1 (squash of `chore/public-ready` rebuilt on the map fixes: zone-label placement, filter-aware pins, movable zone names, tile seams). It started from the 0.6.0-beta.2 release (`1a706f3`, whose security commit `6d6ba0d` fixes the follow-up audit and sanitizes every input) plus the zone-label map fix. Nothing was pushed, tagged, published or deployed, and no repository or package setting was changed.

## Verdict

The code and history are ready for a public repository once the owner has made the decisions listed at the end.
- Scans of the tree, every commit and tag on GitHub and this branch, and the pull-request patches found no credential, no personal data and none of the owner's private words.
- All automated gates pass.
- All 19 leads of the follow-up security audit are fixed in 0.6.0-beta.2, each with tests; this branch adds four small hardening items on top. The remaining hardening notes are listed in section 6.

## 1. Secrets, personal data and private words

The release scanner checks credentials (key, token and assigned-secret shapes, credential files), personal data (home paths, emails, private addresses, this machine's user and host names), process vocabulary and artefacts. It ran with the owner's private word list and allowlist. Both are kept outside Git, and their contents are never printed. Results are counts only.

| Scan | Result |
|---|---|
| Can the scan fail? Branch tree with no allowlist and no word list | 349 findings (138 fail, 211 review) — the scan is live |
| Gate: branch tree + all its commits + all 10 release tags, with allowlist and words | **0 findings, exit 0** |
| Published `origin/main` tree + its commits | **0 findings** |
| Whole history reachable from GitHub refs, tags, `main` and this branch, plus the patches and bodies of pull requests #1–#8 | 44 commits, 980 unique blobs (176 binary), 11 annotated tags. 0 private words and 0 personal findings; all 88 author/committer entries are the public no-reply identity and every tag has the same tagger. 1 credential-shaped string: the known `halloween` CI/dev database password in pull request #5's patch of `ci.yml`, which the production container refuses. 2 "Phase N" review hits, both subdivision plat names |
| Images in the tree (PNG) | No EXIF, text or time chunks, except two third-party Leaflet.draw sprites with a tool tag. Screenshots show only the fictional demo and the public real-plat example |

**Pull-request refs.** GitHub keeps `refs/pull/1…8/head` forever, and they become public with the repository. All eight are Dependabot dependency bumps whose base commits are in the current, already-scrubbed history. No pre-scrub commit is reachable from GitHub.

## 2. Repository files

| Item | Status |
|---|---|
| `LICENSE` | MIT, copyright Lance Sandino. `composer.json` said `proprietary` (skeleton default): fixed to `MIT`. The license still needs the owner's confirmation (D-20261018-10) |
| `SECURITY.md` | Points to GitHub private vulnerability reporting, which the owner must enable after going public. Links the hardening report, including the follow-up audit fixed in 0.6.0-beta.2 |
| `CONTRIBUTING.md` | Event-neutral; links the roadmap, conduct and security policy; uses the fictional demo for screenshots |
| `CODE_OF_CONDUCT.md` | **Added** (it was missing) |
| `ROADMAP.md` | **Added**: Christmas lights and garage sale event types, with ADR 0010 and D-20261021-19 to -23 |
| `.gitignore` | Ignored only `deploy/private/`, while the docs put an operator's own `community.yaml` and boundary in `deploy/community/`: **fixed** to `/deploy/` |
| `.dockerignore` | Excludes `.env*`, `deploy`, the private `config/community.yaml` and `config/*.geojson` (0.6.0-beta.2), tests, docs, scripts, CI and caches |
| `AGENTS.md`, `CHANGES.md`, `DECISIONS.md` | Published on purpose (route D-20261019-03). They carry no private data, and the scans include them |

## 3. CI, release and image workflows

- **Permissions:** `ci.yml` and `release.yml` are least-privilege (`contents: read`; `contents: write` only for the draft release). `images.yml` has `packages: write`, `id-token: write` and `attestations: write` for publishing and attestations, and runs only on pushes to `main` and `v*` tags, never on pull requests. CI uses `pull_request`, not `pull_request_target`.
- **Secrets:** only `GITHUB_TOKEN`. The e2e job makes throwaway random secrets and never prints them.
- **Fixed on this branch:**
  - every action is pinned to the commit its major tag points to, with the version in a comment so Dependabot keeps updating them;
  - checkouts use `persist-credentials: false`, except the images job that fetches release tags (a private repository needs the token for that fetch);
  - `release.yml` drafts a release only for an annotated tag whose commit is on `main` (exercised on the next tag);
  - `bin/e2e.sh` installs with `npm ci` only.
- **GitHub state (read-only, checked for 0.6.0-beta.1):**
  - releases are drafts, which are not public;
  - there are no issues;
  - docker build-record artifacts expire by retention.
  - The repository description still says "trick-or-treat map" (owner action below).
- **Images:** the release and `edge` images are pullable anonymously from GHCR. Their content is the scanned release tree, minus the `.dockerignore` exclusions.

## 4. Dependencies

| Check | Result |
|---|---|
| `composer audit --locked` | No advisories |
| `npm audit --package-lock-only` (tests/e2e) | 0 vulnerabilities |
| `composer validate` | Valid; three unbounded bundle constraints (`*`) are now bounded to their locked majors (the lock changed only its hash) |
| Vendored front-end | Leaflet 1.9.4 (BSD-2-Clause) and Leaflet.draw 1.0.4 (MIT), each with its licence file; fonts are Nunito and DM Sans (SIL OFL 1.1) with licence files |

## 5. Defaults a stranger gets

- **Network:**
  - nginx binds to `127.0.0.1`; PostgreSQL has no published port.
  - PHP-FPM has no TCP port. nginx reaches it through Unix sockets in a volume that only `app` and `nginx` mount, and the email-sending endpoints have their own small pool.
  - Mailpit exists only in the dev and staging overlays, on loopback, and can require a password.
- **Startup checks:** `APP_SECRET` and `POSTGRES_PASSWORD` are required. The production container refuses placeholders, short secrets, the development database password and `APP_DEBUG` switched on, and accepts only the exact `APP_BASE_URL` host.
- **Containers:** read-only roots, `cap_drop: ALL`, `no-new-privileges` and resource limits; PHP and nginx run as non-root.
- **Backups:** `0600` files in a `0700` directory, without session rows. `bin/restore.sh` takes only a plain backup name.
- **Example data:**
  - the demo community is fictional and gated behind `APP_DEMO=1`;
  - the Ventura Reserve example ships only Orange County open-data plat polygons with their source recorded, and no address points, listings or claims;
  - its review page is now regenerated with the escaping generator;
  - the county's reuse terms need the owner's confirmation (below).

## 6. Security audit — fixed in 0.6.0-beta.2, with this branch's additions

The independent, source-only audit (run 1) found 19 leads. All are fixed in `6d6ba0d` (released as 0.6.0-beta.2). Each was confirmed by a test that failed on the old code. Owner-observed deployment facts in that audit's report are deliberately left out of this repository.

| Lead | Fixed by | Regression tests |
|---|---|---|
| 1, 2, 15 Percent-encoded paths (idle timeout, CSRF token, cross-site logout, nginx limits) | nginx refuses them (400) and keys limits on `$uri`; `CanonicalPathSubscriber` refuses them (404) | `RequestPathHardeningTest` (4 tests, plus this branch's 5 more cases); `ContainerConfigTest::testNginxRefusesEncodedPathsAndKeysLimitsOnTheNormalizedUri`; e2e "nginx refuses percent-escaped paths" (this branch) |
| 3, 16 Hidden zones readable by id, named in refusals | `publicHome` and the route planner skip hidden zones; refusals never name or confirm one | `HiddenZonePrivacyTest` (4 tests, plus this branch's walking-group test) |
| 4 PHP-FPM reachable on `:9000` | Unix sockets in a volume shared only by app and nginx | `ContainerConfigTest::testPhpFpmListensOnlyOnUnixSocketsReachableByNginx` |
| 5 Released self-entry pins inherited | the next resident places their own pin | `SelfEntryRepinTest` (3 tests) |
| 6 Review-request reporters cannot be erased | `app:user:delete` erases them without an account | `CommandsTest::testDeletionAlsoErasesSomeoneWhoOnlyAskedForAReview` |
| 7 Quoted email local part | plain local parts only; recipients built with `new Address()` | `EmailNormalizerTest` |
| 8 Revoke silently fails for `ORGANIZER_EMAILS` | fails loudly, says why | `CommandsTest::testRevokingAnOrganizerListedInTheEnvironmentFailsLoudly` |
| 9 Response floor holds PHP-FPM workers | email endpoints run in their own `mail` pool | `ContainerConfigTest::testNginxRoutesMailEndpointsToTheirPoolAndNeverToATcpUpstream` |
| 10 Organizer emails visible while the global cap is full | organizers get the same 429 (their link is still sent) | `AbuseHardeningTest::testOrganizersAreNotLockedOutByTheGlobalLinkBudget` |
| 11, 12 Registry address text for arbitrary ids | a request by id must name the house number | `RegistryIdBindingTest` (3 tests) |
| 13 Unbounded audit growth from listing saves | per-account listing-save limit | `AbuseHardeningTest::testResidentListingSavesAreRateLimitedPerAccount` |
| 14 Anonymous rows per IPv6 /64 | throttle rows purged when they leave their window | `ProofOfWorkAndThrottleTest::testPurgeDropsThrottleEventsOnceTheirOwnWindowHasPassed`, `…testExpiredChallengesArePurgedRightAway` |
| 17 Poison share link breaks the map | typed, own-key filters | `tests/js/lib.test.mjs` "shared or stored filters of the wrong type are dropped…" |
| 18 Routing-provider quota drain | global provider budget | `RoutePlanTest::testProviderCallsShareOneGlobalBudget` |
| 19 Review map renders remote attributes as HTML | popups built from text; Leaflet with SRI | `ReviewMapTest.test_popups_are_text_only_and_cdn_assets_are_pinned` |

0.6.0-beta.2 also:
- sanitizes every input (`Shared/TextInput`, plain-character addresses, anchored URLs) — `InputSanitizationTest`, `TextInputTest`, `AddressNormalizerTest`;
- matches the `APP_BASE_URL` host literally — `RegexQuoteEnvVarProcessorTest`;
- keeps rejected `/auth/confirm?t=…` lines out of the nginx error log;
- validates the transfer's review-request id.

**Added on this branch:**
- `bin/restore.sh` accepts only a plain backup name, and Python tests now pin the production refusals (APP_DEBUG, weak secrets) and the `.dockerignore` exclusions — `scripts/tests/test_operator_scripts.py`;
- hidden zone keys are dropped from the public walking groups — `HiddenZonePrivacyTest::testWalkingGroupsInThePublicPayloadDoNotNameAHiddenZone`;
- the committed example review page is regenerated, and a test keeps it equal to the generator's output — `test_committed_example_review_map_is_the_current_generators_output`;
- shared filters keep only a real "visit at" time and zone keys of at most 60 characters — `tests/js/lib.test.mjs`;
- the new `event.name` setting goes through the same `TextInput` cleaning — `CommunitySchemaTest`.

**Hardening notes still open.** Each was re-checked against the merged code.
- nginx limits key on the full IPv6 address while the app keys per /64, and `limit_conn 40` is above the `www` pool's 12 workers.
- Interrupted backups leave `.partial` files that never rotate.
- A restore brings back deleted accounts and revoked organizers.
- The `maintenance` service still inherits the mail and routing secrets it does not use.
- Base images are pinned by tag, not digest.
- The GIS importer accepts `http://` layer URLs and has no response-size cap.
- A `__Host-` session cookie is documented but not the default, because renaming the cookie would sign everyone out.
- There is no "sign out everywhere".
- The public payload carries the bounding box of a boundary that is not approved yet.
- GeoJSON self-intersection checks run before the vertex cap.
- The claims CSV export is a GET that writes an audit event.

## 7. Event-neutral naming

The product is now the "neighborhood events map", and the community's event is the `event.name` setting (default Halloween, at most 40 characters, cleaned like all community text). Titles, sidebar, footer, meta description and emails read "<community> <event name>". Halloween stays where it is the event type's own content: trick-or-treat copy, reminders, access labels, branding defaults and the demo.

| Identifier | Choice | Why |
|---|---|---|
| Settings saved before `event.name` existed | **Migrated on read** (default Halloween) | existing installs keep their wording |
| `COMPOSE_PROJECT_NAME` / `POSTGRES_DB` / `POSTGRES_USER` default `halloween` | Kept | changing them orphans volumes and data |
| `halloween-*.dump` backup names | Kept | retention and restore of existing backups |
| `nhm:*` localStorage keys, `nhm_session` cookie, `nhm` CSRF id, `X-NHM-Background` | Kept | saved routes, sessions and open pages |
| GHCR image names | Kept (already neutral) | deployed tags |
| Migration descriptions | Kept | migrations are history |
| `MAIL_FROM` default, compose/Dockerfile headers, dev image tag, importer User-Agents, e2e package name | Renamed | not persisted anywhere |

Recorded as D-20261021-18.

## 8. Tests and structure

All commands were run from the branch worktree against two local stacks.

| Gate | Command | Result |
|---|---|---|
| PHPUnit | `docker compose exec -T app sh bin/test.sh` | OK — 252 tests, 1887 assertions |
| Coverage | `php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text` | Lines 94.28 % (≥ 85 ✓), methods 81.14 %, branches n/a |
| PHPStan | `docker compose exec -T app vendor/bin/phpstan analyse --no-progress` | No errors (level 6) |
| node:test | `docker run … node:22-alpine node --test tests/js/*.test.mjs` | 27/27 |
| Python | `docker run … python:3.12-slim … unittest discover -s scripts/tests` | 36/36 |
| E2E | Playwright 1.64.1 against both stacks | 76 passed, 2 skipped (WebKit location needs HTTPS) |
| Session smoke | `python3 scripts/smoke_session.py --base … --mailpit …` | 12/12 |
| Dependencies | `composer audit --locked`, `npm audit --package-lock-only`, `composer validate`, `composer install --dry-run` | no advisories, 0 vulnerabilities, valid, nothing to install |
| Module structure | module-structure fitness check (external tool) | **87.5 / 100 PASS** |

The module-structure check reports:
- module size 100, headers 95.7, deep imports 100, cycles 0 and fan-out 97.6;
- code_root 69.0 and testability 15.9 below their targets — both accepted deviations (D-20261018-13, D-20261018-14).

## 9. Decisions and actions for the owner before switching to public

1. Confirm the **MIT license** (LICENSE and composer.json now agree) — D-20261018-10.
2. Confirm the **reuse terms** of the Orange County plat polygons in `examples/ventura-reserve/`, or remove the example.
3. Confirm the **roadmap architecture** (ADR 0010; D-20261021-19 to -23, marked "owner to confirm"), and that the Halloween-era identifiers stay as listed in section 7 (D-20261021-18).
4. After switching, turn on **private vulnerability reporting**. SECURITY.md and the code of conduct point to it.
5. Update the repository **description and topics** (currently "neighborhood trick-or-treat map").
6. Decide on the **open hardening notes** in section 6.
7. **Refresh the screenshots** after the last map change lands. They predate the zone-label and pin work on `fix/zone-labels`.
