# Upgrade and rollback

## Upgrade

1. Read `CHANGELOG.md` for the target version (migration notes, breaking changes).
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
