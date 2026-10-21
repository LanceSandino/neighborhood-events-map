# Release procedure

1. All gates green locally and in CI (AGENTS.md §5): PHPUnit, PHPStan, composer audit, JS and Python unit tests, Playwright E2E, session smoke test, image builds, migrations up/down/up.
2. Update `VERSION` and move the `Unreleased` notes in `CHANGELOG.md` into `## [X.Y.Z] - YYYY-MM-DD` (Added/Changed/Fixed/Security, known limitations, migration notes). Add `CHANGES.md` records.
3. Merge to `main` (squash). Tag on main: `git tag -a vX.Y.Z -m "X.Y.Z: <summary>"`.
4. `git push origin main vX.Y.Z` — the release workflow checks VERSION/CHANGELOG and opens a **draft** GitHub Release, and the Images workflow publishes `ghcr.io/<owner>/neighborhood-events-map` and `…-nginx` as `:X.Y.Z` and `:latest` (amd64 + arm64, with SBOM and a signed build-provenance attestation). Every push to `main` also publishes `:edge`. Check a published image with `gh attestation verify oci://ghcr.io/<owner>/neighborhood-events-map:X.Y.Z --owner <owner>`.
5. Owner reviews screenshots (`docs/design/screenshots/`) and the draft; publishing is a manual decision.

Pre-releases: `-design.N` (UI snapshots), `-alpha.N`, `-beta.N`. Never tag unbuilt or failing work.

## Making the repository public (only with the owner's explicit go-ahead)

The repository and its history are kept free of private data (DECISIONS D-20261019-02/03), so going public is a visibility switch, not a separate export. Before switching:

1. Scan the tree **and every commit and tag** for a private word list kept outside the repository (deployment name, locality, street names, host names, personal emails); it must print nothing:
   `git grep -n -i -F -f ~/private-words.txt $(git rev-list --all) -- . ; git log --all --format='%an %ae %cn %ce %s %b' | grep -i -F -f ~/private-words.txt`
2. Confirm there are no workflow runs, artifacts, caches or draft releases from before the scrub (`gh run list`, `gh api repos/<owner>/<repo>/actions/artifacts`, `gh release list`).
3. Confirm the license (MIT proposed) and the reuse terms of any committed county plat data (see the example's README).
4. The two container packages are public (package settings → visibility) and linked to the repository; their images contain only the app (no `.env`, `deploy/` or tests — see `.dockerignore`).
5. Check `deploy/private/` is still ignored (`git check-ignore -v deploy/private/x`) and nothing under it is tracked (`git ls-files deploy/private` prints nothing).
