#!/usr/bin/env bash
# ==============================================================
# scripts/bootstrap-backend.sh
# --------------------------------------------------------------
# Instala las dependencias de Composer del backend usando la
# IMAGEN PROPIA DEL PROYECTO (docker/php/Dockerfile target dev),
# que sí incluye todas las extensiones PHP requeridas (pcntl,
# bcmath, pdo_pgsql, redis, intl, etc.).
#
# NO usamos `composer:2` de Docker Hub porque su PHP base no
# tiene las extensiones que Horizon, Reverb y demás requieren.
#
# Idempotente: si ya hay vendor/, sale sin hacer nada.
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

if ! docker compose version >/dev/null 2>&1; then
    red "✗ Docker Compose plugin (v2) no disponible"
    yellow "  Instálalo: sudo apt install docker-compose-plugin"
    exit 1
fi

cd "${ROOT}"

# --- Verificar que el repo está completo ---
if [ ! -f "${BACKEND_DIR}/composer.json" ]; then
    red "✗ No encontré backend/composer.json"
    red "  El backend debe estar pre-poblado por nosotros antes de bootstrap."
    red "  Aplica el último tarball del proyecto antes de re-ejecutar este script."
    exit 1
fi

if [ -d "${BACKEND_DIR}/vendor" ] && [ -f "${BACKEND_DIR}/vendor/autoload.php" ]; then
    yellow "→ backend/vendor/ ya existe. Salgo sin hacer nada."
    yellow "  Si quieres reinstalar: rm -rf backend/vendor backend/composer.lock"
    exit 0
fi

# --- Construir la imagen del proyecto si no existe ---
cyan "→ Construyendo imagen Docker del proyecto (target development)..."
cyan "  (incluye PHP 8.3 + pcntl, bcmath, pdo_pgsql, redis, intl, gd, zip, opcache)"

docker build \
    -f docker/php/Dockerfile \
    --target development \
    --build-arg WWW_USER_ID=$(id -u) \
    --build-arg WWW_GROUP_ID=$(id -g) \
    -t pos-enterprise-backend-dev:latest \
    .

cyan "→ Instalando dependencias del backend con Composer (en imagen propia)..."

docker run --rm \
    -v "${BACKEND_DIR}:/var/www/html" \
    -w /var/www/html \
    pos-enterprise-backend-dev:latest \
    composer install --no-interaction --prefer-dist --no-progress --no-scripts

green ""
green "✓ Backend Laravel inicializado en backend/ con todas sus dependencias"
green ""
cyan "Próximos pasos:"
echo "  1. test -f .env || cp .env.example .env"
echo "  2. make up                         (levanta toda la stack)"
echo "  3. sleep 20 && make status         (verifica que todo está Up)"
