#!/bin/bash
# @module bin/drill-event-upgrade
# @description Rollback drill for the event-scoped listings migration (0.11, docs/runbooks/event-types-migration.md) on
#   a disposable Compose project and port, with the fictional Maple Hollow demo. Starts the PUBLISHED 0.9.0-beta.1
#   images, takes a backup (bin/backup-now.sh), upgrades to the given images (the startup migrations run) and checks
#   that /api/homes shows the same homes, edits and creates a listing on the new release, rolls the IMAGES back to
#   0.9.0-beta.1 keeping the database (path a) and checks that the old release starts and shows the bridged data,
#   makes a write on 0.9 and upgrades again to check it carried forward, then restores the pre-upgrade backup with
#   bin/restore.sh on 0.9.0-beta.1 (path b) and checks the homes are exactly the pre-upgrade ones, and finally
#   upgrades the restored database once more. Always tears the project and its volumes down (down -v).
# @dependencies bash, docker compose, curl, openssl, python3 (scripts/drill_listing.py), bin/backup-now.sh, bin/restore.sh
# Usage: bin/drill-event-upgrade.sh <new-app-image> <new-nginx-image> <tag> [port, default 8198]
#   e.g. docker build --target prod -t nhm-app:drill . && docker build --target nginx -t nhm-nginx:drill . \
#        && bin/drill-event-upgrade.sh nhm-app nhm-nginx drill
set -euo pipefail
cd "$(dirname "$0")/.."
new_app=${1:?usage: bin/drill-event-upgrade.sh <new-app-image> <new-nginx-image> <tag> [port]}
new_nginx=${2:?usage: bin/drill-event-upgrade.sh <new-app-image> <new-nginx-image> <tag> [port]}
new_tag=${3:?usage: bin/drill-event-upgrade.sh <new-app-image> <new-nginx-image> <tag> [port]}
port=${4:-8198}
mail_port=$((port - 1))
old_app=ghcr.io/lancesandino/neighborhood-events-map
old_nginx=ghcr.io/lancesandino/neighborhood-events-map-nginx
old_tag=0.9.0-beta.1
project="nhm-drill-$$"
base="http://localhost:$port"
mailpit="http://localhost:$mail_port"
work=$(mktemp -d)
env_file="$work/.env"
override="$work/compose.drill.yaml"
mkdir -p "$work/backups"
args=(-p "$project" --env-file "$env_file" -f compose.yaml -f "$override")
compose=(docker compose "${args[@]}")
resident=demo-resident-01@example.invalid

cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then
    echo "--- app log (last 40 lines)" >&2
    "${compose[@]}" logs --no-color --tail 40 app >&2 2>/dev/null || true
  fi
  "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf -- "${work:?}"
  exit "$status"
}
trap cleanup EXIT

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }
step() { echo "--- $*"; }

secret=$(openssl rand -hex 24)
password=$(openssl rand -hex 24)
images() { # app image, nginx image, tag
  cat > "$env_file" <<EOF
APP_IMAGE=$1
NGINX_IMAGE=$2
APP_IMAGE_TAG=$3
HTTP_PORT=$port
APP_BASE_URL=$base
APP_SECRET=$secret
POSTGRES_PASSWORD=$password
MAILER_DSN=smtp://mailpit:1025
COMMUNITY_BOOTSTRAP_FILE=/app/examples/demo/community.yaml
COMMUNITY_BOOTSTRAP_APPROVE=1
APP_DEMO=1
BACKUP_DIR=$work/backups
EOF
}
# A local mail catcher so residents can sign in with their one-time links (development image of Mailpit, loopback only).
cat > "$override" <<EOF
services:
  mailpit:
    image: axllent/mailpit:v1.32.0@sha256:0790692be935ea4420d085694f7985b1f692ca6a8771ab0e0bd8ba75ac180498
    ports: ["127.0.0.1:$mail_port:8025"]
    environment: { MP_SMTP_AUTH_ACCEPT_ANY: 1, MP_SMTP_AUTH_ALLOW_INSECURE: 1 }
    networks: [backend, egress]
EOF

up() { "${compose[@]}" up -d --wait --wait-timeout 240 postgres mailpit app nginx backup >/dev/null; }
homes() { curl -fsS "$base/api/homes"; }
# The 0.9 part of every public home (the keys 0.9.0-beta.1 published), sorted by id, as one JSON document.
view09() {
  python3 -c 'import json,sys
keys=["id","address","zone","lat","lon","start","end","candy","nonfood","adult_drinks","low_noise","jump_scares","flashing_lights","loud_audio","scare_level","stroller_friendly","note","status","updated_at"]
print(json.dumps(sorted(({k: h[k] for k in keys} for h in json.load(sys.stdin)["homes"]), key=lambda h: h["id"]), sort_keys=True))'
}
note_of() { python3 -c "import json,sys; print(next(h['note'] for h in json.load(sys.stdin)['homes'] if h['id'] == $1))"; }
count() { python3 -c 'import json,sys; print(len(json.load(sys.stdin)["homes"]))'; }
sql() { "${compose[@]}" exec -T postgres psql -U halloween -d halloween -At -c "$1"; }
applog() { "${compose[@]}" logs --no-color app 2>/dev/null || true; } # read whole: grep -q would cut the pipe (pipefail)

