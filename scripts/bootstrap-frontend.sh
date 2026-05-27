#!/usr/bin/env bash
# ==============================================================
# scripts/bootstrap-frontend.sh
# --------------------------------------------------------------
# Crea el proyecto Vue 3 + TypeScript + Vite en frontend/ con
# Pinia, Vue Router, Tailwind, Dexie y Workbox preinstalados.
#
# DECISIÓN: NO usamos `npm create vue@latest` porque a la fecha
# instala Vite 8 y muchos plugins del ecosistema PWA todavía solo
# soportan Vite ^7. Construimos el proyecto manualmente con
# versiones controladas en package.json.
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

# --- Verificaciones previas ---
command -v docker >/dev/null 2>&1 || { red "✗ Docker no está instalado"; exit 1; }

if ! docker compose version >/dev/null 2>&1; then
    red "✗ Docker Compose plugin no disponible (docker compose, con espacio)"
    yellow "  Para instalarlo:"
    yellow "    Ubuntu/Debian: sudo apt install docker-compose-plugin"
    yellow "    Fedora:        sudo dnf install docker-compose-plugin"
    yellow "    Arch:          sudo pacman -S docker-compose"
    yellow "    Otros:         https://docs.docker.com/compose/install/linux/"
    exit 1
fi

cd "${ROOT}"

# Si ya existe pero está incompleto (caso de bootstrap previo fallido),
# permite re-empezar tras confirmación del usuario.
if [ -f "${FRONTEND_DIR}/package.json" ]; then
    if [ -d "${FRONTEND_DIR}/node_modules" ] && [ -f "${FRONTEND_DIR}/vite.config.ts" ]; then
        yellow "→ frontend/ ya existe con package.json + node_modules + vite.config.ts"
        yellow "  Asumiendo inicializado correctamente. Salgo sin hacer nada."
        exit 0
    fi
    yellow "⚠ frontend/ tiene package.json pero parece incompleto."
    yellow "  Voy a re-crear desde cero. ¿Continuar? (Ctrl+C para abortar, ENTER para continuar)"
    read -r _
    rm -rf "${FRONTEND_DIR}"/* "${FRONTEND_DIR}"/.[!.]* 2>/dev/null || true
fi

cyan "→ Creando proyecto Vue 3 + TypeScript con versiones fijadas..."

mkdir -p "${FRONTEND_DIR}/src/assets" "${FRONTEND_DIR}/public"

# package.json con versiones explícitas y compatibles entre sí
cat > "${FRONTEND_DIR}/package.json" << 'PKG_EOF'
{
  "name": "pos-pwa",
  "private": true,
  "version": "0.1.0",
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vue-tsc -b && vite build",
    "preview": "vite preview",
    "test:unit": "vitest run",
    "test:unit:watch": "vitest",
    "lint": "eslint . --ext .vue,.ts,.tsx",
    "lint:fix": "eslint . --ext .vue,.ts,.tsx --fix",
    "format": "prettier --write \"src/**/*.{ts,tsx,vue,css,scss,json,md}\"",
    "typecheck": "vue-tsc --noEmit"
  },
  "dependencies": {
    "@vueuse/core": "^11.2.0",
    "axios": "^1.7.9",
    "date-fns": "^4.1.0",
    "dexie": "^4.0.10",
    "laravel-echo": "^1.16.1",
    "pinia": "^2.2.6",
    "pusher-js": "^8.4.0",
    "vue": "^3.5.13",
    "vue-router": "^4.4.5",
    "zod": "^3.23.8"
  },
  "devDependencies": {
    "@tsconfig/node22": "^22.0.0",
    "@types/node": "^22.9.0",
    "@vitejs/plugin-vue": "^5.2.0",
    "@vue/eslint-config-prettier": "^10.1.0",
    "@vue/eslint-config-typescript": "^14.1.3",
    "@vue/test-utils": "^2.4.6",
    "@vue/tsconfig": "^0.7.0",
    "autoprefixer": "^10.4.20",
    "eslint": "^9.15.0",
    "eslint-plugin-vue": "^9.31.0",
    "jsdom": "^25.0.1",
    "postcss": "^8.4.49",
    "prettier": "^3.3.3",
    "tailwindcss": "^3.4.15",
    "typescript": "~5.6.3",
    "vite": "^5.4.11",
    "vite-plugin-pwa": "^0.21.1",
    "vitest": "^2.1.5",
    "vue-tsc": "^2.1.10",
    "workbox-window": "^7.3.0"
  }
}
PKG_EOF

cat > "${FRONTEND_DIR}/index.html" << 'HTML_EOF'
<!DOCTYPE html>
<html lang="es-MX">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <meta name="theme-color" content="#1e40af" />
    <title>POS Enterprise</title>
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="/src/main.ts"></script>
  </body>
