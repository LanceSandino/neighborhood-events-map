#!/bin/sh
# Run the PHP test suite inside the app (dev) container against a dedicated *_test database.
# Usage (host): docker compose exec app sh bin/test.sh [phpunit args]
set -eu
cd "$(dirname "$0")/.."
php bin/console doctrine:database:create --env=test --if-not-exists -q
php bin/console doctrine:migrations:migrate --env=test --no-interaction -q
exec php vendor/bin/phpunit "$@"
