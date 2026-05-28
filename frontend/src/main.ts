import './assets/main.css'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { initApiClient } from '@/lib/api/client'
import { installAuthInterceptor } from '@/lib/api/authInterceptor'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

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

  app.use(router)
  app.mount('#app')
}

void bootstrap()
