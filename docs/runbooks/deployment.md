# Deployment — self-hosted Docker host (e.g. an Ubuntu home server) with Cloudflare Tunnel

> Do not deploy to a live server or change its existing services without the owner's explicit go-ahead.

## 1. Prepare the host

- Docker Engine with Compose v2; a non-root deploy user in the `docker` group.
- Choose a free loopback port (default `8087`); nothing else needs to be exposed.

```bash
git clone https://github.com/<owner>/neighborhood-halloween-map.git /srv/halloween
cd /srv/halloween
git checkout v0.4.0-beta.2            # or the release tag you are deploying
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

## 3. Cloudflare Tunnel

Reuse the host's existing `cloudflared` (recommended) and add an ingress rule:

```yaml
ingress:
  - hostname: halloween.example.org
    service: http://127.0.0.1:8087
  - service: http_status:404
```

`cloudflared` sends `X-Forwarded-Proto: https` and the client IP in `X-Forwarded-For`. nginx trusts these only from loopback and from its own network gateway — the address every connection through the published `127.0.0.1` port arrives from — so a host-level `cloudflared` works without configuration. If cloudflared (or another proxy) runs as a container on the stack's network instead, set `NGINX_TRUSTED_PROXIES` to its address. nginx then sets `HTTPS=on` for PHP (secure cookies, HSTS) and logs no query strings. Check the access log (`docker compose logs nginx`) shows real visitor IPs; if every line shows the same private address, per-IP limits would treat all visitors as one. Never route the tunnel to PostgreSQL or Mailpit (Mailpit is not part of the production stack).

## 4. Verify

- Visit the hostname on a phone; check the event badge, map tiles and attribution.
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
