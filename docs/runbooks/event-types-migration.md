# Event migrations: upgrade to 0.11, 0.15, 0.16, 0.17 and 0.18 and both rollback paths

0.11.0-beta.1 gives every event its own listings (ROADMAP Stage 2, [DECISIONS](../../DECISIONS.md) D-20261031-05 to
-14). This runbook says what the upgrade changes in the database, how to check it, and how to go back — with the
image only (the database is kept) or with the backup taken before the upgrade. The drill script runs all of it on a
throwaway stack; run it before upgrading a real installation.

0.12, 0.13 and 0.14 add no migration; 0.15 adds one (several published events), 0.16 one (per-event branding) and
0.17 one (hidden events, below). An installation still on 0.9.0-beta.1 that upgrades straight to the latest release
runs `Version20261031000001` (0.10, `listings.details`), `Version20261031000002` (0.11, event-scoped listings),
`Version20261031000003` (0.15, several events), `Version20261031000004` (0.16, per-event branding) and
`Version20261101000001` (0.17, hidden events) in that order when the app starts; everything
below applies, and the drill starts from the published 0.9.0-beta.1 images for exactly that path. Set `APP_IMAGE_TAG`
to the release you deploy wherever this page says 0.11.0-beta.1.

## 0.18: events close by themselves

0.18.0-beta.1 has **no migration** ([D-20261104-01](../../DECISIONS.md)). What changes on the upgrade is what happens at
start: after the migrations and the community bootstrap, `app:events:close-ended` closes every open event whose last day
has passed in the community's time zone. So an install on 0.9 whose Halloween is over goes, in one start:

1. the five event migrations, in order (the Halloween becomes the open default event, with its homes and branding);
2. the Halloween closes, as an organizer's Close does: next year's event is created **hidden**, the audit log shows `cli`,
   "/" shows the thank-you message (or moves to a current event, if one exists), and it is listed under Past events.

**Rollback.** Nothing else changes for either path. Path (a): the legacy `listings` rows and the settings block keep
mirroring the closed Halloween, so the 0.9 image shows the same homes and headline. Writes on 0.9 reach the closed
Halloween as before, and going forward again closes nothing twice. Path (b) is unchanged: restoring the pre-upgrade backup
on 0.9 brings back the open Halloween's data, and upgrading again closes it again. Hold the close with `EVENTS_AUTO_CLOSE=0`
if you want to compare first. The drill's steps 8 to 10 script all of this, but they had not been run when this was written:
read [the test report](../quality/test-report.md) before relying on it. They are written not to depend on the day they run: the
demo's Halloween (October 30, 2026) is past on any date from now on, and no other step compares with today.

**Limit of path (a).** An event's own access (`settings.access`, [D-20261104-05](../../DECISIONS.md)) is new: 0.9 reads only
the community's stored `community.access`, which an event never changes, so after an image rollback 0.9 shows the
community's access even for a Halloween with its own.

## 0.17: hidden events

0.17.0-beta.1 adds `Version20261101000001` ([D-20261101-01](../../DECISIONS.md)). It is additive and changes no row:

- **Schema.** The state check allows `hidden` (draft → hidden → upcoming → open → closed → archived), and
  `uniq_current_event_per_type` counts hidden events with upcoming and open ones (one current event per type).
  `event_occurrences_default_public` already refuses a hidden default.
- **Data.** None changes: every upcoming event stays upcoming and visible, so visitors see exactly what they saw on 0.16.
  From now on, closing an event creates next year's as hidden.
- **0.9 image.** It reads neither table (only the settings block and the legacy `listings` table), and the bridge
  never mirrors a hidden event; path (a) is unchanged.

Check after the upgrade:

```bash
docker compose exec postgres psql -U halloween -d halloween -c \
  "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'event_occurrences_state_check'"
docker compose exec postgres psql -U halloween -d halloween -c \
  "SELECT id, type, name, state, is_default FROM event_occurrences ORDER BY id"
```

