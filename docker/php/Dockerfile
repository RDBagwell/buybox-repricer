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
