# Deployment — self-hosted Docker host (e.g. an Ubuntu home server) with Cloudflare Tunnel

> Do not deploy to a live server or change its existing services without the owner's explicit go-ahead.

## 1. Prepare the host

- Docker Engine with Compose v2; a non-root deploy user in the `docker` group.
- Choose a free loopback port (default `8087`); nothing else needs to be exposed.

```bash
git clone https://github.com/<owner>/neighborhood-events-map.git /srv/halloween
cd /srv/halloween
git checkout v0.4.0-beta.4            # or the release tag you are deploying
cp .env.example .env && chmod 600 .env
```

Edit `.env` (production values; keep `COMPOSE_FILE` commented out):

```
APP_SECRET=<openssl rand -hex 32>
POSTGRES_PASSWORD=<openssl rand -hex 32>
APP_BASE_URL=https://halloween.example.org
MAILER_DSN=smtp://user:pass@smtp.relay.example:587
MAIL_FROM="Neighborhood Halloween <no-reply@example.org>"
ADMIN_EMAIL=organizer@example.org
ORGANIZER_EMAILS=organizer@example.org            # organizer accounts (comma-separated); remove to revoke
COMMUNITY_BOOTSTRAP_FILE=/app/deploy/community/community.yaml
SESSION_COOKIE_NAME=__Host-nhm_session             # requires HTTPS (Cloudflare Tunnel); keep nhm_session for plain-HTTP tests
```

Put your community folder in place: `mkdir -p deploy/community && cp config/community.example.yaml deploy/community/community.yaml`, then add your reviewed boundary GeoJSON (see [setup](setup.md)). Keep real deployment data under the git-ignored `deploy/` paths, never in `examples/`.

## 2. Start

```bash
docker compose up -d --build --wait
docker compose ps                         # app/nginx/postgres healthy, backup running
curl -s http://127.0.0.1:8087/healthz     # {"status":"ok",...}
docker compose exec app php bin/console app:admin:grant organizer@example.org
```

Migrations run automatically on start (`MIGRATE_ON_START=1`). The container refuses to start with a placeholder or short `APP_SECRET`.

### Staging behind a tunnel (production image, test email)

Before real SMTP is ready, set `COMPOSE_FILE=compose.yaml:compose.staging.yaml` in `.env`: the stack runs the production image and settings (OPcache, preload, no debug, read-only containers, allowed host = `APP_BASE_URL` only) and sends email to Mailpit (`MAILPIT_UI_PORT`, local only). The site answers only on its real hostname, so test it through the tunnel; sign-in requests from other addresses are refused by design. Remove the overlay line once `MAILER_DSN` points at a real relay.

### Performance settings (production image)

- **OPcache** is on, never re-checks files, and **preloads** Symfony's hot classes when PHP-FPM starts (`docker/php/php-prod.ini`). Deploys clear it by recreating the containers (see the upgrade runbook).
- **Composer** autoloading is class-map-authoritative and the Symfony cache is warmed at image build.
- **PHP-FPM** worker pool: each worker uses about 30–40 MB. Set `FPM_MAX_CHILDREN` to roughly the memory you give PHP divided by 40 MB (default 12 ≈ 512 MB), with `FPM_START_SERVERS`, `FPM_MIN_SPARE` and `FPM_MAX_SPARE` in `.env`; then recreate the `app` container. If the logs show `server reached pm.max_children`, raise it (or add memory).
- **nginx** gzips text responses and caches fingerprinted scripts and styles for a year.
- Measure performance on the **production** stack (no `COMPOSE_FILE` dev overlay). The development stack re-checks every PHP file, runs a coverage driver and adds `X-Robots-Tag: noindex`, so it scores lower on Lighthouse by design.

## 3. Cloudflare Tunnel

Reuse the host's existing `cloudflared` (recommended) and add an ingress rule:

```yaml
ingress:
  - hostname: halloween.example.org
    service: http://127.0.0.1:8087
  - service: http_status:404
```

`cloudflared` sends `X-Forwarded-Proto: https` and the client IP in `X-Forwarded-For`. nginx trusts these only from loopback and from its own network gateway — the address every connection through the published `127.0.0.1` port arrives from — so a host-level `cloudflared` works without configuration. If cloudflared (or another proxy) runs as a container on the stack's network instead, set `NGINX_TRUSTED_PROXIES` to its address. nginx then sets `HTTPS=on` for PHP (secure cookies, HSTS) and logs no query strings. Check the access log (`docker compose logs nginx`) shows real visitor IPs; if every line shows the same private address, per-IP limits would treat all visitors as one. Never route the tunnel to PostgreSQL or Mailpit (Mailpit is not part of the production stack).

### Cloudflare free plan (optional extra layer)

The app defends itself without Cloudflare: proof-of-work, persistent per-IP/per-mailbox/global limits and honeypots on every email or claim request, a response-time floor, nginx request and connection limits per client, body caps and timeouts. Cloudflare's free plan adds an outer layer:

- **Security → Bots:** turn on *Bot Fight Mode*.
- **Security → WAF:** keep the free managed rules on; add the one free *rate limiting rule*, for example 30 requests per 10 seconds per IP on paths starting with `/api/auth/`, `/api/addresses/` and `/api/disputes`, action *Block* for 1 minute.
- **SSL/TLS:** *Full*, plus *Always Use HTTPS*.
- **Speed → Optimization:** leave *Rocket Loader*, *Email Address Obfuscation* and automatic script injection **off**. They rewrite pages, and the strict Content-Security-Policy blocks what they inject.
- **Caching:** respect origin headers. Scripts and styles use fingerprinted URLs (`/assets/v<version>.<hash>/…`), so an update never mixes old and new code, and pages, the API and sign-in are `no-store`.
- **Zero Trust → Access** can keep the site private while you test; remove the Access policy when you open it to the public.

## 4. Verify

- Visit the hostname on a phone; check the event badge, map tiles and attribution.
- Set **Community → Contact link** (e.g. `mailto:organizer@…`) so the privacy, terms and accessibility pages give visitors a way to reach you.
- Sign in as organizer, review and approve the boundary, import addresses, open listings.
- List a test home with a real email, confirm, edit, release it.
- Confirm a backup file appears in `./backups` (mode `0600`) and practice a restore (see backup-restore runbook).
- `docker compose logs maintenance` shows an hourly `Purged: …` line.
- `curl -s -o /dev/null -w '%{http_code}' -H 'Host: other.example' http://127.0.0.1:8087/` returns `400` (only the `APP_BASE_URL` host is accepted).

## Troubleshooting

| Symptom | Check |
|---|---|
| 502 from nginx | `docker compose ps`, `docker compose logs app` |
| No emails | `MAILER_DSN`, relay logs, SPF/DKIM; the API returns 503 `mail_unavailable` on transport errors |
| Everyone throttled | the access log shows one private address for every visitor → set `NGINX_TRUSTED_PROXIES` to your proxy's address; or many neighbors really share one IP → raise `RL_LINK_PER_IP` |
| `400 Bad Request` on every page | the browser's hostname differs from `APP_BASE_URL` (production accepts only that host) |
| Grey map | tile URL/policy; CSP `img-src` follows the configured tile host |
| Cookies not Secure | tunnel must send `X-Forwarded-Proto: https` |
