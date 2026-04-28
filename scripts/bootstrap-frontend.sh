#!/usr/bin/env bash
# ==============================================================
# scripts/bootstrap-frontend.sh
# --------------------------------------------------------------
# Crea el proyecto Vue 3 + TypeScript + Vite en frontend/ con
# Pinia, Vue Router, Tailwind, Dexie y Workbox preinstalados.
#
# Uso:
#   ./scripts/bootstrap-frontend.sh
# ==============================================================

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FRONTEND_DIR="${ROOT}/frontend"

cyan()   { printf '\033[0;36m%s\033[0m\n' "$*"; }
green()  { printf '\033[0;32m%s\033[0m\n' "$*"; }
red()    { printf '\033[0;31m%s\033[0m\n' "$*"; }
yellow() { printf '\033[0;33m%s\033[0m\n' "$*"; }

cd "${ROOT}"

if [ -f "${FRONTEND_DIR}/package.json" ]; then
    yellow "→ frontend/ ya existe con package.json. Asumiendo inicializado."
    exit 0
fi

cyan "→ Creando proyecto Vue 3 + TypeScript en frontend/ usando contenedor Node..."

mkdir -p "${FRONTEND_DIR}"

# Crea el proyecto base con npm create vue@latest dentro de un contenedor
docker run --rm \
    -v "${FRONTEND_DIR}:/app" \
    -u "$(id -u):$(id -g)" \
    -w /tmp \
    node:22-alpine \
    sh -c '
        npm create vue@latest pos-pwa -- \
            --typescript \
            --jsx false \
            --router \
            --pinia \
            --vitest \
            --eslint \
            --prettier \
            --force &&
        cp -r /tmp/pos-pwa/. /app/
    '

cyan "→ Instalando dependencias adicionales de la PWA..."

docker run --rm \
    -v "${FRONTEND_DIR}:/app" \
    -u "$(id -u):$(id -g)" \
    -w /app \
    node:22-alpine \
    npm install \
        dexie \
        axios \
        @vueuse/core \
        zod \
        date-fns \
        @vueuse/components \
        laravel-echo \
        pusher-js

cyan "→ Instalando dependencias de desarrollo (Tailwind, Workbox, etc.)..."

docker run --rm \
    -v "${FRONTEND_DIR}:/app" \
    -u "$(id -u):$(id -g)" \
    -w /app \
    node:22-alpine \
    npm install --save-dev \
        tailwindcss@^3 \
        postcss \
        autoprefixer \
        vite-plugin-pwa \
        workbox-window \
        @types/node

green ""
green "✓ Frontend Vue 3 + TS inicializado en frontend/"
green ""
cyan "Próximos pasos:"
echo "  1. cd frontend && npx tailwindcss init -p"
echo "  2. Configurar tailwind.config.js, vite.config.ts (PWA plugin)"
echo "  3. Continuar con Fase 1 (UI base, login, POS shell)"
