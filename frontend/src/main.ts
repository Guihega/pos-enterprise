import './assets/main.css'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { initApiClient } from '@/lib/api/client'
import { useAuthStore } from '@/stores/auth'

async function bootstrap(): Promise<void> {
  const app = createApp(App)
  const pinia = createPinia()

  app.use(pinia)

  // Configura el cliente HTTP (baseUrl, X-Tenant) antes de que Pinia
  // o el router puedan disparar peticiones.
  initApiClient()

  // Rehidrata la sesion desde localStorage si hay token guardado.
  // Si el token caduco, hydrate() limpia silenciosamente.
  const auth = useAuthStore()
  await auth.hydrate()

  app.use(router)
  app.mount('#app')
}

void bootstrap()
