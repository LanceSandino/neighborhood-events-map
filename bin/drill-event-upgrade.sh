#!/bin/bash
# @module bin/drill-event-upgrade
# @description Rollback drill for the event migrations (0.10, 0.11, 0.15, 0.16 and 0.17; docs/runbooks/event-types-migration.md)
#   on a disposable Compose project and port, with the fictional Maple Hollow demo (its Halloween has its own branding,
#   "Spooky streets."). Starts the PUBLISHED 0.9.0-beta.1 images, takes a backup (bin/backup-now.sh), upgrades to the
#   given images (every startup migration runs in order) and checks that /api/homes shows the same homes, the Halloween
#   became the open default event and keeps its custom branding (now its own), edits and creates a listing on the new
#   release, opens Christmas lights next to it (their own look, not Halloween's) and changes the Halloween's headline,
#   then (several events) an organizer closes the Halloween — next year's is created hidden (0.17): no public list, page
#   or API shows it — and a resident lists for next year, which the legacy table must not take; rolls the IMAGES back to 0.9.0-beta.1 keeping the database (path a) and
#   checks that the old release starts and shows the bridged data (the closed Halloween's homes) and its custom headline,
#   makes a write on 0.9 and upgrades again to check it reached the closed Halloween and left next year's listing alone
#   and next year's event still hidden, then announces it (upcoming: now public),
#   then restores the pre-upgrade backup with bin/restore.sh on 0.9.0-beta.1 (path b) and checks the homes are exactly
#   the pre-upgrade ones, and finally upgrades the restored database once more (the branding moves again). Always tears
#   the project and its volumes down (down -v).
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
# The text of the hero headline of a page (the community's or event's branding), e.g. "Spooky streets. Sweet treats.".
headline() { curl -fsS "$base$1" | python3 -c 'import re,sys,html; m=re.search(r"<h1>(.*?)</h1>", sys.stdin.read(), re.S); print(html.unescape(re.sub(r"<[^>]+>", "", m.group(1))).strip() if m else "")'; }
custom="Spooky streets. Sweet treats." # the demo's own Halloween headline (examples/demo/community.yaml)

step "1. published $old_tag with the fictional demo"
images "$old_app" "$old_nginx" "$old_tag"
up
before=$(homes | view09)
n=$(printf '%s' "$before" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))')
[ "$n" -ge 14 ] || fail "the demo has $n homes on $old_tag"
pass "$old_tag serves $n fictional homes; version $(curl -fsS "$base/healthz")"
[ "$(headline /)" = "$custom" ] || fail "$old_tag does not show the demo's custom headline"
home=$(printf '%s' "$before" | python3 -c 'import json,sys; print(next(h["id"] for h in json.load(sys.stdin) if h["address"] == "100 Pumpkin Patch Ln"))')

step "2. backup before the upgrade"
dump=$(bin/backup-now.sh "${args[@]}" | sed -n 's/^Backup written: \([^ ]*\).*/\1/p')
[ -n "$dump" ] || fail "no backup written"
pass "backup $dump"

step "3. upgrade to $new_app:$new_tag (startup migrations)"
images "$new_app" "$new_nginx" "$new_tag"
up
log=$(applog)
# Every migration runs at start, up to the latest (Version20261031000004 per-event branding, then Version20261101000001
# hidden events): Doctrine names the target and how many it executed (5 from 0.9: 0.10, 0.11, 0.15, 0.16, 0.17).
grep -q 'Migrating up to DoctrineMigrations\\Version20261101000001' <<<"$log" || fail "the event migrations did not run at start"
grep -q '5 migrations executed' <<<"$log" || fail "not every event migration ran at start: $(grep -o '[0-9]* migrations executed' <<<"$log" | head -1)"
after=$(homes | view09)
[ "$after" = "$before" ] || fail "/api/homes changed across the upgrade"
[ "$(sql "SELECT state || '/' || is_default FROM event_occurrences")" = "open/true" ] || fail "the Halloween is not the open default event"
pass "same $n homes, keys and values after the upgrade; $(sql "SELECT COUNT(*) || ' occurrence(s) (open, default), ' || (SELECT COUNT(*) FROM event_listings) || ' event listing(s)' FROM event_occurrences")"
[ "$(sql "SELECT settings->'branding'->>'headline' FROM event_occurrences")" = "Spooky streets." ] || fail "the Halloween did not take the community's custom branding"
[ "$(headline /)" = "$custom" ] || fail "the Halloween page lost its custom headline across the upgrade"
pass "the Halloween keeps its custom branding, now its own: \"$(headline /)\""

step "4. edit and create a listing on the new release"
python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email "$resident" edit --home-id "$home" --note "Edited on $new_tag" || fail "edit"
python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email demo-resident-02@example.invalid claim --house 901 --street "Moonbeam Dr" --note "Listed on $new_tag" || fail "claim"
upgraded=$(homes)
[ "$(printf '%s' "$upgraded" | count)" = "$((n + 1))" ] || fail "the new home is not on the map"
created=$(printf '%s' "$upgraded" | python3 -c 'import json,sys; print(next(h["id"] for h in json.load(sys.stdin)["homes"] if h["address"] == "901 Moonbeam Dr"))')
bridged=$(printf '%s' "$upgraded" | view09)
pass "listing $home edited, home $created listed ($((n + 1)) homes)"