step "1. published $old_tag with the fictional demo"
images "$old_app" "$old_nginx" "$old_tag"
up
before=$(homes | view09)
n=$(printf '%s' "$before" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))')
[ "$n" -ge 14 ] || fail "the demo has $n homes on $old_tag"
pass "$old_tag serves $n fictional homes; version $(curl -fsS "$base/healthz")"
home=$(printf '%s' "$before" | python3 -c 'import json,sys; print(next(h["id"] for h in json.load(sys.stdin) if h["address"] == "100 Pumpkin Patch Ln"))')

step "2. backup before the upgrade"
dump=$(bin/backup-now.sh "${args[@]}" | sed -n 's/^Backup written: \([^ ]*\).*/\1/p')
[ -n "$dump" ] || fail "no backup written"
pass "backup $dump"

step "3. upgrade to $new_app:$new_tag (startup migrations)"
images "$new_app" "$new_nginx" "$new_tag"
up
log=$(applog)
grep -q 'Migrating up to DoctrineMigrations\\Version20261031000002' <<<"$log" || fail "the event-types migration did not run at start"
after=$(homes | view09)
[ "$after" = "$before" ] || fail "/api/homes changed across the upgrade"
pass "same $n homes, keys and values after the upgrade; $(sql "SELECT COUNT(*) || ' occurrence(s), ' || (SELECT COUNT(*) FROM event_listings) || ' event listing(s)' FROM event_occurrences")"

step "4. edit and create a listing on the new release"
python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email "$resident" edit --home-id "$home" --note "Edited on $new_tag" || fail "edit"
python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email demo-resident-02@example.invalid claim --house 901 --street "Moonbeam Dr" --note "Listed on $new_tag" || fail "claim"
upgraded=$(homes)
[ "$(printf '%s' "$upgraded" | count)" = "$((n + 1))" ] || fail "the new home is not on the map"
created=$(printf '%s' "$upgraded" | python3 -c 'import json,sys; print(next(h["id"] for h in json.load(sys.stdin)["homes"] if h["address"] == "901 Moonbeam Dr"))')
bridged=$(printf '%s' "$upgraded" | view09)
pass "listing $home edited, home $created listed ($((n + 1)) homes)"

step "5. path (a): images back to $old_tag, database kept"
images "$old_app" "$old_nginx" "$old_tag"
up
log=$(applog)
notice=$(grep -E -o -m1 'You have [0-9]+ previously executed migrations' <<<"$log" || true)
[ -n "$notice" ] || fail "no notice about the newer migrations from $old_tag"
pass "$old_tag starts on the newer schema: \"$notice\"; $(grep -E -o -m1 'Already at the latest version|No migrations to execute' <<<"$log" || echo 'nothing migrated')"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/")" = 200 ] || fail "the $old_tag home page"
rolled=$(homes | view09)
[ "$rolled" = "$bridged" ] || fail "$old_tag does not show the bridged data"
pass "$old_tag shows the same $((n + 1)) homes, the edit and the new home (bridged legacy rows)"
python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email "$resident" edit --home-id "$home" --note "Edited on $old_tag" || fail "edit on $old_tag"
pass "a resident edits on $old_tag"

step "6. forward again to $new_tag: the $old_tag write is kept"
images "$new_app" "$new_nginx" "$new_tag"
up
[ "$(homes | note_of "$home")" = "Edited on $old_tag" ] || fail "the write made on $old_tag was lost"
[ "$(sql "SELECT note FROM event_listings el JOIN event_occurrences o ON o.id = el.event_id AND o.state = 'active' WHERE el.address_id = $home")" = "Edited on $old_tag" ] || fail "event_listings"
pass "$new_tag shows the note written on $old_tag (event tables updated by the bridge trigger)"

step "7. path (b): $old_tag images and the pre-upgrade backup (bin/restore.sh)"
images "$old_app" "$old_nginx" "$old_tag"
up
printf 'RESTORE\n' | bin/restore.sh "$dump" "${args[@]}" >/dev/null
up
restored=$(homes | view09)
[ "$restored" = "$before" ] || fail "the restored homes differ from the pre-upgrade ones"
[ -z "$(sql "SELECT to_regclass('event_occurrences')")" ] || fail "event tables survived the restore"
pass "restored: exactly the $n pre-upgrade homes; no event tables; $(sql "SELECT COUNT(*) FROM doctrine_migration_versions") migrations recorded"

step "8. the restored database upgrades again"
images "$new_app" "$new_nginx" "$new_tag"
up
[ "$(homes | view09)" = "$before" ] || fail "upgrading the restored database changed /api/homes"
pass "upgraded again: same $n homes"
echo "event-types upgrade and rollback drill passed: $old_tag ⇄ $new_app:$new_tag"
