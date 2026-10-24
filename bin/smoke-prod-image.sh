#!/bin/bash
# @module bin/smoke-prod-image
# @description Production-image smoke test: starts the real prod app and nginx images (OPcache preloading,
#   prod PHP settings, read-only containers) with compose.yaml, a throwaway project and random secrets, then
#   checks what the development stack cannot: /healthz and the home page answer 200 through nginx, every
#   versioned asset on the page answers 200, a multipart body is refused (415) except on an upload endpoint
#   (which reaches PHP), all three PHP-FPM pools answer, and the console works in the prod image. Always tears
#   the stack and its volumes down. Run before publishing or deploying any image (CI and the Images workflow
#   run it; docs/runbooks/release.md).
# @dependencies bash, docker compose, curl, openssl
# Usage: bin/smoke-prod-image.sh <app-image> <nginx-image> <tag> [port, default 8199]
#   e.g. docker build --target prod -t nhm-app:smoke . && docker build --target nginx -t nhm-nginx:smoke . \
#        && bin/smoke-prod-image.sh nhm-app nhm-nginx smoke
set -euo pipefail
cd "$(dirname "$0")/.."
app_image=${1:?usage: bin/smoke-prod-image.sh <app-image> <nginx-image> <tag> [port]}
nginx_image=${2:?usage: bin/smoke-prod-image.sh <app-image> <nginx-image> <tag> [port]}
tag=${3:?usage: bin/smoke-prod-image.sh <app-image> <nginx-image> <tag> [port]}
port=${4:-8199}
project="nhm-smoke-$$"
base="http://127.0.0.1:$port"
work=$(mktemp -d)
env_file="$work/.env"
compose=(docker compose -p "$project" --env-file "$env_file" -f compose.yaml)

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

{
  echo "APP_IMAGE=$app_image"
  echo "NGINX_IMAGE=$nginx_image"
  echo "APP_IMAGE_TAG=$tag"
  echo "HTTP_PORT=$port"
  echo "APP_BASE_URL=http://localhost:$port"
  echo "APP_SECRET=$(openssl rand -hex 24)"
  echo "POSTGRES_PASSWORD=$(openssl rand -hex 24)"
  echo "MAILER_DSN=null://null"
} > "$env_file"

fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }
# Requests carry the configured host: production accepts only the APP_BASE_URL host.
code() { curl -s -o /dev/null -w '%{http_code}' -H "Host: localhost:$port" "$@"; }

"${compose[@]}" up -d --wait --wait-timeout 180 postgres app nginx >/dev/null

health=$(curl -fsS -H "Host: localhost:$port" "$base/healthz") || fail "/healthz did not answer 200"
case "$health" in *'"status":"ok"'*'"database":"ok"'*) ;; *) fail "/healthz: $health" ;; esac
pass "/healthz: $health"

page=$(curl -fsS -H "Host: localhost:$port" "$base/") || fail "the home page did not answer 200"
pass "home page 200"

assets=$(printf '%s' "$page" | grep -oE '/assets/v[^"'"'"' )]+' | sort -u)
[ -n "$assets" ] || fail "the home page links no versioned assets"
for asset in $assets; do
  [ "$(code "$base$asset")" = 200 ] || fail "$asset did not answer 200"
done
pass "$(printf '%s\n' "$assets" | wc -l | tr -d ' ') versioned assets answer 200"

for path in / /api/auth/request-link /api/me/homes /api/admin/community; do
  [ "$(code -F x=1 "$base$path")" = 415 ] || fail "a multipart body to $path was not refused with 415"
done
pass "multipart bodies refused (415) outside the upload endpoints"

upload=$(code -F x=1 "$base/api/admin/community/logo")
case "$upload" in 401|403) ;; *) fail "multipart to the logo endpoint answered $upload (expected 401/403 from PHP)" ;; esac
pass "the upload endpoint reaches PHP ($upload without a session)"

"${compose[@]}" exec -T app app-healthcheck || fail "a PHP-FPM pool does not answer its ping"
pass "PHP-FPM pools www, mail and upload answer"

"${compose[@]}" exec -T app php bin/console app:db:ping >/dev/null || fail "the console does not run in the prod image"
"${compose[@]}" exec -T app php bin/console app:maintenance:purge --no-interaction >/dev/null \
  || fail "the maintenance purge does not run in the prod image"
pass "console commands run in the prod image"

echo "production image smoke test passed: $app_image:$tag + $nginx_image:$tag"
