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
    cf_flex)    CFG=/app/config/frankenphp/Caddyfile.cf ;;
    cf_full)
        # cf_full обещает шифрованный origin, но не даёт его: в Caddyfile.cf
        # стоит auto_https off и нет ни одной директивы tls, сертификат нигде
        # не подключается и compose его не монтирует. Единственное, что делает
        # режим, — переносит ОТКРЫТЫЙ HTTP на порт 443. Cloudflare в режиме
        # Full ждёт там TLS и отвечает ошибкой 525, а оператор при этом уверен,
        # что трафик до origin зашифрован.
        #
        # Режим доступен по умолчанию (docker-compose.prod.cf.yml) и предложен
        # в make prod-up-cf, поэтому попасть в него легко. Отказываем на старте:
        # молчаливый незашифрованный origin хуже, чем контейнер, который не
        # поднялся и объяснил почему.
        echo "entrypoint: refusing to start — DEPLOY_MODE=cf_full does not do what its" >&2
        echo "  name says. Caddyfile.cf has 'auto_https off' and no tls directive, so" >&2
        echo "  :443 would serve plain HTTP: Cloudflare Full fails there with error 525," >&2
        echo "  and the origin is unencrypted while the docs promise end-to-end TLS." >&2
        echo "  Use DEPLOY_MODE=cf_flex, or wire up the Origin Certificate first." >&2
        exit 78
        ;;
    demo)       CFG=/app/config/frankenphp/Caddyfile.demo ;;
    direct)     CFG=/app/config/frankenphp/Caddyfile.direct ;;
    *)          echo "unknown DEPLOY_MODE=$MODE" >&2; exit 2 ;;
esac

# Порт для CF-режима. Ветка cf_full сюда не доходит — см. отказ выше.
export CF_LISTEN_PORT=80

# Если команда — frankenphp run, подставляем конфиг выбранного режима.
if [ "$1" = "frankenphp" ] && [ "$2" = "run" ]; then
    shift 2
    # Этот entrypoint — единственный владелец --config. Второй --config в команде
    # встал бы после нашего и победил: ровно так dev-конфиг молча подменял собой
    # выбранный режим во всех прод-режимах. --resume отменяет наш конфиг тем же
    # эффектом. Падаем с понятной ошибкой вместо тихого выбора «какого-нибудь».
    #
    # Аргументы разбираем так же, как pflag, а не по тексту: иначе путь вида
    # -credentials.env или склейка -acaddyfile выглядят как -c и ловятся зря.
    # Правила: -- завершает разбор; значение может прийти отдельным словом или
    # быть приклеено; в короткой связке первый же флаг со значением забирает
    # остаток себе (-eacaddyfile — это -e и -a caddyfile, никакого -c там нет).
    refuse_config_flag() {
        echo "entrypoint: refusing to start — '$1' would take the Caddyfile away from" >&2
        echo "  entrypoint.sh, which owns it (DEPLOY_MODE=$MODE -> $CFG)." >&2
        echo "  A second --config lands after ours and wins; --resume ignores ours." >&2
        echo "  Drop the flag from the container command, or change DEPLOY_MODE." >&2
        exit 64
    }

    expect_value=no
    for arg in "$@"; do
        if [ "$expect_value" = yes ]; then expect_value=no; continue; fi
        case "$arg" in
            --) break ;;
            --config|--config=*|--resume|--resume=*) refuse_config_flag "$arg" ;;
            --adapter|--envfile|--pidfile|--pingback) expect_value=yes; continue ;;
            --*) continue ;;
            -?*)
                rest=${arg#-}
                while [ -n "$rest" ]; do
                    ch=${rest%"${rest#?}"}
                    rest=${rest#?}
                    case "$ch" in
                        c|r) refuse_config_flag "$arg" ;;
                        a)   [ -z "$rest" ] && expect_value=yes; break ;;
                        =)   break ;;
                    esac
                done
                ;;
        esac
    done
    exec frankenphp run --config "$CFG" "$@"
fi

exec "$@"
