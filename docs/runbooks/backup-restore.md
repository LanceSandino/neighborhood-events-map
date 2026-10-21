# Backup and restore

- The `backup` service runs `pg_dump --format=custom` once at start (after the app is healthy) and then every `BACKUP_INTERVAL_SECONDS` (default 24 h) into `${BACKUP_DIR:-./backups}`, deleting dumps older than `BACKUP_RETENTION_DAYS` (default 14).
- Take one now: `bin/backup-now.sh` (add your compose args, e.g. `--env-file .env`).
- Restore (destructive, asks for confirmation; stops app and nginx, restores, restarts): `bin/restore.sh halloween-YYYYMMDDTHHMMSSZ.dump` (a plain file name from the backup directory; the `halloween-` prefix is kept for existing installs, D-20261021-18).
- Copy backups off the host (e.g. rsync to another machine). Uploaded logos live in the `branding` volume; re-upload or back up `docker run --rm -v <project>_branding:/b alpine tar c -C /b .`.

## Restore drill (performed 2026-10-19 on the production-mode stack)

1. Loaded a real community configuration with several hundred official county address points; recorded counts.
2. `bin/backup-now.sh` → 77 KB dump.
3. `docker compose down` and **deleted the PostgreSQL volume**; started again (empty database, migrations only).
4. `bin/restore.sh <dump>` → counts identical (addresses with the same per-zone distribution, geometry rows, users, audit events), `/healthz` ok, migrations current.

Repeat the drill before each event.
