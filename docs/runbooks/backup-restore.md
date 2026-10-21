# Backup and restore

- The `backup` service runs `pg_dump --format=custom` once at start (after the app is healthy) and then every `BACKUP_INTERVAL_SECONDS` (default 24 h) into `${BACKUP_DIR:-./backups}`, deleting dumps older than `BACKUP_RETENTION_DAYS` (default 14). Each dump is written to a hidden `.halloween-….dump.partial` file and renamed when complete; a partial file left by a stop or crash is removed after an hour.
- Take one now: `bin/backup-now.sh` (add your compose args, e.g. `--env-file .env`).
- Restore (destructive, asks for confirmation): `bin/restore.sh halloween-YYYYMMDDTHHMMSSZ.dump` (a plain file name from the backup directory; the `halloween-` prefix is kept for existing installs, D-20261021-18). It saves the current account ledger, stops nginx, maintenance and the app, restores, starts the app (pending migrations run), re-applies the ledger (`app:ledger:replay`) and only then starts nginx. Accounts deleted and organizer roles changed after the backup therefore stay deleted and changed (D-20261021-28). If the app cannot be reached before the restore (a lost database), only the backup's own ledger is re-applied: redo later deletions by hand.
- Copy backups off the host (e.g. rsync to another machine). Uploaded logos live in the `branding` volume; re-upload or back up `docker run --rm -v <project>_branding:/b alpine tar c -C /b .`.

## Restore drill (performed 2026-10-19 on the production-mode stack)

1. Loaded a real community configuration with several hundred official county address points; recorded counts.
2. `bin/backup-now.sh` → 77 KB dump.
3. `docker compose down` and **deleted the PostgreSQL volume**; started again (empty database, migrations only).
4. `bin/restore.sh <dump>` → counts identical (addresses with the same per-zone distribution, geometry rows, users, audit events), `/healthz` ok, migrations current.

## Ledger drill (performed 2026-10-21 on a development stack)

1. Granted the organizer role to two test accounts, then `bin/backup-now.sh`.
2. Deleted one account (`app:user:delete … --confirm`) and revoked the other (`app:admin:grant … --revoke`).
3. `bin/restore.sh <that dump>` → "Ledger re-applied: 2 entries merged, 1 account(s) and 0 review request(s) erased again, 1 organizer role(s) corrected"; the deleted account was absent and the other was no longer an organizer; `/healthz` ok.

Repeat the drill before each event.
