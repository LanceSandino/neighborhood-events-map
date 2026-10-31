# Event-scoped listings: upgrade to 0.11 and both rollback paths

0.11.0-beta.1 gives every event its own listings (ROADMAP Stage 2, [DECISIONS](../../DECISIONS.md) D-20261031-05 to
-14). This runbook says what the upgrade changes in the database, how to check it, and how to go back — with the
image only (the database is kept) or with the backup taken before the upgrade. The drill script runs all of it on a
throwaway stack; run it before upgrading a real installation.

0.12, 0.13 and 0.14 add no migration. An installation still on 0.9.0-beta.1 that upgrades straight to the latest
release runs `Version20261031000001` (0.10, `listings.details`) and `Version20261031000002` (0.11, event-scoped
listings) in that order when the app starts; everything below applies unchanged, and the drill starts from the
published 0.9.0-beta.1 images for exactly that path. Set `APP_IMAGE_TAG` to the release you deploy wherever this page
says 0.11.0-beta.1.

## What the upgrade changes

The app runs `doctrine:migrations:migrate` when it starts. 0.11 adds one migration, `Version20261031000002`. It is
additive: nothing is dropped or rewritten, and the legacy `listings` table stays.

- **New tables.** `event_occurrences` (one row per event: type, name, first and last day, default hours, settings,
  `draft` → `active` → `archived`; a partial unique index allows only one active row), `event_listings` (one per event
  and address; `address_id` references `claims`, so giving a home up or deleting an account removes its listings of
  every event, as before) and `event_listing_windows` (the days and hours a listing takes part in; a window ends on the
  day it starts).
- **Backfill.** One Halloween event per distinct `listings.event_date`, named and timed from the community settings.
  The date in Community → Event becomes the **active** event, earlier dates are **archived**, later dates **drafts**.
  Every `listings` row is copied with its details, status, note, publication, moderation and timestamps, plus one
  window (its date and hours). Without any listing, the configured event still becomes the active one.
- **Bridge, app → old table.** While the active event is a one-day Halloween event, every write to its listings also
  writes the legacy `listings` row of that address with the values 0.10 would have written. Archiving an event
  unpublishes its day's legacy rows; deleting an archived event's listings deletes them.
- **Bridge, old table → app.** A trigger (`listings_bridge_to_events`) copies writes that an older image makes to
  `listings` (after an image-only rollback) into the active Halloween event: edits, new listings, hides, releases. A
  row moved off the event day, or deleted, leaves the event. The app's own mirror writes are not copied back.
- **Settings.** The `event` block of the community settings becomes a view of the active event and is kept in step,
  so an older image, which reads only the settings, shows the same event and date.

Check after the upgrade (from the checkout, `docker compose` arguments as you use them):

```bash
docker compose exec postgres psql -U halloween -d halloween -c \
  "SELECT id, type, name, starts_on, state FROM event_occurrences ORDER BY starts_on"
docker compose exec postgres psql -U halloween -d halloween -c \
  "SELECT (SELECT COUNT(*) FROM listings) AS legacy, (SELECT COUNT(*) FROM event_listings) AS event_listings"
curl -s https://<host>/api/homes | python3 -c 'import json,sys; print(len(json.load(sys.stdin)["homes"]), "public homes")'
```

The two counts are equal right after the upgrade, and the public homes are the ones the map showed before.

## Before you upgrade

1. `bin/backup-now.sh` and copy the dump off the host. Note its name: path (b) needs it.
2. Run the drill once on the images you are about to deploy (below).
3. Upgrade as usual ([upgrade-rollback.md](upgrade-rollback.md)): set `APP_IMAGE_TAG=0.11.0-beta.1`, then
   `docker compose pull && docker compose up -d --force-recreate --wait`.

## Path (a): image-only rollback, database kept

Use it when 0.11 misbehaves and you want the previous release back with everything residents did since the upgrade.

```bash
# in .env: APP_IMAGE_TAG=0.10.0-beta.1   (or 0.9.0-beta.1)
docker compose pull && docker compose up -d --force-recreate --wait
```

What happens: the older image starts its migrations, reports `You have N previously executed migrations in the
database that are not registered migrations`, does nothing else and starts. It reads the settings and the legacy
`listings` table, which 0.11 kept in step: the same event, date and homes. Its writes reach the event tables through
the trigger, so going forward again (`APP_IMAGE_TAG=0.11.0-beta.1`, the same two commands) keeps them.