**Rollback.** Path (a) and path (b) below work as before (the drill checks next year's event stays hidden across the
image rollback and is announced afterwards). To leave 0.17's schema before going back to 0.15 or 0.16 (path (a) to
0.9 does not need it): `docker compose exec app php bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261031000004' -n`
makes every hidden event a draft (organizers only; its listings stay) and puts the 0.16 check and index back.

## 0.16: per-event branding

0.16.0-beta.1 adds `Version20261031000004` ([ADR 0012](../adr/0012-community-and-event-branding.md), D-20261031-46 to
-51). It changes data only — no table or column — and keeps what it copies:

- **What moves.** The community's branding values that differ from every type's built-in default (emoji, headline, its
  accent part, intro, the two colours) and its About text are copied into `settings.branding` of every Halloween event
  that has none (open, closed, archived, upcoming and drafts alike). Values equal to a built-in default are not copied:
  the event shows its own type's default for them, exactly as 0.15 showed them. Christmas lights and sales get nothing
  and show their type's look.
- **What stays.** The community settings keep `branding.brand_emoji`, `headline`, `headline_accent`, `intro`,
  `primary_color`, `accent_color` and `community.about` under those names: the 0.9.0-beta.1 image reads them. 0.16
  writes the Halloween the 0.9 image shows (the newest open or closed one-day Halloween, else the default event) into
  them on every settings save and every change of the events, and never shows them for an event. The community's own
  general About text is a new key, `community.general_about` (empty after the upgrade).
- **Halloween looks exactly as before**; `tests/Integration/EventBrandingMigrationTest.php` compares its pages with what
  0.15.0-beta.1 rendered for the same data.

Check after the upgrade:

```bash
docker compose exec postgres psql -U halloween -d halloween -c \
  "SELECT id, type, name, state, settings->'branding' AS branding FROM event_occurrences ORDER BY id"
docker compose exec postgres psql -U halloween -d halloween -c \
  "SELECT settings->'branding'->>'headline' AS headline_for_09, settings->'community'->>'about' AS about_for_09 FROM community_settings"
```

Every Halloween shows your own branding values (only the ones you had changed); the community keys still hold them.

**Rollback.** Path (a) below with the 0.9.0-beta.1 images shows the mirrored Halloween's branding (the drill checks the
0.9 page shows the custom headline after the image rollback). Branding changed on 0.9 (Community → Branding there) is
replaced by the events' own when 0.16 runs again. To leave 0.16's data before going back to 0.15 (a restore is still
the way to 0.10–0.14): `docker compose exec app php bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20261031000003' -n`
writes the community keys and About text from the mirrored Halloween and removes every event's branding and
`community.general_about`.

## 0.15: several published events

0.15.0-beta.1 adds `Version20261031000003` ([ADR 0011](../adr/0011-several-published-events.md), D-20261031-37 to -45).
An installation on 0.9.0-beta.1 that upgrades straight to 0.15 runs `Version20261031000001` (0.10),
`Version20261031000002` (0.11) and `Version20261031000003` (0.15) in that order when the app starts. The 0.15 step is
additive and lossless: no row is deleted and every listing stays with its event.

- **States.** The `active` event becomes `open`; drafts and archived events stay as they are. The one-active index
  goes; `uniq_current_event_per_type` allows one upcoming or open event per type and `uniq_default_event` one default.
- **Default.** The open event becomes the default (`is_default`), so "/" shows exactly what it showed before.
- **Dates.** `dates_tentative` (false for every migrated event) and dates to be announced (both dates null; none after
  the migration); `closed_at`.
- **Bridge.** The reverse trigger now writes to the newest open or closed one-day Halloween (right after the upgrade:
  the same event as before). The stored settings block mirrors that Halloween.

Check after the upgrade:

```bash
docker compose exec postgres psql -U halloween -d halloween -c \
  "SELECT id, type, name, starts_on, state, is_default FROM event_occurrences ORDER BY starts_on"
curl -s https://<host>/api/events | python3 -m json.tool
```

