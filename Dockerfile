# syntax=docker/dockerfile:1.7
# Neighborhood events map — application images.
# Targets: dev (PHP-FPM + pcov, bind-mounted source), prod (PHP-FPM, baked code), nginx (static + proxy).
# Every base image is pinned by its multi-arch digest, so a rebuild cannot pick up a moved or replaced tag;
# Dependabot (docker ecosystem) proposes the new digest when a tag moves.

FROM php:8.5-fpm-alpine@sha256:75bbd8240bc8a991b0f8ddb2365f11937f93199972e3c9f7e32c63c7241f546b AS php-base
# OPcache is part of PHP itself since 8.5 (no extension to install). `apk upgrade` takes Alpine's security fixes
# that the pinned base image does not carry yet (e.g. brotli 1.2.1 for CVE-2026-111497); CI's image scan fails on
# any fixed HIGH/CRITICAL package left behind.
RUN apk upgrade --no-cache \
 && apk add --no-cache icu-libs libpq libzip fcgi tzdata \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql pcntl zip \
 && apk del .build-deps
COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=0755 docker/php/healthcheck.sh /usr/local/bin/app-healthcheck
# PHP-FPM listens only on Unix sockets in /run/php (a volume shared with nginx, whose user is uid/gid 101):
# www-data joins gid 101 so the sockets can be handed to that group with mode 0660.
RUN addgroup -S -g 101 fcgi-nginx && addgroup www-data fcgi-nginx \
 && install -d -o www-data -g fcgi-nginx -m 0750 /run/php
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 \
    FPM_MAX_CHILDREN=12 FPM_START_SERVERS=3 FPM_MIN_SPARE=2 FPM_MAX_SPARE=6 FPM_MAIL_MAX_CHILDREN=4 FPM_UPLOAD_MAX_CHILDREN=2
WORKDIR /app
HEALTHCHECK --interval=15s --timeout=5s --start-period=60s --retries=5 CMD app-healthcheck
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

FROM php-base AS dev
# pcov (coverage) from its tagged source on GitHub, checked against a pinned SHA-256: pecl.php.net refuses
# downloads from CI runners. Bump both ARGs together.
ARG PCOV_VERSION=1.0.12
ARG PCOV_SHA256=fdd07cad8e2ff42f0c9f095d84aeef11dab0fde7a008805f61883cbcb1b3f12b
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl \
 && curl -fsSL --retry 3 -o /tmp/pcov.tar.gz "https://github.com/krakjoe/pcov/archive/refs/tags/v${PCOV_VERSION}.tar.gz" \
 && echo "${PCOV_SHA256}  /tmp/pcov.tar.gz" | sha256sum -c - \
 && mkdir -p /usr/src/php/ext/pcov && tar xzf /tmp/pcov.tar.gz -C /usr/src/php/ext/pcov --strip-components=1 \
 && docker-php-ext-install pcov \
 && rm -rf /tmp/pcov.tar.gz /usr/src/php/ext/pcov \
 && apk del .build-deps
# Loaded after zz-app.ini (alphabetical) so dev overrides win.
COPY docker/php/php-dev.ini "$PHP_INI_DIR/conf.d/zzz-app-dev.ini"
ENV APP_ENV=dev

FROM php-base AS vendor
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

FROM php-base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY docker/php/php-prod.ini "$PHP_INI_DIR/conf.d/zzz-app-prod.ini"
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && rm -rf var && mkdir -p var/cache var/log var/branding \
 && APP_SECRET=build-only-placeholder-not-used-at-runtime php bin/console cache:warmup --env=prod \
 && chown -R www-data:www-data var
# After every build step (they run programs): no program execution at runtime (docker/php/php-prod-final.ini).
COPY docker/php/php-prod-final.ini "$PHP_INI_DIR/conf.d/zzzz-app-prod-final.ini"
USER www-data

FROM nginxinc/nginx-unprivileged:1.31-alpine@sha256:b5047b9b76845eac124358373c6852377c52e4b05834a47647e955dd91cb8f1e AS nginx
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/app-fastcgi.conf /etc/nginx/snippets/app-fastcgi.conf
COPY --chmod=0755 docker/nginx/trusted-proxy.sh /docker-entrypoint.d/30-trusted-proxy.sh
COPY --from=prod /app/public /app/public
# Mount point of the PHP-FPM socket volume, same owner and mode as in the app image (whichever image
# initializes the volume first, PHP can create its sockets and nginx can reach them).
USER root
# Alpine security fixes newer than the pinned base image (see the php-base stage).
RUN apk upgrade --no-cache \
 && install -d -o 82 -g 101 -m 0750 /run/php
USER 101
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 CMD wget -qO- http://127.0.0.1:8080/healthz >/dev/null || exit 1
