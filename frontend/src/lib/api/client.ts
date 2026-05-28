/**
 * Configuracion del cliente HTTP del POS Enterprise.
 *
 * Envuelve el cliente generado por Hey API (`@/lib/api/generated`) y le
 * inyecta baseUrl y, cuando hay sesion, el token Bearer + el tenant
 * activo.
 *
 * El SDK generado (login, me, logout, etc.) usa internamente este mismo
 * cliente como instancia global; basta con configurarlo una vez al
 * arrancar la app (ver `main.ts`) y todas las llamadas del SDK ya van
 * apuntadas al backend correcto.
 *
 * Politica de headers:
 *  - `initApiClient()` setea solo baseUrl + Content-Type/Accept.
 *  - `setApiTenant(slug)` se llama al login exitoso y al hydrate.
 *  - `setApiToken(token)` se llama al login exitoso y al hydrate.
 *  - `clearApiTenant()` / `clearApiToken()` al logout.
 *
 * El tenant del login mismo (cuando aun no hay sesion) se pasa en el
 * `headers` de la llamada `apiLogin()`, no aqui.
 */
import { client } from './generated/client.gen'

/**
 * Inicializa el cliente con la configuracion base de la app.
 *
 * Llamar una sola vez en `main.ts`, antes de montar la app.
 * Despues de esta llamada, las peticiones todavia no llevan tenant ni
 * token; el store de auth los inyecta segun corresponda.
 */
export function initApiClient(): void {
  const baseUrl = import.meta.env.VITE_API_URL

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
    },
  })
}

/** Inyecta el slug del tenant en futuras peticiones del cliente. */
export function setApiTenant(slug: string): void {
  const current = client.getConfig()
  client.setConfig({
    ...current,
    headers: {
      ...current.headers,
      'X-Tenant': slug,
    },
  })
}

/** Quita el header X-Tenant del cliente. */
export function clearApiTenant(): void {
  const current = client.getConfig()
  const headers = { ...current.headers }
  delete (headers as Record<string, string>)['X-Tenant']
  client.setConfig({
    ...current,
    headers,
  })
}

/** Inyecta el token Bearer en futuras peticiones del cliente. */
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

/** Quita el token Bearer del cliente. */
export function clearApiToken(): void {
  const current = client.getConfig()
  const headers = { ...current.headers }
  delete (headers as Record<string, string>).Authorization
  client.setConfig({
    ...current,
    headers,
  })
}
