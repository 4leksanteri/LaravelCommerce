#!/bin/sh
#
# Production boot.
#
# Caching happens here rather than at build time because every value being
# cached comes from the environment, and the environment is not known until the
# container starts. An image built with one deployment's configuration baked in
# is an image that can only be deployed once.
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Laravel signs sessions and encrypts cookies with it." >&2
    exit 1
fi

# Deliberately not run: `php artisan migrate`. Under more than one replica a
# migrate per container is a race, and a failed one leaves the schema half
# applied with no clear owner. A deployment runs migrations as its own step,
# once, before the new containers start taking traffic.
echo "==> Caching configuration"
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
