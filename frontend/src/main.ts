import './assets/main.css'
import '@tabler/icons-webfont/dist/tabler-icons.css'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { initApiClient } from '@/lib/api/client'
import { installAuthInterceptor } from '@/lib/api/authInterceptor'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { useSyncStore } from '@/stores/sync'

async function bootstrap(): Promise<void> {
  const app = createApp(App)
  const pinia = createPinia()

  app.use(pinia)

  // Configura el cliente HTTP (baseUrl, Content-Type/Accept) antes de
  // que Pinia o el router puedan disparar peticiones.
  initApiClient()

  // Registra el interceptor que escucha 401 globales y dispara logout
  // automatico. Necesita Pinia ya inicializado (usa useAuthStore) y el
  // router ya importado. Se instala una sola vez.
  installAuthInterceptor()

  // Rehidrata la sesion desde localStorage si hay token guardado.
  // Si el token caduco, hydrate() limpia silenciosamente (y de paso
  // el interceptor tambien lo manejaria via 401).
  const auth = useAuthStore()
  await auth.hydrate()

  // Rehidratar el carrito si pertenece al tenant restaurado. Debe ir
  // DESPUES de auth.hydrate() porque depende de authStore.tenant.
  const cart = useCartStore()
  cart.hydrate()

  // Motor de sync en background (doc 35.4 pasos 6-9). Se arranca si la
  // sesion quedo hidratada, y se sincroniza con las transiciones de auth
  // via $onAction para no crear dependencia circular auth -> sync.
  const sync = useSyncStore()
  if (auth.isAuthenticated) {
    sync.start()
    // Repuebla IndexedDB si hace falta (38.6, 35.4 paso 5). No se hace
    // await: el arranque de la UI no se bloquea por el snapshot; el
    // progreso se observa via sync.snapshotProgress.
    void sync.ensureSnapshot()
  }
  auth.$onAction(({ name, after }) => {
    after(() => {
      if (name === 'login') {
        sync.start()
        void sync.ensureSnapshot()
      } else if (name === 'logout' || name === 'forceLogout') {
        sync.stop()
      }
    })
  })

  app.use(router)
  app.mount('#app')
}

void bootstrap()
