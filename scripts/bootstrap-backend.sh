#!/usr/bin/env bash
# ==============================================================
# scripts/bootstrap-backend.sh
# --------------------------------------------------------------
# Crea el proyecto Laravel 11 en backend/ y deja todo listo
# para Fase 1. Idempotente: si ya existe, no hace daño.
#
# Uso:
#   ./scripts/bootstrap-backend.sh
# ==============================================================

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="${ROOT}/backend"

cyan()   { printf '\033[0;36m%s\033[0m\n' "$*"; }
green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
red()    { printf '\033[0;31m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }

# --- Verificaciones previas ---
command -v docker >/dev/null 2>&1 || { red "✗ Docker no está instalado"; exit 1; }
docker compose version >/dev/null 2>&1 || { red "✗ Docker Compose plugin no disponible"; exit 1; }

cd "${ROOT}"

if [ -f "${BACKEND_DIR}/composer.json" ]; then
    yellow "→ backend/ ya existe con composer.json. Asumiendo proyecto inicializado."
    yellow "  Si quieres re-bootstrappear, borra backend/ primero (DESTRUCTIVO)."
    exit 0
fi

cyan "→ Creando proyecto Laravel 11 en backend/ usando contenedor temporal..."

# Limpia backend/ pero preserva el directorio
mkdir -p "${BACKEND_DIR}"
find "${BACKEND_DIR}" -mindepth 1 -delete

# Usa composer en contenedor para no requerir PHP local
docker run --rm \
    -v "${BACKEND_DIR}:/app" \
    -u "$(id -u):$(id -g)" \
    composer:2 \
    create-project --prefer-dist laravel/laravel:^11.0 /app --no-scripts --no-interaction

cyan "→ Instalando dependencias adicionales del proyecto..."

docker run --rm \
    -v "${BACKEND_DIR}:/app" \
    -u "$(id -u):$(id -g)" \
    -w /app \
    composer:2 \
    require --no-interaction \
        laravel/sanctum \
        laravel/reverb \
        laravel/horizon \
        spatie/laravel-permission \
        spatie/laravel-activitylog \
        ramsey/uuid \
        league/csv

cyan "→ Instalando dependencias de desarrollo..."

docker run --rm \
    -v "${BACKEND_DIR}:/app" \
    -u "$(id -u):$(id -g)" \
    -w /app \
    composer:2 \
    require --dev --no-interaction \
        laravel/pint \
        phpstan/phpstan \
        larastan/larastan \
        nunomaduro/collision \
        pestphp/pest \
        pestphp/pest-plugin-laravel \
        pestphp/pest-plugin-faker

cyan "→ Vinculando .env del monorepo al backend..."

if [ -f "${ROOT}/.env" ]; then
    ln -sf "${ROOT}/.env" "${BACKEND_DIR}/.env"
    green "✓ .env enlazado"
else
    yellow "⚠ No se encontró .env en raíz; copia .env.example primero"
fi

green ""
green "✓ Backend Laravel 11 inicializado en backend/"
green ""
cyan "Próximos pasos:"
echo "  1. make up"
echo "  2. make artisan cmd=\"key:generate\""
echo "  3. Continuar con Fase 1 (modelos, migraciones, etc.)"
