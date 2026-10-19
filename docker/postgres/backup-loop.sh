#!/bin/sh
# Daily pg_dump (custom format) with retention. Runs inside the postgres:17-alpine image.
# Dumps are private (umask 077, directory 0700) and leave out session rows: the schema is kept,
# live session ids are not, so a leaked dump cannot be replayed as a signed-in session.
set -eu
umask 077
: "${BACKUP_RETENTION_DAYS:=14}"
: "${BACKUP_INTERVAL_SECONDS:=86400}"
mkdir -p /backups
chmod 700 /backups
while true; do
  stamp=$(date -u +%Y%m%dT%H%M%SZ)
  tmp="/backups/.halloween-$stamp.dump.partial"
  if pg_dump --format=custom --no-owner --exclude-table-data=sessions --file="$tmp"; then
    mv "$tmp" "/backups/halloween-$stamp.dump"
    echo "backup ok: halloween-$stamp.dump"
  else
    rm -f "$tmp"; echo "backup FAILED at $stamp" >&2
  fi
  find /backups -name 'halloween-*.dump' -mtime +"$BACKUP_RETENTION_DAYS" -delete
  sleep "$BACKUP_INTERVAL_SECONDS"
done
