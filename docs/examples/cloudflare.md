# Example: Cloudflare in front of the map (optional)

This is **one example** of putting the map online, for people who already use Cloudflare for their domain. The app does not need Cloudflare: any HTTPS reverse proxy or tunnel works, and the [deployment runbook](../runbooks/deployment.md#3-https-in-front) covers the general case. The hostname `events.example.org` and port `8087` below are placeholders.

**Trade-off to know first:** Cloudflare terminates HTTPS, so it sees every request to the site (page addresses, visitors' IP addresses, sign-in pages). The app gets each visitor's address from the `X-Forwarded-For` header Cloudflare sends.

## 1. Tunnel to the stack

A Cloudflare Tunnel publishes the site without opening any port on your router or server. Run `cloudflared` on the Docker host (or reuse one that is already there) and add an ingress rule for the map:

```yaml
ingress:
  - hostname: events.example.org
    service: http://127.0.0.1:8087
  - service: http_status:404
```

- `cloudflared` sends `X-Forwarded-Proto: https` and the visitor's address in `X-Forwarded-For`. nginx trusts these only from loopback and its own network gateway, where connections through the published `127.0.0.1` port arrive. A host-level `cloudflared` therefore works without configuration.
- If `cloudflared` runs as a container on the stack's network instead, set `NGINX_TRUSTED_PROXIES` to its address.
- Check that `docker compose logs nginx` shows real visitor addresses. If every line shows the same private address, all visitors share one rate-limit budget.
- Route only the map's hostname to the stack: never PostgreSQL or Mailpit.

If you proxy DNS to your own reverse proxy instead of using a tunnel, set **SSL/TLS → Full (strict)** and keep a valid certificate on that proxy.

## 2. Settings on the free plan

The app defends itself without Cloudflare (proof-of-work, persistent limits, honeypots, a response-time floor, nginx limits per client network). These settings add an outer layer:

- **Security → WAF:** keep the free managed rules on. Add the one free *rate limiting rule*, for example 30 requests per 10 seconds per IP on paths starting with `/api/auth/`, `/api/addresses/` and `/api/disputes`, action *Block* for 1 minute.
- **Security → Bots:** *Bot Fight Mode* works. It injects a small inline "JavaScript detections" script that the app's Content-Security-Policy blocks: the browser console shows *Refused to execute inline script*, and Cloudflare scores bots from its other signals. The policy is not loosened for it.
- **SSL/TLS → Edge certificates:** *Always Use HTTPS* on. The app sends its own HSTS header over HTTPS.
- **Speed → Optimization:** leave *Rocket Loader* and *Email Address Obfuscation* **off**. They rewrite pages, and the policy blocks what they inject.
- **Caching:** respect origin headers. Scripts and styles use fingerprinted URLs (`/assets/v<version>.<hash>/…`) and are cached for a year; pages, the API and sign-in pages are `no-store`.
- **Zero Trust → Access:** an Access application can keep the site private while you test. A public site needs no Access application; remove it when you open the site.

## 3. Web Analytics (optional, cookie-free)

Cloudflare Web Analytics counts page views, paths, referrers, countries and devices, and measures page-load times (Core Web Vitals). Cloudflare says it uses no cookies or local storage and does not fingerprint visitors. Analytics are **off by default**.

To turn them on:

1. In Cloudflare, open **Analytics & Logs → Web Analytics**, add the site and choose **automatic setup**. Cloudflare then adds its beacon script to every page it serves.
2. In the stack's `.env`, set `CLOUDFLARE_WEB_ANALYTICS=1`, then recreate the app container: `docker compose up -d --wait app`.

With the setting on:
- the Content-Security-Policy also allows scripts from `https://static.cloudflareinsights.com`;
- the beacon sends its reports to the site's own `/cdn-cgi/rum`, which is already allowed;
- the footer reads "Cookie-free visit statistics", and the privacy page describes Cloudflare Web Analytics.

The app never adds the beacon itself, so the setting does nothing on a site Cloudflare does not proxy. Check your local rules on analytics; the privacy page describes the software's behaviour and is not legal advice.

To keep analytics off, leave `CLOUDFLARE_WEB_ANALYTICS=0` **and** turn off automatic setup. Otherwise Cloudflare keeps injecting a script that the policy blocks: harmless, but a console error on every page.

**Check it works:** open the site with the browser's developer tools. `beacon.min.js` loads from `static.cloudflareinsights.com`, a request to `/cdn-cgi/rum` succeeds, and the console shows no policy errors for it. Visits appear in the Web Analytics dashboard after a few minutes.

## 4. Verify

- `https://events.example.org/healthz` answers `{"status":"ok",…}` through Cloudflare.
- Over HTTPS the sign-in cookie is named `__Host-nhm_session` and is `Secure`.
- `docker compose logs nginx` shows visitors' real addresses.
- A burst of requests to `/api/auth/` is answered with `429`, by nginx or by your rate limiting rule.
