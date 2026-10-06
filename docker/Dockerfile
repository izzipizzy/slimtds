# ===== Stage: assets-builder =====
FROM oven/bun:1.4.2-alpine@sha256:d888c0ae6c86d7866ff10c5aafdd9077b36aee6455b33dd270fb93c0dd5cef6f AS assets-builder

WORKDIR /app

COPY package.json bun.lock bunfig.toml ./
RUN bun install --frozen-lockfile

COPY resources ./resources
COPY public/assets ./public/assets
COPY scripts ./scripts
COPY tailwind.config.js ./

RUN bun run build

# ===== Stage: vendor =====
FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS vendor

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-req=ext-sockets

COPY . ./
RUN composer dump-autoload --optimize --classmap-authoritative

# ===== Stage: runtime =====
# Pin the PHP 8.5 / FrankenPHP runtime for reproducible builds.
FROM dunglas/frankenphp:1.12.7-php8.5-alpine@sha256:34c27f58cd2343a004e836db3c82705150f4f47920b640b976707a6c7f4b8686 AS runtime

# Системные зависимости для расширений
RUN apk add --no-cache \
        postgresql18-client \
        icu-data-full \
        libpq \
        gettext \
        su-exec \
        supercronic

# PHP-расширения
RUN install-php-extensions \
        pdo_pgsql \
        intl \
        mbstring \
        opcache \
        zip \
        sodium \
        apcu \
        gd \
        sockets

# PHP конфиг
RUN echo "memory_limit=256M" > /usr/local/etc/php/conf.d/zz-app.ini \
 && echo "date.timezone=UTC" >> /usr/local/etc/php/conf.d/zz-app.ini \
 && echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/zz-app.ini \
 && echo "opcache.enable_cli=1" >> /usr/local/etc/php/conf.d/zz-app.ini \
 && echo "opcache.jit=tracing" >> /usr/local/etc/php/conf.d/zz-app.ini \
 && echo "opcache.jit_buffer_size=64M" >> /usr/local/etc/php/conf.d/zz-app.ini \
 && echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/zz-app.ini

# Build identity. One ARG per instruction — unlike ENV, ARG declares a single
# build argument and the multi-name form fails the build.
#
# These are baked, never overridden at runtime. A compose `environment` mapping
# overrides the image ENV even when interpolation yields an empty string, so a
# `${APP_VERSION:-}` entry would erase a correctly stamped release image on an
# ordinary `docker compose up`. Dev overrides live in docker-compose.override.yml.
ARG APP_VERSION=""
ARG APP_COMMIT=""
ARG APP_BUILD_DATE=""
ARG APP_BUILD_KIND=""
ENV APP_VERSION=$APP_VERSION \
    APP_COMMIT=$APP_COMMIT \
    APP_BUILD_DATE=$APP_BUILD_DATE \
    APP_BUILD_KIND=$APP_BUILD_KIND

WORKDIR /app

COPY --from=vendor    /app /app
COPY --from=assets-builder /app/public/assets /app/public/assets
COPY --from=assets-builder /app/public/p.js   /app/public/p.js

COPY config/frankenphp/ /app/config/frankenphp/
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/healthcheck.sh

EXPOSE 80 443

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
# No --config here: entrypoint.sh injects the Caddyfile for the active DEPLOY_MODE.
# A hardcoded --config is appended *after* entrypoint's own and wins, which silently
# forced the dev config in every mode.
CMD ["frankenphp", "run"]