step "4a. per-event branding: Christmas lights open next to the Halloween with their own look; the Halloween's headline changes"
organizer=drill-organizer@example.invalid
"${compose[@]}" exec -T app php bin/console app:admin:grant "$organizer" >/dev/null
closed_id=$(sql "SELECT id FROM event_occurrences WHERE state = 'open'")
lights=$(python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email "$organizer" open-event --type holiday_lights --name "Christmas Lights" \
  --starts-on 2026-11-27 --ends-on 2027-01-01) || fail "open the lights"
lights_id=$(printf '%s' "$lights" | python3 -c 'import json,sys; print(json.load(sys.stdin)["event_id"])')
[ "$(headline "/?e=$lights_id")" = "Holiday nights. Bright lights." ] || fail "the lights show Halloween's branding: $(headline "/?e=$lights_id")"
python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email "$organizer" brand --event-id "$closed_id" --headline "Drill streets." >/dev/null || fail "brand"
custom="Drill streets. Sweet treats."
[ "$(headline /)" = "$custom" ] || fail "the Halloween's new headline is not shown"
[ "$(sql "SELECT settings->'branding'->>'headline' FROM community_settings")" = "Drill streets." ] || fail "the 0.9 keys did not follow the Halloween's branding"
pass "lights $lights_id: \"$(headline "/?e=$lights_id")\"; Halloween: \"$(headline /)\" (and in the keys the 0.9 image reads)"

step "4b. several events: the Halloween closes, next year's is created hidden, a resident lists for it"
closed_day=$(sql "SELECT starts_on FROM event_occurrences WHERE id = $closed_id")
closing=$(python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email "$organizer" close --event-id "$closed_id") || fail "close"
next_id=$(printf '%s' "$closing" | python3 -c 'import json,sys; print(json.load(sys.stdin)["next_id"])')
[ "$(sql "SELECT state || ' ' || starts_on || ' ' || dates_tentative FROM event_occurrences WHERE id = $next_id")" = "hidden 2027-10-31 true" ] || fail "next year's Halloween"
public_states() { curl -fsS "$base/api/events" | python3 -c 'import json,sys; print(",".join(e["state"] for e in json.load(sys.stdin)["events"]))'; }
[ "$(public_states)" = "closed,open" ] || fail "next year's hidden Halloween is listed for visitors: $(public_states)"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$base/api/event?e=$next_id")" = 404 ] || fail "the hidden event answers the public API"
curl -fsS "$base/?e=$closed_id" | grep -q "e=$next_id" && fail "the thank-you page names the hidden event"
pass "next year's Halloween $next_id is hidden: not in /api/events, 404 on /api/event?e=, not named by the thank-you page"
python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email "$resident" list-for --home-id "$home" --event-id "$next_id" --note "Next year on $new_tag" || fail "list for next year"
[ "$(sql "SELECT event_date || ' ' || note FROM listings WHERE address_id = $home")" = "$closed_day Edited on $new_tag" ] || fail "next year's listing reached the legacy table"
[ "$(homes | count)" = 0 ] || fail "a closed event shows homes"
pass "closed event $closed_id of $closed_day (no homes on the map), next year's $next_id hidden on 2027-10-31 (tentative); the legacy row keeps the closed Halloween's listing"

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
[ "$(headline /)" = "$custom" ] || fail "$old_tag does not show the Halloween's custom headline after the image rollback: $(headline /)"
pass "$old_tag shows the Halloween's custom headline: \"$(headline /)\""
python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email "$resident" edit --home-id "$home" --note "Edited on $old_tag" || fail "edit on $old_tag"
pass "a resident edits on $old_tag"

step "6. forward again to $new_tag: the $old_tag write is kept"
images "$new_app" "$new_nginx" "$new_tag"
up
[ "$(sql "SELECT note FROM event_listings WHERE event_id = $closed_id AND address_id = $home")" = "Edited on $old_tag" ] || fail "the write made on $old_tag was lost"
[ "$(sql "SELECT note FROM event_listings WHERE event_id = $next_id AND address_id = $home")" = "Next year on $new_tag" ] || fail "next year's listing changed"
[ "$(public_states)" = "closed,open" ] || fail "/api/events after the forward: $(public_states)"
# A second organizer: the first one has used its three sign-in links of the quarter hour (open-event, brand, close).
organizer2=drill-organizer-2@example.invalid
"${compose[@]}" exec -T app php bin/console app:admin:grant "$organizer2" >/dev/null
python3 scripts/drill_listing.py --base "$base" --mailpit "$mailpit" --email "$organizer2" announce --event-id "$next_id" >/dev/null || fail "announce"
[ "$(public_states)" = "closed,open,upcoming" ] || fail "/api/events after the announcement: $(public_states)"
[ "$(curl -fsS "$base/?e=$closed_id" | grep -c "href=\"/?e=$next_id\"")" -ge 1 ] || fail "the thank-you card does not link the announced event"
[ "$(headline /)" = "$custom" ] || fail "the closed Halloween lost its headline"
[ "$(headline "/?e=$lights_id")" = "Holiday nights. Bright lights." ] || fail "the lights changed their look"
pass "$new_tag keeps the note written on $old_tag in the closed Halloween (bridge trigger); next year's listing is untouched and its event was still hidden until announced (now upcoming, linked from the thank-you card); each event keeps its look"

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
[ "$(headline /)" = "Spooky streets. Sweet treats." ] || fail "the restored Halloween lost its custom headline on the upgrade"
pass "upgraded again: same $n homes; the Halloween's custom headline moved again: \"$(headline /)\""
echo "event-types upgrade and rollback drill passed: $old_tag ⇄ $new_app:$new_tag"
