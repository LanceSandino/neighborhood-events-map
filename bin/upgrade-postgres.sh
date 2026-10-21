#!/bin/bash
# Move the database to the newer PostgreSQL major version named in compose.yaml (for example 17 → 18).
# A major version cannot open the previous one's data files, so this goes through a backup: dump the running
# (old) database, start the new PostgreSQL on its own empty volume, restore, compare every table's row count,
# then start the whole stack. The old data volume is left untouched for a rollback.
#
# Run it AFTER updating the checkout to the new release and BEFORE `docker compose up`, while the old
# containers still run. Usage: bin/upgrade-postgres.sh [extra docker compose args, e.g. --env-file .env.demo]
set -euo pipefail
cd "$(dirname "$0")/.."
dc() { docker compose "$@" </dev/null; }   # never let docker read the confirmation answer
args=("$@")

target=$(dc "${args[@]}" config --images postgres 2>/dev/null | sed -nE 's#^(docker\.io/)?(library/)?postgres:([0-9]+).*#\3#p' | head -1)
[ -n "$target" ] || { echo "compose.yaml names no postgres:<major> image." >&2; exit 2; }
running=$(dc "${args[@]}" exec -T postgres postgres --version 2>/dev/null | sed -nE 's/.* ([0-9]+)\.[0-9]+.*/\1/p' || true)
if [ -z "$running" ]; then
  echo "The PostgreSQL container is not running, so there is nothing to dump. Start the previous release again, or" >&2
  echo "restore an existing backup into the new version with bin/restore.sh." >&2
  exit 2
fi
if [ "$running" -ge "$target" ]; then
  echo "The running database is already PostgreSQL $running (compose.yaml: $target). Nothing to do."
  exit 0
fi

read -r -p "Move the database from PostgreSQL $running to $target? The site is offline for a minute or two. Type UPGRADE to continue: " answer || answer=""
[ "$answer" = "UPGRADE" ] || { echo "Aborted."; exit 1; }

# Exact row counts of every table except sessions (backups leave their rows out on purpose).
count_sql="SELECT string_agg(format('SELECT %L AS t, count(*) AS n FROM %I', table_name, table_name), ' UNION ALL ' ORDER BY table_name)
  FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name <> 'sessions'"
counts() {
  local q
  q=$(dc "${args[@]}" exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -tAc \"$count_sql\"")
  [ -n "$q" ] || return 0
  dc "${args[@]}" exec -T postgres sh -c "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -tA -F ' ' -c \"$q ORDER BY 1\""
}

stamp=$(date -u +%Y%m%dT%H%M%SZ)
file="halloween-$stamp.dump"
dc "${args[@]}" stop nginx maintenance app
before=$(counts)
# A one-off container of the backup service (its PostgreSQL client is the new version, which can dump older
# servers), written to a partial file and renamed when complete, like every other backup.
dc "${args[@]}" run --rm --no-deps -T --entrypoint sh backup -c \
  "umask 077 && pg_dump --format=custom --no-owner --exclude-table-data=sessions --file=/backups/.$file.partial && mv /backups/.$file.partial /backups/$file"
echo "Backup of PostgreSQL $running written: $file"

dc "${args[@]}" up -d --no-deps --wait postgres
now=$(dc "${args[@]}" exec -T postgres postgres --version | sed -nE 's/.* ([0-9]+)\.[0-9]+.*/\1/p')
[ "$now" = "$target" ] || { echo "Expected PostgreSQL $target after the switch, found $now. Stopping here." >&2; exit 3; }
if [ -n "$(counts)" ]; then
  echo "The new PostgreSQL $target volume already holds tables; refusing to restore over them." >&2
  exit 3
fi
dc "${args[@]}" run --rm --no-deps -T --entrypoint sh backup -c \
  "pg_restore --no-owner --exit-on-error --single-transaction --dbname=\"\$PGDATABASE\" /backups/$file"
after=$(counts)
if [ "$before" != "$after" ]; then
  echo "Row counts differ after the restore. The old database is untouched in its volume; nothing was started." >&2
  diff <(echo "$before") <(echo "$after") >&2 || true
  exit 4
fi
echo "Restored into PostgreSQL $target; every table has the same number of rows ($(echo "$after" | wc -l | tr -d ' ') tables)."

dc "${args[@]}" up -d
dc "${args[@]}" up -d --wait postgres app nginx
echo "Done. The PostgreSQL $running data stays in the old volume (docker volume ls | grep pgdata) for a rollback;"
echo "remove it once you are sure: docker volume rm <project>_pgdata"
