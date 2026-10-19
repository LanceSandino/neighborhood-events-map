# syntax=docker/dockerfile:1.7
# Neighborhood Halloween Map — application images.
# Targets: dev (PHP-FPM + pcov, bind-mounted source), prod (PHP-FPM, baked code), nginx (static + proxy).
ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-fpm-alpine AS php-base
RUN apk add --no-cache icu-libs libpq libzip fcgi tzdata \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql opcache pcntl zip \
 && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=0755 docker/php/healthcheck.sh /usr/local/bin/app-healthcheck
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1
WORKDIR /app
HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=5 CMD app-healthcheck
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

FROM php-base AS dev
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
 && pecl install pcov-1.0.12 && docker-php-ext-enable pcov \
 && apk del .build-deps
# Loaded after zz-app.ini (alphabetical) so dev overrides win.
COPY docker/php/php-dev.ini "$PHP_INI_DIR/conf.d/zzz-app-dev.ini"
ENV APP_ENV=dev

FROM php-base AS vendor
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

FROM php-base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && rm -rf var && mkdir -p var/cache var/log var/branding \
 && APP_SECRET=build-only-placeholder-not-used-at-runtime php bin/console cache:warmup --env=prod \
 && chown -R www-data:www-data var
USER www-data

FROM nginxinc/nginx-unprivileged:1.31-alpine AS nginx
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/app-fastcgi.conf /etc/nginx/snippets/app-fastcgi.conf
COPY --chmod=0755 docker/nginx/trusted-proxy.sh /docker-entrypoint.d/30-trusted-proxy.sh
COPY --from=prod /app/public /app/public
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1
