FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

FROM node:24-alpine AS frontend

WORKDIR /app
ARG VITE_REVERB_APP_KEY=rpgays-local-key
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php:8.4-cli-alpine

RUN apk add --no-cache $PHPIZE_DEPS postgresql-dev libpq libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /app
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
RUN APP_ENV=build php artisan package:discover --ansi

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM php:8.4-cli-alpine AS quality

ENV APP_ENV=testing \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    CONTROL_SECRET=quality-container-secret-not-for-production \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    CACHE_STORE=array \
    FILESYSTEM_DISK=local \
    QUEUE_CONNECTION=sync \
    SESSION_DRIVER=array

RUN apk add --no-cache $PHPIZE_DEPS postgresql-dev libpq sqlite-dev libzip-dev oniguruma-dev nodejs npm \
    && docker-php-ext-install pdo_pgsql pdo_sqlite pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock package.json package-lock.json ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts \
    && npm ci

COPY . .
RUN cp .env.example .env \
    && composer dump-autoload --optimize --no-interaction

CMD ["composer", "quality"]
