# Deployment — self-hosted Docker host (e.g. an Ubuntu home server)

> Do not deploy to a live server or change its existing services without the owner's explicit go-ahead.

## 1. Prepare the host

- Docker Engine with Compose v2; a non-root deploy user in the `docker` group.
- Choose a free loopback port (default `8087`); nothing else needs to be exposed.

Nothing is built on the server: each release is published as ready-made images for amd64 and arm64
(`ghcr.io/<owner>/neighborhood-events-map` and `…-nginx`). The server only needs that release's compose files
(a shallow clone, or the release's source archive) plus your `.env` and community folder.

```bash
git clone --depth 1 --branch vX.Y.Z https://github.com/<owner>/neighborhood-events-map.git /srv/neighborhood-events-map
cd /srv/neighborhood-events-map
cp .env.example .env && chmod 600 .env
```

Edit `.env` (production values; keep `COMPOSE_FILE` commented out):

```
APP_IMAGE_TAG=X.Y.Z                                 # the release you deploy (never "latest" in production)
APP_SECRET=<openssl rand -hex 32>
POSTGRES_PASSWORD=<openssl rand -hex 32>
APP_BASE_URL=https://events.example.org
MAILER_DSN=smtp://user:pass@smtp.relay.example:587
MAIL_FROM="Neighborhood events <no-reply@example.org>"
ADMIN_EMAIL=organizer@example.org
ORGANIZER_EMAILS=organizer@example.org            # organizer accounts (comma-separated); remove to revoke
COMMUNITY_BOOTSTRAP_FILE=/app/deploy/community/community.yaml
SESSION_COOKIE_NAME=nhm_session                    # served over HTTPS the cookie is __Host-nhm_session automatically
```

Put your community folder in place: `mkdir -p deploy/community && cp config/community.example.yaml deploy/community/community.yaml`, then add your reviewed boundary GeoJSON (see [setup](setup.md)). Keep real deployment data under the git-ignored `deploy/` folder, never in `examples/`.

## 2. Start

```bash
docker compose pull                       # the published images for this host's CPU
docker compose up -d --wait
docker compose ps                         # app/nginx/postgres healthy, backup running
curl -s http://127.0.0.1:8087/healthz     # {"status":"ok",...}
docker compose exec app php bin/console app:admin:grant organizer@example.org
```

To build from the checked-out source instead of pulling, run `docker compose up -d --build --wait` (the images get the same names, tagged locally). Migrations run automatically on start (`MIGRATE_ON_START=1`). The container refuses to start with a placeholder or short `APP_SECRET`, the development database password, or `APP_DEBUG` switched on.

### Staging behind a tunnel (production image, test email)

Before real SMTP is ready, set `COMPOSE_FILE=compose.yaml:compose.staging.yaml` in `.env`: the stack runs the production image and settings (OPcache, preload, no debug, read-only containers, allowed host = `APP_BASE_URL` only) and sends email to Mailpit (`MAILPIT_UI_PORT`, local only). The site answers only on its real hostname, so test it through the tunnel; sign-in requests from other addresses are refused by design. Remove the overlay line once `MAILER_DSN` points at a real relay.

### Performance settings (production image)

- **OPcache** is on, never re-checks files, and **preloads** Symfony's hot classes when PHP-FPM starts (`docker/php/php-prod.ini`). Deploys clear it by recreating the containers (see the upgrade runbook).
- **Composer** autoloading is class-map-authoritative and the Symfony cache is warmed at image build.
- **PHP-FPM** worker pool: each worker uses about 30–40 MB. Set `FPM_MAX_CHILDREN` to roughly the memory you give PHP divided by 40 MB (default 12 ≈ 512 MB), with `FPM_START_SERVERS`, `FPM_MIN_SPARE` and `FPM_MAX_SPARE` in `.env`; then recreate the `app` container. If the logs show `server reached pm.max_children`, raise it (or add memory). The sign-in and review-request endpoints run in a separate small pool (`FPM_MAIL_MAX_CHILDREN`, default 4, started on demand), so a flood of them cannot take the map's workers. nginx also lets one client network (an IPv4 address or an IPv6 /64) keep at most 6 requests in PHP at a time and 2 on the email endpoints, half of each default pool; if you lower `FPM_MAX_CHILDREN` below 8, lower the `limit_conn perip 6` lines in `docker/nginx/default.conf` to match (D-20261021-27).
- **PHP-FPM has no TCP port.** nginx reaches it through Unix sockets in the `php-socket` volume, which only the `app` and `nginx` containers mount, so nothing else on the host or the Docker networks can talk FastCGI to it. Recreating `app` alone is fine: nginx connects to the socket path on every request and needs no restart.
- **nginx** gzips text responses and caches fingerprinted scripts and styles for a year.
- Measure performance on the **production** stack (no `COMPOSE_FILE` dev overlay). The development stack re-checks every PHP file, runs a coverage driver and adds `X-Robots-Tag: noindex`, so it scores lower on Lighthouse by design.

## 3. HTTPS in front

The stack serves plain HTTP on `127.0.0.1` only. Put something in front that holds the HTTPS certificate for your hostname and forwards requests to `http://127.0.0.1:8087`: a reverse proxy on the same host (Caddy, nginx, Traefik) or a tunnel service. Whatever you choose must:

- keep the original `Host` header (the `APP_BASE_URL` host; production answers `400` to any other host);
- send `X-Forwarded-Proto: https` and the visitor's address in `X-Forwarded-For`.

nginx trusts these headers only from loopback and from its own network gateway, where every connection through the published `127.0.0.1` port arrives, so a proxy on the same host works without configuration. If your proxy runs as a container on the stack's network instead, set `NGINX_TRUSTED_PROXIES` to its address. nginx then sets `HTTPS=on` for PHP (secure `__Host-` cookie, HSTS) and logs no query strings. Check that `docker compose logs nginx` shows real visitor addresses: if every line shows the same private address, all visitors share one rate-limit budget. Never expose PostgreSQL or Mailpit (Mailpit is not part of the production stack).

For example, Caddy on the same host gets and renews the certificate itself and sends the headers above:

```caddy
events.example.org {
    reverse_proxy 127.0.0.1:8087
}
```

Already using Cloudflare for your domain? [docs/examples/cloudflare.md](../examples/cloudflare.md) is an optional example with Cloudflare Tunnel, free-plan security settings and optional cookie-free Web Analytics (`CLOUDFLARE_WEB_ANALYTICS`).

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
| 502 from nginx | `docker compose ps`, `docker compose logs app`; nginx logs `connect() to unix:/run/php/…` while PHP-FPM is (re)starting |
| No emails | `MAILER_DSN`, relay logs, SPF/DKIM; the API returns 503 `mail_unavailable` on transport errors |
| Everyone throttled | the access log shows one private address for every visitor → set `NGINX_TRUSTED_PROXIES` to your proxy's address; or many neighbors really share one IP → raise `RL_LINK_PER_IP` |
| `400 Bad Request` on every page | the browser's hostname differs from `APP_BASE_URL` (production accepts only that host) |
| Grey map | tile URL/policy; CSP `img-src` follows the configured tile host |
| Cookies not Secure | the proxy or tunnel must send `X-Forwarded-Proto: https` |