</html>
HTML_EOF

cat > "${FRONTEND_DIR}/tsconfig.json" << 'TS_EOF'
{
  "files": [],
  "references": [
    { "path": "./tsconfig.app.json" },
    { "path": "./tsconfig.node.json" }
  ]
}
TS_EOF

cat > "${FRONTEND_DIR}/tsconfig.app.json" << 'TS_APP_EOF'
{
  "extends": "@vue/tsconfig/tsconfig.dom.json",
  "include": ["env.d.ts", "src/**/*", "src/**/*.vue"],
  "exclude": ["src/**/__tests__/*"],
  "compilerOptions": {
    "composite": true,
    "tsBuildInfoFile": "./node_modules/.tmp/tsconfig.app.tsbuildinfo",
    "baseUrl": ".",
    "paths": {
      "@/*": ["./src/*"]
    },
    "strict": true,
    "noImplicitAny": true,
    "strictNullChecks": true,
    "noUnusedLocals": true,
    "noUnusedParameters": true
  }
}
TS_APP_EOF

cat > "${FRONTEND_DIR}/tsconfig.node.json" << 'TS_NODE_EOF'
{
  "extends": "@tsconfig/node22/tsconfig.json",
  "include": ["vite.config.*", "vitest.config.*"],
  "compilerOptions": {
    "composite": true,
    "tsBuildInfoFile": "./node_modules/.tmp/tsconfig.node.tsbuildinfo",
    "module": "ESNext",
    "moduleResolution": "Bundler",
    "types": ["node"]
  }
}
TS_NODE_EOF

cat > "${FRONTEND_DIR}/env.d.ts" << 'ENV_EOF'
/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL: string;
  readonly VITE_REVERB_APP_KEY: string;
  readonly VITE_REVERB_HOST: string;
  readonly VITE_REVERB_PORT: string;
  readonly VITE_REVERB_SCHEME: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
ENV_EOF

cat > "${FRONTEND_DIR}/vite.config.ts" << 'VITE_EOF'
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'prompt',
      includeAssets: ['favicon.svg', 'robots.txt'],
      manifest: {
        name: 'POS Enterprise',
        short_name: 'POS',
        description: 'Sistema de punto de venta',
        theme_color: '#1e40af',
        background_color: '#ffffff',
        display: 'standalone',
        orientation: 'any',
        start_url: '/?source=pwa',
        icons: [
          { src: '/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: '/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,ico,woff2}'],
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
      },
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
  },
});
VITE_EOF

# Punto de entrada mínimo (se expande al implementar el shell de la PWA)
cat > "${FRONTEND_DIR}/src/main.ts" << 'MAIN_EOF'
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import './assets/main.css';

const app = createApp(App);
app.use(createPinia());
app.mount('#app');
MAIN_EOF

cat > "${FRONTEND_DIR}/src/App.vue" << 'APP_EOF'
<script setup lang="ts">
// Placeholder. Se reemplaza al implementar el shell de la PWA en Fase 1.
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-50 text-slate-900">
    <div class="text-center">
      <h1 class="text-3xl font-bold mb-2">POS Enterprise</h1>
      <p class="text-slate-600">Bootstrap completado. Listo para Fase 1.</p>
    </div>
  </div>
</template>
APP_EOF

cat > "${FRONTEND_DIR}/src/assets/main.css" << 'CSS_EOF'
@tailwind base;
@tailwind components;
@tailwind utilities;
CSS_EOF

cat > "${FRONTEND_DIR}/tailwind.config.js" << 'TW_EOF'
/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: { extend: {} },
  plugins: [],
};
TW_EOF

cat > "${FRONTEND_DIR}/postcss.config.js" << 'PC_EOF'
export default {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
};
PC_EOF

cat > "${FRONTEND_DIR}/.gitignore" << 'GI_EOF'
node_modules
dist
dist-ssr
*.local
.DS_Store
.vite
coverage
.env
.env.local
.env.*.local
GI_EOF

cyan "→ Instalando dependencias en frontend/ (1-3 min la primera vez)..."

docker run --rm \
    -v "${FRONTEND_DIR}:/app" \
    -u "$(id -u):$(id -g)" \
    -w /app \
    node:22-alpine \
    npm install

green ""
green "✓ Frontend Vue 3 + TS + Vite 5 inicializado correctamente"
green "  - Vite 5.4 (compatible con vite-plugin-pwa 0.21)"
green "  - Vue 3.5, TypeScript 5.6 strict"
green "  - Pinia, Vue Router, Tailwind 3, Dexie, Workbox, Laravel Echo"
green ""
cyan "Próximo paso:"
echo "  1. ./scripts/bootstrap-backend.sh   (si aún no lo corriste)"
echo "  2. make up                          (levanta toda la stack)"
