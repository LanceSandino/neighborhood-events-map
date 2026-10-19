#!/bin/bash
# Take an immediate PostgreSQL backup (custom format) into ${BACKUP_DIR:-./backups}.
# Usage: bin/backup-now.sh [extra docker compose args, e.g. --env-file .env.prod]
set -euo pipefail
cd "$(dirname "$0")/.."
stamp=$(date -u +%Y%m%dT%H%M%SZ)
docker compose "$@" exec -T backup sh -c "umask 077 && pg_dump --format=custom --no-owner --exclude-table-data=sessions --file=/backups/halloween-$stamp.dump && ls -l /backups/halloween-$stamp.dump"
echo "Backup written: halloween-$stamp.dump (inside the backup volume/directory)"
