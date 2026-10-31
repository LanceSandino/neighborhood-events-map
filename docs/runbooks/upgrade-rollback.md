# Upgrade and rollback

## Upgrade

1. Read the target version's notes below ([Notes for each release](#notes-for-each-release)) for what the upgrade does to the database and how to go back; `CHANGELOG.md` says what residents and organizers will notice.
2. `bin/backup-now.sh` and copy the dump off the host.
3. Update the compose files and pin the release: `git fetch --depth 1 origin tag vX.Y.Z && git checkout vX.Y.Z`, then set `APP_IMAGE_TAG=X.Y.Z` in `.env`.
4. `docker compose pull && docker compose up -d --force-recreate --wait` (migrations run on start; from source: `docker compose up -d --build --force-recreate --wait`). Recreating the containers is what clears PHP's OPcache: production never re-checks code files (`opcache.validate_timestamps=0`, fastest), so a new container is the only way new code runs. Never copy files into a running container.
   - Changed only `.env` or PHP settings? `docker compose up -d --force-recreate app maintenance` does the same without a rebuild.
   - Check the new version is live: the footer shows it, or `curl -s https://<host>/healthz`.
5. Check `/healthz`, the public map, organizer sign-in, one listing edit.

## PostgreSQL major upgrade (for example 17 → 18)

A new PostgreSQL major version cannot open the previous one's data files, so a release that moves to one (see its changelog) is installed through a backup. `bin/upgrade-postgres.sh` does every step and checks each one:

1. Update the checkout and `.env` as in steps 2–3 above, but **do not** run `docker compose up` yet. The old containers keep running.
2. Pull or build the new images: `docker compose pull` (or `docker compose build`).
3. `bin/upgrade-postgres.sh` (add your compose arguments). It asks for confirmation, then:
   - stops nginx, maintenance and the app;
   - dumps the old database into the backup directory and records every table's row count;
   - starts the new PostgreSQL on its own empty volume (`postgres-data`) and restores the dump;
   - compares the row counts table by table, and starts nothing if they differ;
   - starts the whole stack.
4. Check `/healthz`, the public map and an organizer sign-in.

The old data stays untouched in the `pgdata` volume. To roll back, check out the previous release and set its `APP_IMAGE_TAG` again: its compose file uses that volume, so `docker compose up -d` brings the old database back as it was before the upgrade. Once you are sure, remove it: `docker volume rm <project>_pgdata`.

## Rollback

- Code-only change (no new migration): `git checkout <previous tag>`, set `APP_IMAGE_TAG` back to that version, then `docker compose pull && docker compose up -d --force-recreate --wait`. Older images stay in the registry, so a rollback never needs a build.
- With a schema migration: either run the down migration before switching code (`docker compose exec app php bin/console doctrine:migrations:migrate prev`) or restore the pre-upgrade backup with `bin/restore.sh`, then deploy the previous tag.
- 0.11.0-beta.1 (event-scoped listings) → 0.10.0-beta.1 or 0.9.0-beta.1: the image alone (the database is kept and stays in step for the older image) or the pre-upgrade backup with `bin/restore.sh` — commands, what each keeps and their limits are in [event-types-migration.md](event-types-migration.md), and `bin/drill-event-upgrade.sh` runs both on a throwaway stack.
- 0.10.0-beta.1 → 0.9.0-beta.1 needs only the image: its migration is additive (`listings.details` plus a trigger that fills it from the old columns, D-20261031-03). The 0.9 image starts with a warning about one newer executed migration ("Already at the latest version") and reads and writes the old columns, which 0.10 keeps up to date; when 0.10 runs again, the trigger has kept `details` in step with whatever 0.9 wrote. Running the down migration as well is possible but not needed.

CI verifies every migration on a blank database up → down to zero → up again.

## Notes for each release

What each release needs from whoever runs the site. `CHANGELOG.md` (and the app's "What's new") is written for
residents and organizers and leaves this out; the engineering records are in `CHANGES.md`.

### 0.16.0-beta.1 (per-event branding, ADR 0012)

- Pull `0.16.0-beta.1` and recreate the stack; back up first. One data-only migration runs at start,
  `Version20261031000004` (no schema change): the community's own Halloween branding (emoji, headline, accent part,
  intro, colours — only values that differ from a built-in default) and its About text are copied into every Halloween
  event's settings (`settings.branding`). Christmas lights and sales then show their type's look. An install on 0.9
  runs `Version20261031000001` to `…04` in order.
