#!/bin/sh
# Daily pg_dump (custom format) with retention. Runs inside the postgres:17-alpine image.
# Dumps are private (umask 077, directory 0700) and leave out session rows: the schema is kept,
# live session ids are not, so a leaked dump cannot be replayed as a signed-in session.
# Each dump is written to a hidden .partial file and renamed when complete, so a stopped container or a
# full disk never leaves a truncated file that looks like a backup. BACKUP_PATH and BACKUP_ONCE exist for
# the script's tests (scripts/tests/test_operator_scripts.py).
set -eu
umask 077
: "${BACKUP_RETENTION_DAYS:=14}"
: "${BACKUP_INTERVAL_SECONDS:=86400}"
: "${BACKUP_PATH:=/backups}"
mkdir -p "$BACKUP_PATH"
chmod 700 "$BACKUP_PATH"
while true; do
  # A dump cut off by a stop or crash leaves its .partial file behind. Remove those older than an hour:
  # a dump of this database takes seconds, and bin/backup-now.sh may be writing a fresh one right now.
  find "$BACKUP_PATH" -name '.halloween-*.dump.partial' -mmin +60 -delete
  stamp=$(date -u +%Y%m%dT%H%M%SZ)
  tmp="$BACKUP_PATH/.halloween-$stamp.dump.partial"
  if pg_dump --format=custom --no-owner --exclude-table-data=sessions --file="$tmp"; then
    mv "$tmp" "$BACKUP_PATH/halloween-$stamp.dump"
    echo "backup ok: halloween-$stamp.dump"
  else
    rm -f "$tmp"; echo "backup FAILED at $stamp" >&2
  fi
  find "$BACKUP_PATH" -name 'halloween-*.dump' -mtime +"$BACKUP_RETENTION_DAYS" -delete
  [ -z "${BACKUP_ONCE:-}" ] || exit 0
  sleep "$BACKUP_INTERVAL_SECONDS"
done
