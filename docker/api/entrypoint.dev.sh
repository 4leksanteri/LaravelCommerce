#!/bin/sh
#
# Development boot. None of this belongs in production, which is exactly why
# there are two entrypoints rather than one with an APP_ENV branch in it.
set -eu

# The source tree is bind-mounted over /app and vendor/ is a named volume, so
# what the image installed at build time may be a lockfile or two out of date.
# Composer is quick when there is nothing to do.
echo "==> Installing PHP dependencies"
composer install --no-interaction --prefer-dist

echo "==> Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}"
attempt=0
until php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT")) ? 0 : 1);'; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 60 ]; then
        echo "PostgreSQL did not accept a connection within 60 seconds." >&2
        exit 1
    fi
    sleep 1
done

# Migrating on boot is a laptop convenience. A deployment runs migrations as an
# explicit step, because more than one replica migrating at once is a race.
echo "==> Running migrations"
php artisan migrate --force

# No config:cache here, deliberately. A cached config file survives an edit to
# .env and then quietly serves the old value, which costs more time to diagnose
# than the caching ever saves in development.
echo "==> Clearing stale caches"
php artisan config:clear
php artisan route:clear

exec "$@"
