# Security policy

## Reporting a vulnerability

Please report security problems **privately** through GitHub's private vulnerability reporting (this repository → Security → Report a vulnerability) — do not open a public issue. Include steps to reproduce, affected version (`VERSION` or the footer of the site) and impact. You will get an acknowledgement within a few days. Please do not test against a live neighborhood deployment without the organizer's permission.

## Supported versions

Only the latest release line receives fixes (currently `0.x` pre-releases).

## Design summary (what to expect)

- Passwordless sign-in: 256-bit one-time tokens, stored only as SHA-256 hashes, valid 15 minutes, consumed by a CSRF-protected POST (never by GET), never logged; using one link retires the others. Links are built only from `APP_BASE_URL`.
- The sign-in reply is identical whether or not an account exists, and every reply after a solved proof-of-work takes at least `AUTH_RESPONSE_FLOOR_MS` (+ jitter), so timing reveals nothing either.
- Sessions stored server-side in PostgreSQL; rotated at sign-in; destroyed at logout; 30-minute inactivity timeout and a 12-hour absolute lifetime enforced on the server for every protected request.
- Proof-of-work (purpose- and client-bound, single use, short-lived) **plus** persistent rate limits on all email-sending endpoints (per IP — IPv6 per /64 — per mailbox with aliases folded, per address, global); `429` with `Retry-After`; honeypot fields on every form; nginx request/connection limits ahead of PHP. PoW raises cost; it does not make the service bot-proof.
- Paths with percent escapes are refused by nginx (400) and the app (404) before routing, so every security check sees the same path the router does.
- Same-origin checks on every state-changing request and session CSRF tokens on signed-in writes; strict CSP (`script-src 'self'`, `style-src 'self'`), `X-Frame-Options: DENY`, HSTS behind HTTPS; production accepts only the `APP_BASE_URL` host.
- Roles: residents (`ROLE_USER`) manage only their own homes, reached through the owner-scoped `ResidentHomes` (every query binds the owner in SQL); organizers (`ROLE_ADMIN`) are granted only from the command line or `ORGANIZER_EMAILS`, enforced in `access_control` and on the controllers. A resident manages at most `registration.max_homes_per_user` homes; an organizer's "hide" survives release and re-claim.
- Ownership is enforced by a database primary key in one transaction; organizer actions are audited.
- Public endpoints expose only opted-in listings (no emails, owner ids or unclaimed address inventory); registry lookups work only while listings are open.
- PostgreSQL is never published on a host port; nginx binds to `127.0.0.1` for a tunnel or reverse proxy and trusts forwarded headers only from that path. Containers run without root where the image allows, with no new privileges, dropped capabilities, read-only root filesystems (app, nginx, maintenance) and resource limits. Mailpit is development-only.

Audit and hardening results: [docs/security/hardening-report.md](docs/security/hardening-report.md) — the 0.4.0-beta.2 audit and the [follow-up audit fixed in 0.6.0-beta.2](docs/security/hardening-report.md#follow-up-audit--060-beta1-2026-10-21); the public-release checks are in [docs/quality/public-readiness-audit.md](docs/quality/public-readiness-audit.md).

## Data retention and deletion

Expired links, PoW challenges, claim intents, throttle events, sessions and route-cache rows are purged hourly by the `maintenance` service (`bin/console app:maintenance:purge`). Backups are private (`0600` files in a `0700` directory) and never contain session rows. Listings from past event dates stop being public automatically. Account deletion on request: `bin/console app:user:delete <email> --confirm`.

## Known, accepted limitations

- Controlling an email address is not proof of living at a home ("first verified email wins"). Organizers resolve disputes manually.
- Self-reported sensory labels are not guarantees.
- Rate limits are per IP (IPv6 per /64); many neighbors behind one IP may need higher per-IP limits (configurable).
- A distributed attacker who solves proof-of-work for many requests can still use up the global hourly sign-in mail budget for residents (organizers are exempt). It protects the mail relay and is configurable.

## Operator checklist

Random `APP_SECRET` (≥ 32 chars; the container refuses placeholders and test values), strong `POSTGRES_PASSWORD` (the container refuses the development default), `APP_DEBUG=0` (the production container refuses anything else), HTTPS in front (Cloudflare Tunnel or proxy), authenticated SMTP relay with SPF/DKIM, daily backups with a tested restore, `composer audit` before upgrades, and keep `.env` and `deploy/` out of Git.
