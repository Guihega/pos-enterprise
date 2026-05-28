/**
 * Store de autenticacion del POS Enterprise.
 *
 * Estado y operaciones de sesion:
 *  - Login con email+password contra /auth/login (Sanctum).
 *  - Persistencia del token Bearer en localStorage.
 *  - Sincronizacion del token con el cliente HTTP (setApiToken/clearApiToken).
 *  - Logout local + remoto (revoca el token actual en el backend).
 *
 * Deuda conocida (mover a cookies httpOnly en Etapa 5 de hardening):
 *   El token vive en localStorage, lo que lo expone a XSS. Para esta
 *   primera version es el patron mas simple alineado con Sanctum tokens.
 *   Migrar a cookies httpOnly requerira coordinar con el backend (CSRF,
 *   SameSite) y reescribir este store.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { login as apiLogin, logout as apiLogout, me as apiMe } from '@/lib/api/generated'
import type { User } from '@/lib/api/generated'
import { clearApiToken, setApiToken } from '@/lib/api/client'

/** Clave bajo la que se persiste el token en localStorage. */
const TOKEN_STORAGE_KEY = 'pos:auth:token'

/**
 * Devuelve los headers comunes a toda llamada del SDK.
 *
 * El cliente HTTP global ya inyecta X-Tenant via client.setConfig() en
 * initApiClient(), pero los tipos generados por Hey API exigen pasar
 * X-Tenant en cada llamada porque la spec OpenAPI lo marca como header
 * requerido. Los headers globales y los de la llamada se mergean.
 */
function commonHeaders(): { 'X-Tenant': string } {
  return {
    'X-Tenant': import.meta.env.VITE_DEV_TENANT ?? '',
  }
}

export const useAuthStore = defineStore('auth', () => {
  // ---- state ----
  const token = ref<string | null>(null)
  const user = ref<User | null>(null)

  // ---- getters ----
  const isAuthenticated = computed(() => token.value !== null && user.value !== null)

  // ---- actions ----

  /**
   * Inicia sesion contra el backend. Si tiene exito, guarda token + user,
   * persiste el token en localStorage y configura el cliente HTTP para
   * incluir el Authorization header en futuras peticiones.
   *
   * Lanza el error del SDK si las credenciales son invalidas o si hay
   * fallo de red; el caller decide como mostrarlo en UI.
   */
  async function login(email: string, password: string): Promise<void> {
    const { data, error } = await apiLogin({
      body: { email, password },
      headers: commonHeaders(),
    })

    if (error || !data) {
      throw error ?? new Error('Respuesta vacia del servidor en login')
    }

    token.value = data.data.token
    user.value = data.data.user
    localStorage.setItem(TOKEN_STORAGE_KEY, data.data.token)
    setApiToken(data.data.token)
  }

  /**
   * Cierra sesion: revoca el token en el backend y limpia el estado local.
   *
   * Si la llamada al backend falla (token ya invalido, red caida, etc.),
   * la limpieza local se hace de todas formas: el objetivo es siempre
   * dejar al usuario en estado deslogueado en el cliente.
   */
  async function logout(): Promise<void> {
    try {
      if (token.value) {
        await apiLogout({ headers: commonHeaders() })
      }
    } catch {
      // Ignoramos errores del backend: limpiar localmente es prioritario.
    } finally {
      token.value = null
      user.value = null
      localStorage.removeItem(TOKEN_STORAGE_KEY)
      clearApiToken()
    }
  }

  /**
   * Rehidrata la sesion al arrancar la app si hay un token en localStorage.
   *
   * Lee el token, lo inyecta al cliente HTTP y llama /auth/me para
   * recuperar el usuario. Si el token ya no es valido, el backend
   * respondera 401 y limpiamos todo en silencio.
   *
   * Se llama desde main.ts antes de montar la app.
   */
  async function hydrate(): Promise<void> {
    const stored = localStorage.getItem(TOKEN_STORAGE_KEY)
    if (!stored) {
      return
    }

    token.value = stored
    setApiToken(stored)

    const { data, error } = await apiMe({ headers: commonHeaders() })
    if (error || !data) {
      // Token caducado o invalido: limpiar y seguir como anonimos.
      token.value = null
      localStorage.removeItem(TOKEN_STORAGE_KEY)
      clearApiToken()
      return
    }

    user.value = data.data
  }

  return {
    // state
    token,
    user,
    // getters
    isAuthenticated,
    // actions
    login,
    logout,
    hydrate,
  }
})
