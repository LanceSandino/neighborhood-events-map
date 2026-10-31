# Architecture overview

```
 Browser (families, residents, organizers)
   │  HTTPS (any reverse proxy or tunnel)
   ▼
 nginx (unprivileged, 127.0.0.1:8087) ── static assets, query-less access log, sets HTTPS/real client IP,
   │                                       refuses percent-encoded paths
   │ FastCGI over Unix sockets in a volume shared only by these two containers (no TCP port)
   ▼
 PHP-FPM / Symfony 8.1 (www-data) ── pool "www" for everything; small pool "mail" for the two
   │                                  email-sending endpoints (they wait out the response floor)
   ├─ Controllers: pages, public API, auth, owner API, organizer API, routes
   ├─ Security: CanonicalPathSubscriber, firewall + MagicLinkAuthenticator, IdleSessionSubscriber, CsrfGuardSubscriber, PoW, Throttle
   ├─ Domain: Community (config + versioned geometry), Event (event types), Geo, Address, Listing, Claim, Dispute, Admin, Audit, Routing
   └─ Mailer → SMTP relay (Mailpit in development)
   │ DBAL (parameterized SQL)
   ▼
 PostgreSQL 18 (internal network only) ── daily pg_dump by the backup service
```

## Request lifecycle highlights

- **Public pages/APIs** (`/`, `/api/community`, `/api/homes`) are stateless reads of the active event's opted-in listings; they never start or touch a session. Between events `/` is a neutral "no event right now" page.
- **Listing a home:** `GET /api/pow` → browser worker → `POST /api/auth/request-link` (PoW, throttles, eligibility, claim intent, email) → email → `GET /auth/confirm` (read-only page) → `POST /auth/confirm` (consume token, upsert user, atomic claim + listing, rotate session) → `/me`.
- **Protected requests** (`/me`, `/admin`, `/api/me/*`, `/api/admin/*`): firewall loads the session user → `IdleSessionSubscriber` (expire or extend) → CSRF check for writes → controller.
- **Paths are canonical:** a percent-encoded path is refused before anything runs (nginx with 400, `CanonicalPathSubscriber` with 404), because Symfony routes on the decoded path while some guards read the raw one.

## Data model

`community_settings` (single row JSONB; its `event` block mirrors the active event), `community_geometries` (versioned boundary/zone FeatureCollections, approval), `eligible_addresses` (private registry, `inside_boundary` flag, the moderation hold), `users`, `claims` (PK = address), `claim_intents`, `event_occurrences` (one active), `event_listings` (one per event and address; type fields in `details` JSONB; `published`, `moderation_hidden`) with `event_listing_windows`, the legacy `listings` (one row per address, kept equal to the active Halloween event's listings for rollbacks), `auth_link_tokens` (hashes), `pow_challenges`, `throttle_events`, `disputes`, `audit_events`, `sessions`, `route_cache`. See `migrations/Version20261018000001.php` and later migrations.

## Event types

The community's event has a type (`event.type`, default `halloween`). Each type is a code module on the PHP side
(`src/Event`), in the browser (`public/assets/js/events`) and in Twig (`templates/event/<key>/`); core code asks the
type for listing fields, statuses, filters, icons and words. See [event types](event-types.md).

## Configuration precedence

Environment (`.env` / Compose) for infrastructure and secrets → community settings in the database (bootstrapped once from `community.yaml`, edited in the console) and the events (`event_occurrences`; the YAML's event block becomes the first one, Organizer → Events prepares and switches the next) → code defaults. Session idle and link lifetime: env > community settings > defaults (30/15 minutes).
