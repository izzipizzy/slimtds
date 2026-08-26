#!/bin/sh
# Проверка живости контейнера. Стучаться надо туда, где слушает конфиг
# активного режима, иначе healthcheck меряет не то: базовый вариант всегда
# ходил на http://localhost:80, а cf_full слушает 443, direct — только своё
# доменное имя. В обоих случаях контейнер оказывался вечно unhealthy при
# полностью исправном приложении.
#
# Выбор режима повторяет entrypoint.sh намеренно: CF_LISTEN_PORT экспортируется
# только в основной процесс и до healthcheck не доходит.
#
# cf_full здесь нет умышленно: entrypoint.sh отказывается стартовать в этом
# режиме, пока для него не сделан настоящий TLS, поэтому контейнер до проверки
# живости просто не доходит. Когда режим починят — вернуть сюда ветку вместе
# с ним.
set -eu

MODE="${DEPLOY_MODE:-dev}"

case "$MODE" in
    dev|cf_flex)
        # Caddy слушает :80 открытым текстом.
        exec curl -fsS --max-time 3 -o /dev/null http://127.0.0.1/__health
        ;;
    direct)
        # Единственный сайт в конфиге привязан к домену, поэтому запрос на
        # localhost в него не попадёт. Резолвим домен в петлю сами.
        if [ -z "${DOMAIN:-}" ]; then
            echo "healthcheck: DEPLOY_MODE=direct requires DOMAIN" >&2
            exit 1
        fi
        exec curl -fsSk --max-time 3 -o /dev/null \
            --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/__health"
        ;;
    *)
        echo "healthcheck: unknown DEPLOY_MODE=$MODE" >&2
        exit 1
        ;;
esac
