#!/usr/bin/env bash
# ==============================================================
# scripts/wait-for-services.sh
# --------------------------------------------------------------
# Espera a que Postgres y Redis estén listos antes de continuar.
# Útil tras `make up` o en CI.
#
# Uso:
#   ./scripts/wait-for-services.sh [timeout_seconds]
#   ./scripts/wait-for-services.sh 60
# ==============================================================

set -euo pipefail

TIMEOUT="${1:-60}"
ELAPSED=0
INTERVAL=2

green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
red()    { printf '\033[0;31m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }

check_postgres() {
    docker compose exec -T postgres pg_isready -U pos -d pos >/dev/null 2>&1
}

check_redis() {
    docker compose exec -T redis redis-cli -a redis_dev_secret_change_me ping 2>/dev/null | grep -q PONG
}

yellow "Esperando a Postgres y Redis (timeout: ${TIMEOUT}s)..."

while [ $ELAPSED -lt $TIMEOUT ]; do
    PG_OK=false
    REDIS_OK=false

    check_postgres && PG_OK=true
    check_redis && REDIS_OK=true

    if $PG_OK && $REDIS_OK; then
        green "✓ Postgres y Redis listos (${ELAPSED}s)"
        exit 0
    fi

    sleep $INTERVAL
    ELAPSED=$((ELAPSED + INTERVAL))
    printf '.'
done

echo ""
red "✗ Timeout esperando servicios"
red "  Postgres: $($PG_OK && echo 'OK' || echo 'NO RESPONDE')"
red "  Redis:    $($REDIS_OK && echo 'OK' || echo 'NO RESPONDE')"
exit 1
