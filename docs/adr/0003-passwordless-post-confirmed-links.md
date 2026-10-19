# ADR 0003 — Passwordless, POST-confirmed one-time links; 30-minute idle sessions

**Context.** Residents should not manage passwords. Email security scanners open links automatically; a GET-consumed link would be burned before the resident clicks.

**Decision.** `MagicLinkTokens` stores SHA-256 hashes of 256-bit random tokens with purpose, optional claim intent and a 15-minute expiry. `GET /auth/confirm` only renders a confirmation page; `MagicLinkAuthenticator` consumes the token atomically on a CSRF-protected `POST`, then (in the same request) completes any pending claim. The session is rotated (`session_fixation_strategy: invalidate`) and a `LoginSessionSubscriber` starts the server-side idle clock. `IdleSessionSubscriber` enforces the window on every protected request (default 30 min, env `AUTH_SESSION_IDLE_MINUTES` > YAML > default); requests flagged `X-NHM-Background: 1` and the session probe never extend it. Sessions live in PostgreSQL (`PgSessionHandler`, separate lazy PDO connection with advisory locks).

**Consequences.** Links survive scanners; sessions survive restarts; expiry is independent of browser timers. A custom token store was preferred over Symfony Login Link because the link also carries a claim intent.

**Alternatives.** Symfony Login Link (`check_post_only`), passwords, social login — rejected or insufficient.
