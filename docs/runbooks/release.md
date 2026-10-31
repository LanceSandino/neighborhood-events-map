# Release procedure

1. All gates green locally and in CI (AGENTS.md §5): PHPUnit, PHPStan, composer audit, JS and Python unit tests, Playwright E2E, session smoke test, image builds, migrations up/down/up.
   Then the production images themselves: `docker build --target prod -t nhm-app:smoke . && docker build --target nginx -t nhm-nginx:smoke . && bin/smoke-prod-image.sh nhm-app nhm-nginx smoke` (prod settings with OPcache preloading, which the development stack does not use). CI runs it for pull requests, and the Images workflow runs it on both platforms before any tag points at the images; before a deploy, run it once more against the published tag (`bin/smoke-prod-image.sh ghcr.io/<owner>/neighborhood-events-map ghcr.io/<owner>/neighborhood-events-map-nginx X.Y.Z`).
   A release with a schema migration also runs its rollback drill on the images (for 0.11, event-scoped listings: `bin/drill-event-upgrade.sh nhm-app nhm-nginx smoke`; [event-types-migration.md](event-types-migration.md) has both rollback paths and their limits).
2. Update `VERSION` and move the `Unreleased` notes in `CHANGELOG.md` into `## [X.Y.Z] - YYYY-MM-DD` (Added/Changed/Fixed/Security, known limitations, migration notes). Add `CHANGES.md` records.
3. Merge to `main` (squash). Tag on main: `git tag -a vX.Y.Z -m "X.Y.Z: <summary>"`.
4. `git push origin main vX.Y.Z` — the release workflow checks VERSION/CHANGELOG and opens a **draft** GitHub Release, and the Images workflow publishes `ghcr.io/<owner>/neighborhood-events-map` and `…-nginx` as `:X.Y.Z` and `:latest` (amd64 + arm64, with SBOM and a signed build-provenance attestation). Every push to `main` builds each platform once on its own native GitHub runner and publishes `:edge` and `:sha-<short>`; a release tag never rebuilds — it gets the images of its commit (pushed together with `main`, the main run applies the version tags; pushed later, the tag run copies them in seconds). Tag only commits on `main`. Check a published image with `gh attestation verify oci://ghcr.io/<owner>/neighborhood-events-map:X.Y.Z --owner <owner>`.
5. Owner reviews screenshots (`docs/design/screenshots/`) and the draft; publishing is a manual decision.

Pre-releases: `-design.N` (UI snapshots), `-alpha.N`, `-beta.N`. Never tag unbuilt or failing work.

## Making the repository public (only with the owner's explicit go-ahead)

The repository and its history are kept free of private data (DECISIONS D-20261019-02/03), so going public is a visibility switch, not a separate export. Before switching:

1. Scan the tree **and every commit and tag** for a private word list kept outside the repository (deployment name, locality, street names, host names, personal emails); it must print nothing:
   `git grep -n -i -F -f ~/private-words.txt $(git rev-list --all) -- . ; git log --all --format='%an %ae %cn %ce %s %b' | grep -i -F -f ~/private-words.txt`
2. Confirm there are no workflow runs, artifacts, caches or draft releases from before the scrub (`gh run list`, `gh api repos/<owner>/<repo>/actions/artifacts`, `gh release list`).
3. The license is MIT (D-20261021-24). Any newly committed county plat data needs its reuse terms checked and recorded in the example's README (the Ventura Reserve terms were accepted, D-20261021-25).
4. The two container packages are public (package settings → visibility) and linked to the repository; their images contain only the app (no `.env`, `deploy/` or tests — see `.dockerignore`).
5. Check `deploy/` is still ignored (`git check-ignore -v deploy/private/x deploy/community/community.yaml`) and nothing under it is tracked (`git ls-files deploy` prints nothing).
6. Pull-request refs (`refs/pull/*/head`) become public too and cannot be deleted by the owner: check every one is based on the current history (`git ls-remote origin 'refs/pull/*'`). The last full check is in [docs/quality/public-readiness-audit.md](../quality/public-readiness-audit.md).
