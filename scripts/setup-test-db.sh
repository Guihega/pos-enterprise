#!/usr/bin/env bash
# ==============================================================
# scripts/setup-test-db.sh
# --------------------------------------------------------------
# Crea la base de datos pos_test si no existe, para que los
# tests puedan correr con RefreshDatabase.
#
# Importante: TODO se ejecuta dentro del contenedor postgres del
# proyecto (no toca el Postgres del sistema host, que vive en
# otro puerto y otra base).
# ==============================================================

set -euo pipefail

cyan()   { printf '\033[0;36m%s\033[0m\n' "$*"; }
green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
red()    { printf '\033[0;31m%s\033[0m\n' "$*"; }

# Verificar que el contenedor Postgres del proyecto está corriendo
if ! docker compose ps postgres --format json | grep -q '"State":"running"'; then
    red "✗ El contenedor pos-postgres no está corriendo."
    red "  Levanta la stack primero: make up"
    exit 1
fi

cyan "→ Verificando si la base pos_test existe..."

EXISTS=$(docker compose exec -T postgres psql -U pos -d postgres -tAc \
    "SELECT 1 FROM pg_database WHERE datname='pos_test'")

if [ "$EXISTS" = "1" ]; then
    cyan "  Ya existe. Saltando creación."
else
    cyan "→ Creando base de datos pos_test..."
    docker compose exec -T postgres psql -U pos -d postgres \
        -c "CREATE DATABASE pos_test OWNER pos;"
    green "  ✓ Base pos_test creada."
fi

cyan "→ Aplicando init script (extensiones, función current_tenant_id)..."
docker compose exec -T postgres psql -U pos -d pos_test \
    -f /docker-entrypoint-initdb.d/01-init.sql > /dev/null 2>&1 || {
    red "✗ Falló al aplicar init script. Mostrando errores:"
    docker compose exec -T postgres psql -U pos -d pos_test \
        -f /docker-entrypoint-initdb.d/01-init.sql
    exit 1
}

green "✓ Base de datos pos_test lista para tests."
