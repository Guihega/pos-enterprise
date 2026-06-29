/**
 * Store de autenticacion del POS Enterprise.
 *
 * Estado y operaciones de sesion:
 *  - Login con tenant + email + password contra /auth/login (Sanctum).
 *  - Persistencia de token y tenant en localStorage.
 *  - Sincronizacion de token y tenant con el cliente HTTP (set/clearApi*).
 *  - Logout local + remoto (revoca el token actual en el backend).
 *
 * Deuda conocida (mover a cookies httpOnly en Etapa 5 de hardening):
 *   El token vive en localStorage, lo que lo expone a XSS. Para esta
 *   primera version es el patron mas simple alineado con Sanctum tokens.
 *   Migrar a cookies httpOnly requerira coordinar con el backend (CSRF,
 *   SameSite) y reescribir este store.
 *
 * Deuda conocida (resolver con subdominios en produccion):
 *   El tenant se pasa explicitamente como string en el form de login y
 *   en el header X-Tenant. En produccion deberia resolverse por
 *   subdominio (tenant1.miapp.com) y este store dejaria de manipular
 *   el tenant directamente.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { login as apiLogin, logout as apiLogout, me as apiMe } from '@/lib/api/generated'
import type { User } from '@/lib/api/generated'
import {
  clearApiTenant,
  clearApiToken,
  setApiTenant,
  setApiToken,
} from '@/lib/api/client'

/** Claves bajo las que se persiste el estado en localStorage. */
const TOKEN_STORAGE_KEY = 'pos:auth:token'
const TENANT_STORAGE_KEY = 'pos:auth:tenant'

export const useAuthStore = defineStore('auth', () => {
  // ---- state ----
  const token = ref<string | null>(null)
  const tenant = ref<string | null>(null)
  const user = ref<User | null>(null)

  // ---- getters ----
  const isAuthenticated = computed(
    () => token.value !== null && user.value !== null,
  )

  // ---- actions ----

  /**
   * Inicia sesion contra el backend. Si tiene exito, guarda token,
   * tenant y user; los persiste en localStorage y configura el cliente
   * HTTP para incluir Authorization y X-Tenant en futuras peticiones.
   *
   * El tenant se pasa explicitamente como header en la propia llamada
   * de login porque aun no esta inyectado en el cliente global.
   *
   * Lanza el error del SDK si las credenciales son invalidas o si hay
   * fallo de red; el caller decide como mostrarlo en UI.
   */
  async function login(
    email: string,
    password: string,
    tenantSlug: string,
  ): Promise<void> {
    const { data, error } = await apiLogin({
      body: { email, password },
      headers: { 'X-Tenant': tenantSlug },
    })

    if (error || !data) {
      throw error ?? new Error('Respuesta vacia del servidor en login')
    }

    token.value = data.data.token
    tenant.value = tenantSlug
    user.value = data.data.user

    localStorage.setItem(TOKEN_STORAGE_KEY, data.data.token)
    localStorage.setItem(TENANT_STORAGE_KEY, tenantSlug)

    setApiTenant(tenantSlug)
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
      if (token.value && tenant.value) {
        await apiLogout({ headers: { 'X-Tenant': tenant.value } })
      }
    } catch {
      // Ignoramos errores del backend: limpiar localmente es prioritario.
    } finally {
      token.value = null
      tenant.value = null
      user.value = null
      localStorage.removeItem(TOKEN_STORAGE_KEY)
      localStorage.removeItem(TENANT_STORAGE_KEY)
      clearApiToken()
      clearApiTenant()
    }
  }

  /**
   * Limpia el state de sesion sin llamar al backend.
   *
   * Para casos donde sabemos que el token ya no sirve y llamar a
   * /auth/logout seria inutil: detectado por el interceptor de 401,
   * sesion expirada, etc. Si quieres revocar el token explicitamente
   * en el backend, usa `logout()`.
   */
  function forceLogout(): void {
    token.value = null
    tenant.value = null
    user.value = null
    localStorage.removeItem(TOKEN_STORAGE_KEY)
    localStorage.removeItem(TENANT_STORAGE_KEY)
    clearApiToken()
    clearApiTenant()
  }

  /**
   * Rehidrata la sesion al arrancar la app si hay token y tenant en
   * localStorage. Si falta uno de los dos o /auth/me devuelve error,
   * limpia todo en silencio y la app sigue como anonima.
   *
   * Se llama desde main.ts antes de montar la app.
   */
  async function hydrate(): Promise<void> {
    const storedToken = localStorage.getItem(TOKEN_STORAGE_KEY)
    const storedTenant = localStorage.getItem(TENANT_STORAGE_KEY)

    if (!storedToken || !storedTenant) {
      return
    }

    token.value = storedToken
    tenant.value = storedTenant
    setApiToken(storedToken)
    setApiTenant(storedTenant)

    const { data, error } = await apiMe({
      headers: { 'X-Tenant': storedTenant },
    })

    if (error || !data) {
      // Token caducado o invalido: limpiar y seguir como anonimos.
      token.value = null
      tenant.value = null
      localStorage.removeItem(TOKEN_STORAGE_KEY)
      localStorage.removeItem(TENANT_STORAGE_KEY)
      clearApiToken()
      clearApiTenant()
      return
    }

    user.value = data.data
  }

  return {
    // state
    token,
    tenant,
    user,
    // getters
    isAuthenticated,
    // actions
    login,
    logout,
    forceLogout,
    hydrate,
  }
})
