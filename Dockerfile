# syntax=docker/dockerfile:1.7
#
# Agelgay (EUISIS) container build.
#
#   docker compose up --build        # full stack: web, app, queue, scheduler, db
#   docker build --target app .      # PHP-FPM image only
#   docker build --target web .      # nginx image (static files + proxy to app)
#
# Stages:
#   vendor  - Composer dependencies (no dev packages)
#   assets  - Vite production build (needs vendor/tightenco/ziggy, aliased in vite.config.js)
#   app     - PHP 8.4 FPM with every extension the application needs; runs php-fpm,
#             the queue worker or the scheduler depending on the entrypoint argument
#   web     - nginx serving public/ and passing PHP requests to the app container

ARG PHP_VERSION=8.4
ARG NODE_VERSION=24
# true installs Composer dev packages too (Faker, PHPUnit): needed for `php artisan db:seed`,
# which builds demo data with model factories. compose.yaml sets it to true for the local stack.
ARG WITH_DEV_DEPS=false

# ---------------------------------------------------------------------------
# Composer dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
ARG WITH_DEV_DEPS
WORKDIR /app
COPY composer.json composer.lock ./
# Platform requirements are checked again in the app stage, where the real
# extension set is installed; the Composer image only resolves and downloads.
RUN --mount=type=cache,target=/tmp/cache \
    composer install \
        $([ "$WITH_DEV_DEPS" = "true" ] || echo --no-dev) \
        --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --no-progress --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Front-end build
# ---------------------------------------------------------------------------
FROM node:${NODE_VERSION}-bookworm-slim AS assets
WORKDIR /app
ENV CI=true
COPY package.json package-lock.json ./
# @euisis/ui is a local package linked from packages/euisis-ui (file: dependency).
COPY packages ./packages
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund
# The route() helper is resolved from the Composer package (see vite.config.ts).
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
COPY vite.config.js tsconfig.json ./
COPY resources ./resources
COPY public/index.php ./public/index.php
RUN npm run build

# ---------------------------------------------------------------------------
# Application image (PHP-FPM, queue worker, scheduler)
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS app
ARG WITH_DEV_DEPS

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        pdo_pgsql pgsql pdo_mysql intl gd zip bcmath pcntl exif opcache redis \
    && apt-get update \
    && apt-get install -y --no-install-recommends gosu postgresql-client unzip git \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# The repository's public/storage link (if any) points at a host path; the
# entrypoint recreates it with `storage:link` inside the container.
RUN rm -rf public/storage \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
                storage/framework/sessions storage/framework/views storage/logs \
                bootstrap/cache \
    && composer dump-autoload --optimize --no-interaction \
        $([ "$WITH_DEV_DEPS" = "true" ] || echo --no-dev) \
    && chown -R www-data:www-data storage bootstrap/cache \
    && cp docker/entrypoint.sh /usr/local/bin/entrypoint \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint \
    && chmod 0755 /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Web server image
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
# Public disk files live in the storage volume, mounted read-only into this container.
RUN ln -s /var/www/html/storage/app/public /var/www/html/public/storage
