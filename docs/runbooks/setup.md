# Setting up a neighborhood (organizer guide)

1. **Install** (see README → Quick start). Grant yourself the organizer role:
   `docker compose exec app php bin/console app:admin:grant you@example.org`
2. **Sign in** at `/login` with that email (one-time link; in development it arrives in Mailpit at http://localhost:8025).
3. **Community & branding** (`/admin/community`): name, subtitle, city/state, time zone, event type (Halloween is the one this version offers), event name (shown as "<community> <event name>", default: the type's name), event date and suggested hours, emoji or logo (SVG/PNG/JPEG/WebP ≤ 512 KB), colours, headline, features, optional zones (any names — phases, sections, "Lakefront" — or none), crossing/safety notes.
4. **Boundary & zones** (`/admin/community/map`):
   - Import a reviewed GeoJSON file (official GIS, or drawn at https://geojson.io on a real map), or draw polygons directly (choose "Neighborhood boundary" or a zone before drawing).
   - Separate areas stay separate; never bridge lakes or major roads.
   - Save. The server rejects malformed rings, self-intersections, `[lat, lon]` mix-ups and unknown zones, and warns about overlapping zones.
   - Compare the outline with real streets, property edges, gates and zone edges, tick the review box and **Approve boundary**. Listings open only after approval.
5. **Addresses** (`/admin/addresses`): import a CSV with `address,latitude,longitude,zone_key` (dry run first), or add addresses one by one. Coordinates must come from a verified source; nothing is geocoded. Alternatively allow resident self-entry with a street allow-list in settings (residents place a pin; the server checks street, boundary, zone and collisions).
6. **Email**: set `MAILER_DSN`/`MAIL_FROM` to an authenticated relay and `ADMIN_EMAIL` to the inbox that receives review requests. Send yourself a sign-in link to test deliverability.
7. **Open listings**: Overview → "Allow house listings" (also requires the approved boundary).
8. **After the event**: change the event date for next year (old listings disappear from the public map until owners save again) or run `bin/console app:season:archive`.

Exporting the current configuration for backup or redeploy: `docker compose exec app php bin/console app:community:export /app/var/export` (writes `community.yaml` + `geometry.geojson`, re-importable with `app:community:bootstrap --force`).
