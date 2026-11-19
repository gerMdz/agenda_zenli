FROM composer:2 AS composer

FROM node:22-bookworm-slim AS frontend

WORKDIR /app
COPY frontend/package*.json ./
RUN if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi
COPY frontend/ ./
RUN npm run build

FROM nginx:stable-alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY public/ /var/www/html/public/
COPY --from=frontend /app/dist/ /srv/zenli/

FROM php:8.4-fpm-bookworm AS app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zenli.ini

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-autoloader

COPY .env .env
COPY bin/ bin/
COPY config/ config/
COPY migrations/ migrations/
COPY public/ public/
COPY src/ src/
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p var \
    && chown www-data:www-data var
