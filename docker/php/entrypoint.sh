#!/bin/sh
# App container entrypoint: refuse unsafe secrets and debug mode in prod, wait for PostgreSQL,
# apply migrations (MIGRATE_ON_START=1), then exec the given command.
set -eu

if [ "${APP_ENV:-prod}" = "prod" ]; then
  # Debug mode shows stack traces, environment values and the profiler to visitors: never in production.
  case "${APP_DEBUG:-0}" in
    0|false|"") ;;
    *) echo "FATAL: APP_DEBUG must be 0 in production (use the dev overlay for debugging)" >&2; exit 64 ;;
  esac
  case "${APP_SECRET:-}" in
    ""|change-me*|*placeholder*|test*|*example*) echo "FATAL: set a random APP_SECRET (>= 32 chars) in .env" >&2; exit 64 ;;
  esac
  if [ "${#APP_SECRET}" -lt 32 ]; then echo "FATAL: APP_SECRET must be at least 32 characters" >&2; exit 64; fi
  # The development/CI database password (and obvious placeholders) must never reach production.
  case "${DATABASE_URL:-}" in
    *:halloween@*|*:password@*|*:change-me*|*:postgres@*) echo "FATAL: set a random POSTGRES_PASSWORD in .env" >&2; exit 64 ;;
  esac
fi

# The development image runs as root with the source bind-mounted: a fresh branding volume is root's, but PHP-FPM's
# workers (www-data) store uploaded logos there. Production runs as www-data on a volume the image already owns.
if [ "$(id -u)" = "0" ] && [ -d /app/var/branding ]; then
  chown www-data:www-data /app/var/branding
fi

if [ "$1" = "php-fpm" ] || [ "${WAIT_FOR_DB:-0}" = "1" ]; then
  tries=0
  until php bin/console app:db:ping >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then echo "FATAL: database not reachable" >&2; exit 69; fi
    sleep 1
  done
  if [ "${MIGRATE_ON_START:-1}" = "1" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
  fi
  if [ -n "${COMMUNITY_BOOTSTRAP_FILE:-}" ]; then
    # COMMUNITY_BOOTSTRAP_APPROVE=1 only for boundaries that were already reviewed (e.g. the fictional demo).
    if [ "${COMMUNITY_BOOTSTRAP_APPROVE:-0}" = "1" ]; then approve="--approve-boundary"; else approve=""; fi
    php bin/console app:community:bootstrap "$COMMUNITY_BOOTSTRAP_FILE" $approve || echo "WARN: community bootstrap failed" >&2
    if [ "${APP_DEMO:-0}" = "1" ]; then php bin/console app:demo:seed || true; fi
  fi
fi

exec docker-php-entrypoint "$@"