- The community settings keep the old branding keys and `community.about` for the 0.9.0-beta.1 image: this release
  writes the Halloween that image shows (the newest open or closed one-day Halloween, else the default event) into them
  whenever events or settings change, and never shows them for an event. After an image-only rollback to 0.9 the map
  shows that Halloween's branding; branding changed on 0.9 is replaced by the events' own when this release runs again.
- Down (`doctrine:migrations:migrate 'DoctrineMigrations\Version20261031000003'`) writes those keys from the same
  Halloween and removes every event's branding and the community's general About text (`community.general_about`).
- The sign-in email subject no longer names an event ("Your <community> sign-in link"); claim emails add the event and
  the address; review requests name the event of the page they were sent from. `MAIL_FROM` is unchanged.
- Checks and both ways back: [event-types-migration.md](event-types-migration.md#016-per-event-branding); the drill
  checks the 0.9 page shows the custom Halloween headline after an image-only rollback.

### 0.15.0-beta.1 (several events at once, ADR 0011)

- Back up, pull `0.15.0-beta.1`, recreate the stack. One migration runs at start, `Version20261031000003` (an install on
  0.9 runs those of 0.10, 0.11 and this release in order): the event on the map stays on the map and becomes the default
  event, so visitors see what they saw before.
- After this release the image-only way back is to 0.9.0-beta.1 only: it shows one Halloween (the newest open or closed
  one). The 0.10–0.14 images do not know the new states; going back to them needs the backup taken before the upgrade.
- The public event (`/api/event`, `/api/community`) adds `state`, `dates_tentative`, `is_default` and, once closed,
  `closing_message`; `/api/events` lists the public events. Public homes keep exactly their fields.

### 0.14.0-beta.1

- Pull `0.14.0-beta.1` and recreate the stack. No migration; an install on 0.9 or 0.10 runs the event migrations of
  0.10 and 0.11 in order when it starts (back up first; [event-types-migration.md](event-types-migration.md)).
- Reference configurations for each kind of event: `examples/reference/`; organizer guide:
  [organizer-events.md](organizer-events.md).
- Layout details changed in this release: Larger text on phones 380–440 px wide, Halloween's filter buttons stay 40 px
  high (44 px on the other types), the route notices of the lights and the sale use their own tokens instead of
  Halloween's peach.

### 0.13.0-beta.1

- Pull `0.13.0-beta.1` and recreate the stack. No migration. Going back to 0.12.0-beta.1 needs only the older image
  while a Halloween or Christmas lights event is on the map; a garage sale is not shown by older versions (they show
  "No event on the map right now").
- `community.yaml` may set the first event's settings (`event.settings`), for example a sale's days, categories and
  rules (D-20261031-29).

### 0.12.0-beta.1

- Pull `0.12.0-beta.1` and recreate the stack. No migration. Going back to 0.11.0-beta.1 needs only the older image while
  a Halloween event is on the map; a Christmas lights season is not shown by older versions.

### 0.11.0-beta.1

- Back up, pull `0.11.0-beta.1`, recreate the stack. One additive migration runs at start (`Version20261031000002`):
  every event becomes a row of its own and each listing belongs to one event; the current Halloween and its homes carry
  over unchanged. Going back to 0.10.0-beta.1 or 0.9.0-beta.1 needs only the older image (the database keeps working
  with it), or the pre-upgrade backup ([event-types-migration.md](event-types-migration.md)).
- `/api/event` and each home in `/api/homes` add the event they belong to, the home's days and hours and its status.
- `bin/restore.sh` replaces the whole database in one step: a backup taken before an upgrade restores cleanly, and a
  restore that fails changes nothing (D-20261031-15).

### 0.10.0-beta.1

- Pull `0.10.0-beta.1` and recreate the stack. One additive migration runs at start (`Version20261031000001`,
  `listings.details`); going back to 0.9.0-beta.1 needs only the older image.
- `/api/event` names the event type. A listing's details are stored in `listings.details` next to the old columns,
  which are kept and updated too, so the earlier version still reads them (D-20261031-03).