The open Halloween is `open` and the default; `/api/homes` shows the same homes as before.

**Rollback targets after 0.15.** Path (a) below works with the **0.9.0-beta.1** images (what production runs before
this upgrade): 0.9 reads only the settings block and the legacy table. The 0.10–0.14 images read `event_occurrences`
but not the new states — they find no `active` event and show "No event on the map right now" — so to return to one of
them use path (b), the restore of the backup taken before the upgrade (D-20261031-41).

## What the 0.11 upgrade changes

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
# in .env: APP_IMAGE_TAG=0.9.0-beta.1   (after 0.15 the only image-rollback target; before 0.15 also 0.10.0-beta.1)
docker compose pull && docker compose up -d --force-recreate --wait
```

What happens: the older image starts its migrations, reports `You have N previously executed migrations in the
database that are not registered migrations`, does nothing else and starts. It reads the settings and the legacy
`listings` table, which 0.11 kept in step: the same event, date and homes. Its writes reach the event tables through
the trigger, so going forward again (`APP_IMAGE_TAG=0.11.0-beta.1`, the same two commands) keeps them.

Limits of path (a):

- Only one Halloween is visible to the older image: since 0.15 the newest open or closed one-day Halloween (before
  0.15, the active one). Upcoming events (next year's Halloween with residents' listings for it), drafts, archived
  events and events of other types are not; nothing about them changes while it runs, and a resident's listing for an
  upcoming Halloween never reaches the legacy table (it would overwrite this year's row of the same address).
- After 0.15, use the 0.9.0-beta.1 images for path (a); the 0.10–0.14 images show no event (see above), use path (b).
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
3. upgrades to the given images with automatic closing off (`EVENTS_AUTO_CLOSE=0`, so an organizer can work with the
   open Halloween; steps 8 to 10 run as production does; every startup migration runs, up to
   `Version20261101000001`) and checks that `/api/homes` shows the same homes, keys and values and that the Halloween is
   the open default event;
4. signs a fictional resident in (real proof-of-work, link from the drill's Mailpit), edits a listing and lists a new
   self-entered home (`scripts/drill_listing.py`);
   4a. (per-event branding) checks the Halloween kept the demo's custom headline ("Spooky streets.", now its own), an
   organizer opens Christmas lights next to it — they show "Holiday nights. Bright lights.", not Halloween's — and
   changes the Halloween's headline, which also reaches the keys the 0.9 image reads; each event gets its own access, and
   the community's stored value stays;
   4b. (several events) an organizer closes the Halloween — next year's is created hidden on October 31: not in
   `/api/events`, 404 on `/api/event?e=`, not named by the thank-you page; the default moves to the open lights —, the
   resident lists the home for next year, and the legacy row keeps the closed Halloween's listing;
5. path (a): rolls the images back to `0.9.0-beta.1`, checks it starts, reports the newer migrations and shows the
   bridged homes (the closed Halloween's, with the edit and the new home), the Halloween's custom headline (the one
   changed in step 4a) and the community's own access; a resident edits on 0.9;
6. goes forward again and checks the edit made on 0.9 reached the closed Halloween, next year's listing is untouched
   and its event still hidden; then an organizer announces it, `/api/events` lists it as upcoming and the thank-you
   card links it;
7. path (b): restores the pre-upgrade backup with `bin/restore.sh` on `0.9.0-beta.1` and checks the homes are exactly
   the pre-upgrade ones and the event tables are gone;
8. upgrades the restored database as production does, automatic closing on: the past-dated Halloween closes at start
   (audited as `cli`), next year's is hidden, "/" shows the thank-you page with the custom headline (moved again) and it is
   listed under Past events;
9. path (a) again: images back to `0.9.0-beta.1`, the same homes and headline as before the upgrade;
10. forward again: nothing closes twice, and the maintenance loop finds nothing to do.

The latest result is in [docs/quality/test-report.md](../quality/test-report.md).
