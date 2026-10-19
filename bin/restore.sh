#!/bin/bash
# Restore a backup made by bin/backup-now.sh or the daily backup loop.
# DESTRUCTIVE: replaces the current database contents. Stops the app first, restores, restarts.
# Usage: bin/restore.sh <backup-file-name> [extra docker compose args]
set -euo pipefail
cd "$(dirname "$0")/.."
file="${1:?usage: bin/restore.sh halloween-YYYYMMDDTHHMMSSZ.dump [compose args]}"
shift
read -r -p "Restore $file over the CURRENT database? Type RESTORE to continue: " answer
[ "$answer" = "RESTORE" ] || { echo "Aborted."; exit 1; }
docker compose "$@" stop app nginx
docker compose "$@" exec -T backup sh -c "test -f /backups/$file && pg_restore --clean --if-exists --no-owner --dbname=\"\$PGDATABASE\" /backups/$file"
docker compose "$@" start app nginx
echo "Restored $file. Check: curl -s http://127.0.0.1:\${HTTP_PORT:-8087}/healthz"