Limits of path (a):

- Only the active one-day Halloween event is visible to the older image. Drafts, archived events and events of other
  types are not; nothing about them changes while it runs.
- Organizer → Events does not exist there. Do not start the next season the 0.9 way (changing the date under
  Community → Event): when 0.11 runs again the active event is still the one 0.11 knew, and listings the older image
  saved for the other date stay only in the legacy table. Go forward first, then use Organizer → Events.
- Name or hours changed under Community → Event on the older image are replaced by the event's own values when 0.11
  runs again (the event is the source of truth).
- A listing moved to another day or deleted by hand in SQL leaves the active event (the trigger keeps the two tables
  equal for the event day).

Optional, while 0.11 still runs: drop the new tables before switching images,
`docker compose exec app php bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261031000001' -n`.
That loses drafts, archived events and listings of other types (the legacy rows stay); path (a) does not need it.

## Path (b): full restore of the pre-upgrade backup

Use it when the data itself must go back to the moment before the upgrade. Everything written since the backup is
lost — listings, claims, new accounts, audit events — except account deletions and organizer-role changes, which
`bin/restore.sh` re-applies from the account ledger (D-20261021-28).

```bash
# in .env: APP_IMAGE_TAG=0.10.0-beta.1   (the release you upgraded from)
docker compose pull && docker compose up -d --force-recreate --wait
bin/restore.sh halloween-YYYYMMDDTHHMMSSZ.dump      # type RESTORE
curl -s http://127.0.0.1:${HTTP_PORT:-8087}/healthz
```

`bin/restore.sh` replaces the whole database schema with the backup's in one transaction, so the event tables, their
trigger and anything else a newer release added go with it, and a restore that fails half-way changes nothing.
Restoring a pre-upgrade backup while 0.11 is still the configured image also works: the app runs the migrations again
on start and the result is the same as the first upgrade.

## Erasure, release and date boundaries

- **Erasure and release.** `event_listings.address_id` references `claims` with `ON DELETE CASCADE`: a resident giving a
  home up, an organizer's unclaim and `app:user:delete` remove that address's listings of every event, archived ones
  included, and the legacy row; a transfer keeps them with the address
  (`tests/Integration/EventTypesAcceptanceTest.php`). The same holds after an image-only rollback, because the older
  image deletes from `claims` too. After a restore (path b), `bin/restore.sh` re-applies account deletions from the
  ledger, so an erased account and its listings do not come back.
- **Date boundaries.** A listing's windows are local days in the community's time zone and end on the day they start
  (no window past midnight). The display status is derived from them on the server and in the browser by the same
  rules, tested on one fixture (48 cases: daylight-saving days, December 31 into January 1, month ends). The backfill
  keeps each `listings.event_date` as one event, so seasons of earlier years stay apart.

## The drill

`bin/drill-event-upgrade.sh` runs every step on a disposable Compose project (its own name, port 8198 and Mailpit on
8197 by default) with the fictional Maple Hollow demo, and always removes it with `down -v`:

```bash
docker build --target prod -t nhm-app:drill . && docker build --target nginx -t nhm-nginx:drill .
bin/drill-event-upgrade.sh nhm-app nhm-nginx drill
```

1. starts the **published** `0.9.0-beta.1` images and checks the demo's homes;
2. takes a backup with `bin/backup-now.sh`;
3. upgrades to the given images (the startup migrations run) and checks that `/api/homes` shows the same homes, keys
   and values;
4. signs a fictional resident in (real proof-of-work, link from the drill's Mailpit), edits a listing and lists a new
   self-entered home (`scripts/drill_listing.py`);
5. path (a): rolls the images back to `0.9.0-beta.1`, checks it starts, reports the newer migrations and shows the
   bridged homes (the edit and the new home); a resident edits on 0.9;
6. goes forward again and checks the edit made on 0.9 reached the event tables;
7. path (b): restores the pre-upgrade backup with `bin/restore.sh` on `0.9.0-beta.1` and checks the homes are exactly
   the pre-upgrade ones and the event tables are gone;
8. upgrades the restored database once more and checks the homes again.

The latest result is in [docs/quality/test-report.md](../quality/test-report.md).
