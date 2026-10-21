# Contributing

Thanks for helping neighbors run safer, friendlier neighborhood events — Halloween trick-or-treating today, more event types on the [roadmap](ROADMAP.md).

1. Read [AGENTS.md](AGENTS.md) (development protocol — applies to humans and AI agents alike), [DECISIONS.md](DECISIONS.md) and the ADRs in `docs/adr/`. Settled decisions stay settled unless new evidence supersedes them.
2. Start the dev stacks (`README.md` → Quick start). Use the fictional demo community for screenshots and manual testing; never use real residents' data, and keep your own neighborhood's files in the git-ignored `deploy/` folder.
3. Branch from `main` (`feature/…` or `fix/…`), write a failing test first, then the change.
4. Run the quality gates listed in AGENTS.md §5 and include the commands and results in your pull request.
5. Use Conventional Commits; update `CHANGELOG.md` (Unreleased) and `CHANGES.md` (with the regression test that anchors the change).
6. UI changes: compare against `docs/design/` at 375, 430, 768 and 1440 px in light and dark, and attach screenshots of the fictional demo.

Please keep the project small and dependency-light: no frontend build tooling, no new services without an ADR.

Security problems are reported privately, never in an issue: see [SECURITY.md](SECURITY.md). Everyone taking part follows the [code of conduct](CODE_OF_CONDUCT.md).
