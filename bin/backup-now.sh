#!/bin/bash
# Take an immediate PostgreSQL backup (custom format) into ${BACKUP_DIR:-./backups}.
# Usage: bin/backup-now.sh [extra docker compose args, e.g. --env-file .env.prod]
set -euo pipefail
cd "$(dirname "$0")/.."
stamp=$(date -u +%Y%m%dT%H%M%SZ)
# Written to a hidden .partial file first (as the daily loop does), so an interrupted run never leaves a
# truncated file that looks like a backup; the daily loop removes such leftovers after an hour.
docker compose "$@" exec -T backup sh -c "umask 077 && t=/backups/.halloween-$stamp.dump.partial \
  && if pg_dump --format=custom --no-owner --exclude-table-data=sessions --file=\$t; then mv \$t /backups/halloween-$stamp.dump; else rm -f \$t; exit 1; fi \
  && ls -l /backups/halloween-$stamp.dump"
echo "Backup written: halloween-$stamp.dump (inside the backup volume/directory)"
