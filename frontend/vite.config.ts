import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import vueDevTools from 'vite-plugin-vue-devtools'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueJsx(),
    vueDevTools(),
    // PWA + Service Worker (doc maestro sec. 37). generateSW (Workbox)
    // implementa las estrategias de cache de 37.1; registerType 'prompt'
    // respeta el update manual-approve de 37.2 (no activa SW nuevo a
    // media venta); manifest de 37.3.
    VitePWA({
      registerType: 'prompt',
      injectRegister: null, // el registro lo maneja la app (main.ts)
      devOptions: {
        // SW desactivado en dev para no interferir con HMR de Vite.
        enabled: false,
      },
      manifest: {
        name: 'POS Enterprise',
        short_name: 'POS',
        start_url: '/?source=pwa',
        display: 'standalone',
        orientation: 'any',
        theme_color: '#1e40af',
        background_color: '#ffffff',
        icons: [
          { src: '/favicon.ico', sizes: '64x64', type: 'image/x-icon' },
        ],
      },
      workbox: {
        // 1. Precache del shell (HTML/JS/CSS/fuentes).
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff,woff2}'],
        cleanupOutdatedCaches: true,
        // 2. Navigation -> app shell, excluyendo API/admin (37.1 punto 2).
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//, /^\/admin\//],
        runtimeCaching: [
          // 3. Imagenes -> CacheFirst con expiracion (37.1 punto 3).
          {
            urlPattern: ({ request }) => request.destination === 'image',
            handler: 'CacheFirst',
            options: {
              cacheName: 'images',
              expiration: { maxEntries: 500, maxAgeSeconds: 30 * 24 * 60 * 60 },
              cacheableResponse: { statuses: [0, 200] },
            },
          },
          // 4. GET de producto individual -> StaleWhileRevalidate (37.1 punto 4).
          {
            urlPattern: /\/api\/v1\/products\/[a-f0-9-]+$/,
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'products-api',
              expiration: { maxEntries: 5000, maxAgeSeconds: 24 * 60 * 60 },
            },
          },
          // 6. Resto de API GET -> NetworkFirst con fallback (37.1 punto 6).
          {
            urlPattern: /\/api\//,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'api',
              networkTimeoutSeconds: 10,
              expiration: { maxEntries: 200, maxAgeSeconds: 60 * 60 },
            },
          },
        ],
      },
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    },
  },
})
