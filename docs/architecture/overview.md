# Architecture overview

```
 Browser (families, residents, organizers)
   │  HTTPS (Cloudflare Tunnel or reverse proxy)
   ▼
 nginx (unprivileged, 127.0.0.1:8087) ── static assets, query-less access log, sets HTTPS/real client IP
   │ FastCGI
   ▼
 PHP-FPM / Symfony 7.4 (www-data)
   ├─ Controllers: pages, public API, auth, owner API, organizer API, routes
   ├─ Security: firewall + MagicLinkAuthenticator, IdleSessionSubscriber, CsrfGuardSubscriber, PoW, Throttle
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

## Data model

`community_settings` (single row JSONB), `community_geometries` (versioned boundary/zone FeatureCollections, approval), `eligible_addresses` (private registry, `inside_boundary` flag), `users`, `claims` (PK = address), `claim_intents`, `listings` (per event date; `published`, `moderation_hidden`), `auth_link_tokens` (hashes), `pow_challenges`, `throttle_events`, `disputes`, `audit_events`, `sessions`, `route_cache`. See `migrations/Version20261018000001.php`.

## Configuration precedence

Environment (`.env` / Compose) for infrastructure and secrets → community settings in the database (bootstrapped once from `community.yaml`, edited in the console) → code defaults. Session idle and link lifetime: env > community settings > defaults (30/15 minutes).
