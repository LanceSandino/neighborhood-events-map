# Architecture overview

```
 Browser (families, residents, organizers)
   │  HTTPS (Cloudflare Tunnel or reverse proxy)
   ▼
 nginx (unprivileged, 127.0.0.1:8087) ── static assets, query-less access log, sets HTTPS/real client IP,
   │                                       refuses percent-encoded paths
   │ FastCGI over Unix sockets in a volume shared only by these two containers (no TCP port)
   ▼
 PHP-FPM / Symfony 7.4 (www-data) ── pool "www" for everything; small pool "mail" for the two
   │                                  email-sending endpoints (they wait out the response floor)
   ├─ Controllers: pages, public API, auth, owner API, organizer API, routes
   ├─ Security: CanonicalPathSubscriber, firewall + MagicLinkAuthenticator, IdleSessionSubscriber, CsrfGuardSubscriber, PoW, Throttle
   ├─ Domain: Community (config + versioned geometry), Geo, Address, Listing, Claim, Dispute, Admin, Audit, Routing
   └─ Mailer → SMTP relay (Mailpit in development)
   │ DBAL (parameterized SQL)
   ▼
 PostgreSQL 17 (internal network only) ── daily pg_dump by the backup service
```

## Request lifecycle highlights

- **Public pages/APIs** (`/`, `/api/community`, `/api/homes`) are stateless reads of opted-in listings for the configured event date; they never start or touch a session.
- **Listing a home:** `GET /api/pow` → browser worker → `POST /api/auth/request-link` (PoW, throttles, eligibility, claim intent, email) → email → `GET /auth/confirm` (read-only page) → `POST /auth/confirm` (consume token, upsert user, atomic claim + listing, rotate session) → `/me`.
- **Protected requests** (`/me`, `/admin`, `/api/me/*`, `/api/admin/*`): firewall loads the session user → `IdleSessionSubscriber` (expire or extend) → CSRF check for writes → controller.
- **Paths are canonical:** a percent-encoded path is refused before anything runs (nginx with 400, `CanonicalPathSubscriber` with 404), because Symfony routes on the decoded path while some guards read the raw one.

## Data model

`community_settings` (single row JSONB), `community_geometries` (versioned boundary/zone FeatureCollections, approval), `eligible_addresses` (private registry, `inside_boundary` flag), `users`, `claims` (PK = address), `claim_intents`, `listings` (per event date; `published`, `moderation_hidden`), `auth_link_tokens` (hashes), `pow_challenges`, `throttle_events`, `disputes`, `audit_events`, `sessions`, `route_cache`. See `migrations/Version20261018000001.php`.

## Configuration precedence

Environment (`.env` / Compose) for infrastructure and secrets → community settings in the database (bootstrapped once from `community.yaml`, edited in the console; they include the event name, date and hours) → code defaults. Session idle and link lifetime: env > community settings > defaults (30/15 minutes).
