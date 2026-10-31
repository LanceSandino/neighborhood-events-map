#!/bin/bash
# Restore a backup made by bin/backup-now.sh or the daily backup loop.
# DESTRUCTIVE: replaces the current database (its whole schema, in one transaction) with the backup's. Stops the app
# first, restores, restarts. A backup taken before an upgrade restores cleanly after it (newer tables go too).
# Accounts deleted and organizer roles changed after the backup stay deleted and changed: the current account
# ledger (ids and times only) is saved first and re-applied to the restored database (app:ledger:replay).
# Usage: bin/restore.sh <backup-file-name> [extra docker compose args]
set -euo pipefail
cd "$(dirname "$0")/.."
file="${1:?usage: bin/restore.sh halloween-YYYYMMDDTHHMMSSZ.dump [compose args]}"
shift
# The name is used inside "sh -c" in the backup container: plain file names only.
if ! [[ "$file" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*\.dump$ ]]; then
  echo "Not a backup file name: $file (expected e.g. halloween-YYYYMMDDTHHMMSSZ.dump from the backup directory)" >&2
  exit 2
fi
read -r -p "Restore $file over the CURRENT database? Type RESTORE to continue: " answer
[ "$answer" = "RESTORE" ] || { echo "Aborted."; exit 1; }
docker compose "$@" exec -T backup test -f "/backups/$file" || { echo "No such backup in the backup directory: $file" >&2; exit 2; }

ledger=$(umask 077 && mktemp)
trap 'rm -f "$ledger"' EXIT
if ! docker compose "$@" exec -T app php bin/console app:ledger:export > "$ledger"; then
  echo "WARNING: could not read the current account ledger (is the app running?). Only the backup's own ledger" >&2
  echo "         will be re-applied: accounts deleted or organizers changed after the backup must be redone by hand." >&2
  : > "$ledger"
fi

docker compose "$@" stop nginx maintenance app
# The backup replaces the whole schema in ONE transaction: tables, functions and triggers that a newer release added
# (and that reference the backup's tables) go with it, so a backup from before an upgrade restores cleanly, and a
# restore that fails half-way changes nothing (the database stays as it was; the app stays stopped).
docker compose "$@" exec -T backup sh -c "set -e; umask 077; work=\$(mktemp -d); trap 'rm -rf \"\$work\"' EXIT; \
  pg_restore --no-owner --file=\"\$work/restore.sql\" '/backups/$file'; \
  { echo 'DROP SCHEMA public CASCADE;'; grep -qx 'CREATE SCHEMA public;' \"\$work/restore.sql\" || echo 'CREATE SCHEMA public;'; cat \"\$work/restore.sql\"; } > \"\$work/all.sql\"; \
  psql -X -q -v ON_ERROR_STOP=1 --single-transaction --dbname=\"\$PGDATABASE\" -f \"\$work/all.sql\" >/dev/null"
# The app runs pending migrations when it starts; re-apply the ledger before nginx lets anyone in.
docker compose "$@" start app
for _ in $(seq 1 60); do
  docker compose "$@" exec -T app app-healthcheck >/dev/null 2>&1 && break
  sleep 2
done
docker compose "$@" exec -T app php bin/console app:ledger:replay --merge=- < "$ledger"
# Development stacks run maintenance only on demand (a profile), so it may have no container to start.
if [ -n "$(docker compose "$@" ps -a -q maintenance 2>/dev/null)" ]; then docker compose "$@" start maintenance; fi
docker compose "$@" start nginx
echo "Restored $file. Check: curl -s http://127.0.0.1:\${HTTP_PORT:-8087}/healthz"
