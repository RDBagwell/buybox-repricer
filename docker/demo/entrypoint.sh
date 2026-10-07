#!/bin/sh
# Boots the single-container demo: checks secrets, caches config, migrates, then hands over to
# supervisord. The world is rebuilt by the boot-reset program once the web server is up.
set -eu
cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi
if [ -z "${DB_URL:-}" ] && [ -z "${DB_HOST:-}" ]; then
    echo "Set DB_URL (or DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD) to a Postgres database." >&2
    exit 1
fi

export DEMO_MAX_SPEED="${DEMO_MAX_SPEED:-50}"
export DEMO_IDLE_AFTER_SECONDS="${DEMO_IDLE_AFTER_SECONDS:-120}"

envsubst '${PORT}' < /etc/nginx/templates/demo.conf.template > /etc/nginx/conf.d/demo.conf

php artisan config:cache
php artisan route:cache
php artisan migrate --force
chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /etc/supervisor/demo.conf
