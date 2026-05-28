/**
 * Configuracion del cliente HTTP del POS Enterprise.
 *
 * Envuelve el cliente generado por Hey API (`@/lib/api/generated`) y le
 * inyecta baseUrl, headers de tenant y, cuando hay sesion, el token Bearer.
 *
 * El SDK generado (login, me, logout, etc.) usa internamente este mismo
 * cliente como instancia global; basta con configurarlo una vez al
 * arrancar la app (ver `main.ts`) y todas las llamadas del SDK ya van
 * apuntadas al backend correcto y autenticadas si corresponde.
 */
import { client } from './generated/client.gen'

/**
 * Inicializa el cliente con la configuracion base de la app.
 *
 * Llamar una sola vez en `main.ts`, antes de montar la app.
 */
export function initApiClient(): void {
  const baseUrl = import.meta.env.VITE_API_URL
  const tenant = import.meta.env.VITE_DEV_TENANT

  if (!baseUrl) {
    throw new Error(
      'VITE_API_URL no esta definida. Revisa docker-compose.yml o tu archivo .env.',
    )
  }

  client.setConfig({
    baseUrl,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(tenant ? { 'X-Tenant': tenant } : {}),
    },
  })
}

/**
 * Inyecta el token Bearer en futuras peticiones del cliente.
 *
 * Se llama desde el store de auth cuando el usuario inicia sesion exitosa
 * o cuando se restaura el token desde localStorage al arrancar.
 */
export function setApiToken(token: string): void {
  const current = client.getConfig()
  client.setConfig({
    ...current,
    headers: {
      ...current.headers,
      Authorization: `Bearer ${token}`,
    },
  })
}

/**
 * Quita el token Bearer del cliente.
 *
 * Se llama desde el store de auth cuando el usuario cierra sesion o el
 * token deja de ser valido.
 */
export function clearApiToken(): void {
  const current = client.getConfig()
  const headers = { ...current.headers }
  delete (headers as Record<string, string>).Authorization
  client.setConfig({
    ...current,
    headers,
  })
}
