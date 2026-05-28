import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { client } from '@/lib/api/generated/client.gen'
import { installAuthInterceptor } from '@/lib/api/authInterceptor'
import { useAuthStore } from '@/stores/auth'

/**
 * Mock del router para verificar redirects sin necesitar una instancia
 * Vue real. Imitamos la API minima que usa el interceptor:
 *   - router.currentRoute.value.name
 *   - router.push(...)
 */
vi.mock('@/router', () => ({
  default: {
    currentRoute: { value: { name: 'pos' as string } },
    push: vi.fn<(to: { name: string }) => Promise<void>>(),
  },
}))

import router from '@/router'

let interceptorId: number | undefined

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  // Restaurar currentRoute a un estado por defecto (no /login).
  router.currentRoute.value = { name: 'pos' }
  // Instalar el interceptor antes de cada test.
  interceptorId = installAuthInterceptor()
})

afterEach(() => {
  // Limpiar el interceptor instalado para que no contamine otros tests.
  if (interceptorId !== undefined) {
    client.interceptors.error.eject(interceptorId)
    interceptorId = undefined
  }
})

/** Construye un Request fake para una URL dada. */
function fakeRequest(url: string): Request {
  return new Request(url)
}

/** Construye una Response fake con el status dado. */
function fakeResponse(status: number): Response {
  return new Response(null, { status })
}

/** Invoca todos los interceptors de error registrados, simulando el flujo del cliente. */
async function runErrorInterceptors(
  error: unknown,
  response: Response | undefined,
  request: Request | undefined,
): Promise<unknown> {
  let finalError = error
  for (const fn of client.interceptors.error.fns) {
    if (fn) {
      finalError = await fn(
        finalError,
        response,
        request,
        // El cuarto argumento es options; el interceptor no lo usa.
        {} as never,
      )
    }
  }
  return finalError
}

describe('authInterceptor', () => {
  it('en 401 fuera de /login: dispara forceLogout y redirige a /login', async () => {
    const auth = useAuthStore()
    // Simular sesion previa.
    localStorage.setItem('pos:auth:token', 'tok')
    localStorage.setItem('pos:auth:tenant', 'acme')
    auth.token = 'tok'
    auth.tenant = 'acme'

    await runErrorInterceptors(
      new Error('Unauthorized'),
      fakeResponse(401),
      fakeRequest('http://localhost:8080/api/v1/auth/me'),
    )

    expect(auth.token).toBeNull()
    expect(auth.tenant).toBeNull()
    expect(localStorage.getItem('pos:auth:token')).toBeNull()
    expect(localStorage.getItem('pos:auth:tenant')).toBeNull()
    expect(router.push).toHaveBeenCalledWith({ name: 'login' })
  })

  it('en 401 desde /auth/login: NO dispara forceLogout ni redirect', async () => {
    const auth = useAuthStore()
    auth.token = null
    auth.tenant = null

    await runErrorInterceptors(
      new Error('Bad credentials'),
      fakeResponse(401),
      fakeRequest('http://localhost:8080/api/v1/auth/login'),
    )

    // El interceptor debe ignorar este caso completamente.
    expect(router.push).not.toHaveBeenCalled()
  })

  it('en error NO-401 (ej. 500): no hace nada', async () => {
    const auth = useAuthStore()
    auth.token = 'tok'
    auth.tenant = 'acme'

    await runErrorInterceptors(
      new Error('Server error'),
      fakeResponse(500),
      fakeRequest('http://localhost:8080/api/v1/auth/me'),
    )

    expect(auth.token).toBe('tok')
    expect(auth.tenant).toBe('acme')
    expect(router.push).not.toHaveBeenCalled()
  })

  it('si ya estamos en /login, no hace push duplicado', async () => {
    router.currentRoute.value = { name: 'login' }
    const auth = useAuthStore()
    auth.token = 'tok'
    auth.tenant = 'acme'

    await runErrorInterceptors(
      new Error('Unauthorized'),
      fakeResponse(401),
      fakeRequest('http://localhost:8080/api/v1/auth/me'),
    )

    // forceLogout si se ejecuto (sesion limpiada).
    expect(auth.token).toBeNull()
    // Pero el redirect NO se hace porque ya estamos en login.
    expect(router.push).not.toHaveBeenCalled()
  })

  it('sin response (ej. error de red): no hace nada', async () => {
    const auth = useAuthStore()
    auth.token = 'tok'
    auth.tenant = 'acme'

    await runErrorInterceptors(
      new Error('Network error'),
      undefined,
      fakeRequest('http://localhost:8080/api/v1/auth/me'),
    )

    expect(auth.token).toBe('tok')
    expect(router.push).not.toHaveBeenCalled()
  })
})
