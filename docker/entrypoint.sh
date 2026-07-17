#!/bin/sh
set -e

# Выбираем Caddyfile по DEPLOY_MODE
MODE="${DEPLOY_MODE:-dev}"

# Dev: baked classmap from image build is authoritative — it won't see classes
# added after the image was built when host dir is bind-mounted over /app.
# Fix: (1) empty the classmap, (2) remove setClassMapAuthoritative(true) from
# autoload_real.php so Composer's ClassLoader falls back to PSR-4 disk scan.
# Vendored (3rd-party) classes still come from PSR-4 autoload rules — no perf
# impact in dev because we're not benchmarking dev.
if [ "$MODE" = "dev" ] && [ -f /app/vendor/composer/autoload_classmap.php ]; then
    printf "<?php\nreturn [];\n" > /app/vendor/composer/autoload_classmap.php
fi
if [ "$MODE" = "dev" ] && [ -f /app/vendor/composer/autoload_real.php ]; then
    sed -i 's/$loader->setClassMapAuthoritative(true);//g' /app/vendor/composer/autoload_real.php
fi
case "$MODE" in
    dev)        CFG=/app/config/frankenphp/Caddyfile.dev ;;
    cf_flex|cf_full) CFG=/app/config/frankenphp/Caddyfile.cf ;;
    demo)       CFG=/app/config/frankenphp/Caddyfile.demo ;;
    direct)     CFG=/app/config/frankenphp/Caddyfile.direct ;;
    *)          echo "unknown DEPLOY_MODE=$MODE" >&2; exit 2 ;;
esac

# Для CF-режима отличаем Flex (HTTP only) от Full (HTTPS с Origin Cert)
export CF_LISTEN_PORT=80
if [ "$MODE" = "cf_full" ]; then
    export CF_LISTEN_PORT=443
fi

# Если команда — frankenphp run без явного --config, подставляем выбранный
if [ "$1" = "frankenphp" ] && [ "$2" = "run" ]; then
    shift 2
    exec frankenphp run --config "$CFG" "$@"
fi

exec "$@"
