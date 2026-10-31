#!/bin/bash
# Run the Playwright E2E suite (in Docker) against the four local dev stacks:
#   Ventura example     : docker compose up -d                         (http://localhost:8087, Mailpit 8025)
#   Fictional demo      : docker compose --env-file .env.demo up -d    (http://localhost:8088, Mailpit 8026)
#   Fictional lights    : docker compose --env-file .env.lights up -d  (http://localhost:8089, Mailpit 8027)
#   Fictional sale      : docker compose --env-file .env.sale up -d    (http://localhost:8090, Mailpit 8028)
# The organizer role for the E2E account is granted out-of-band here, exactly as an operator would.
set -euo pipefail
cd "$(dirname "$0")/.."
PW_IMAGE="mcr.microsoft.com/playwright:v1.64.1-noble"
mkdir -p docs/design/screenshots
# A fresh organizer address per run keeps repeated runs under the per-email link limit (3 per 15 min).
E2E_ORGANIZER="e2e-organizer-$(date +%s)@example.test"
for stack in "" "--env-file .env.demo" "--env-file .env.lights" "--env-file .env.sale"; do
  # shellcheck disable=SC2086
  docker compose $stack exec -T app php bin/console app:admin:grant "$E2E_ORGANIZER" >/dev/null
done
# nginx publishes only on the host's 127.0.0.1. Docker Desktop (macOS) forwards host.docker.internal to
# that loopback; on Linux the container must share the host network to reach it.
NET=(--add-host=host.docker.internal:host-gateway)
URLS=()
if [ "$(uname -s)" = "Linux" ]; then
  NET=(--network host)
  URLS=(-e E2E_DEMO_URL=http://localhost:8088 -e E2E_VENTURA_URL=http://localhost:8087 -e E2E_LIGHTS_URL=http://localhost:8089
        -e E2E_SALE_URL=http://localhost:8090
        -e E2E_MAILPIT_URL=http://localhost:8026 -e E2E_DEMO_MAILPIT_URL=http://localhost:8026 -e E2E_VENTURA_MAILPIT_URL=http://localhost:8025
        -e E2E_LIGHTS_MAILPIT_URL=http://localhost:8027 -e E2E_SALE_MAILPIT_URL=http://localhost:8028)
fi
# The season test (tests/e2e/specs/season.spec.mjs) puts a new event on the demo's map, which ends the season of the
# fictional homes; they are listed again for whatever event is active before and after every run (app:demo:seed lists
# only the fictional homes that have no listing for it, from examples/demo/homes.json).
# The lights and sale demos' homes come from examples/demo-lights/homes.json and examples/demo-sale/homes.json
# (app:demo:seed reads the file next to the stack's community.yaml); their tests give back every home they add.
relist() {
  docker compose --env-file .env.demo exec -T app php bin/console app:demo:seed >/dev/null
  docker compose --env-file .env.lights exec -T app php bin/console app:demo:seed >/dev/null
  docker compose --env-file .env.sale exec -T app php bin/console app:demo:seed >/dev/null
}
relist
set +e
docker run --rm --ipc=host "${NET[@]}" ${URLS[@]+"${URLS[@]}"} -e E2E_ORGANIZER="$E2E_ORGANIZER" -e E2E_BLOCK_TILES="${E2E_BLOCK_TILES:-}" \
  -v "$PWD":/work -w /work/tests/e2e "$PW_IMAGE" \
  /bin/bash -c "npm ci --no-audit --no-fund >/dev/null && npx playwright test $*"
status=$?
set -e
relist
exit "$status"
