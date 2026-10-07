# syntax=docker/dockerfile:1

# --- Node toolchain (copied into the PHP image so Wayfinder can call artisan during the build)
FROM node:22.22-bookworm-slim AS node

# --- PHP-FPM base with the extensions the app needs
FROM php:8.3-fpm-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip pcntl bcmath opcache \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

WORKDIR /var/www/html

# --- Application image (php-fpm, Horizon, scheduler, listener, simulator CLI)
FROM base AS app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader --no-progress

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
RUN composer dump-autoload --optimize \
    && cp -n .env.example .env \
    && php artisan key:generate --force \
    && npm run build \
    && rm .env \
    && chown -R www-data:www-data storage bootstrap/cache

# --- nginx serving the built public/ directory
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

# --- Hosted demo: everything in ONE container under supervisord (nginx, php-fpm, Reverb, a queue
# worker, the scheduler, the notification listener, the simulator daemon and a private Redis).
# Built as the last stage so hosts that build a Dockerfile without a target (Render) get it.
# Postgres stays external (DB_URL). See docs/hosting.md.
FROM app AS demo

RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor redis-server gettext-base \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default \
    && rm -rf node_modules \
    && printf 'memory_limit=128M\nopcache.enable_cli=1\nexpose_php=Off\ndisplay_errors=Off\n' > /usr/local/etc/php/conf.d/zz-demo.ini \
    && printf '[www]\npm = dynamic\npm.max_children = 4\npm.start_servers = 1\npm.min_spare_servers = 1\npm.max_spare_servers = 2\nlisten = 127.0.0.1:9000\n' > /usr/local/etc/php-fpm.d/zz-demo.conf

COPY docker/demo/nginx.conf.template /etc/nginx/templates/demo.conf.template
COPY docker/demo/supervisord.conf /etc/supervisor/demo.conf
COPY docker/demo/entrypoint.sh /usr/local/bin/demo-entrypoint
RUN chmod +x /usr/local/bin/demo-entrypoint

ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    DEMO_MODE=true \
    ALLOW_REGISTRATION=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=pgsql \
    REDIS_HOST=127.0.0.1 \
    QUEUE_CONNECTION=database \
    CACHE_STORE=database \
    SESSION_DRIVER=database \
    BROADCAST_CONNECTION=reverb \
    REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8090 \
    REVERB_SCHEME=http \
    REVERB_SERVER_HOST=127.0.0.1 \
    REVERB_SERVER_PORT=8090 \
    TRUSTED_PROXIES=*

EXPOSE 10000
CMD ["demo-entrypoint"]
