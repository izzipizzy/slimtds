# ===== Stage: assets-builder =====
FROM oven/bun:1-alpine AS assets-builder

WORKDIR /app

COPY package.json bunfig.toml ./
RUN bun install

COPY resources ./resources
COPY public/assets ./public/assets
COPY scripts ./scripts
COPY tailwind.config.js ./

RUN bun run build

# ===== Stage: vendor =====
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-req=ext-sockets

COPY . ./
RUN composer dump-autoload --optimize --classmap-authoritative

# ===== Stage: runtime =====
FROM dunglas/frankenphp:1.12.4-php8.4-alpine AS runtime

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
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV FRANKENPHP_CONFIG="/app/config/frankenphp/Caddyfile.dev"

EXPOSE 80 443

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
# No --config here: entrypoint.sh injects the Caddyfile for the active DEPLOY_MODE.
# (A hardcoded --config would be appended after entrypoint's and win, forcing dev config.)
CMD ["frankenphp", "run"]
