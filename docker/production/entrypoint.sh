#!/bin/bash
set -e

# Production entrypoint for the Homflo API (Northflank).
# - Web: default FrankenPHP process (Caddy + PHP-FPM).
# - worker: long-running Laravel queue worker.
# - scheduler: Laravel scheduler tick.

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

php artisan config:cache
php artisan route:cache

if [ "$1" = "worker" ]; then
    exec php artisan queue:work --queue="${DB_QUEUE:-default}" --timeout=120 --tries=3
fi

if [ "$1" = "scheduler" ]; then
    exec php artisan schedule:run
fi

exec frankenphp run --config /app/Caddyfile
