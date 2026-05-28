import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useAuthStore } from '@/stores/auth'

/**
 * Mock del SDK generado por Hey API.
 *
 * Reemplazamos los metodos que usa el store (login, logout, me) por
 * stubs controlables por primitivas de Vitest.
 */
vi.mock('@/lib/api/generated', () => ({
  login: vi.fn<typeof apiLogin>(),
  logout: vi.fn<typeof apiLogout>(),
  me: vi.fn<typeof apiMe>(),
}))

/**
 * Mock del archivo client.ts (nosotros) para aislar el store de
 * los efectos de configuracion del cliente HTTP.
 */
vi.mock('@/lib/api/client', () => ({
  setApiToken: vi.fn<typeof setApiToken>(),
  clearApiToken: vi.fn<typeof clearApiToken>(),
  setApiTenant: vi.fn<typeof setApiTenant>(),
  clearApiTenant: vi.fn<typeof clearApiTenant>(),
}))

import { login as apiLogin, logout as apiLogout, me as apiMe } from '@/lib/api/generated'
import {
  clearApiTenant,
  clearApiToken,
  setApiTenant,
  setApiToken,
} from '@/lib/api/client'

/** Shape de respuesta exitosa de login que usa el SDK. */
const fakeUser = {
  uuid: 'u-1',
  name: 'Alice',
  email: 'alice@example.com',
  is_active: true,
  must_change_password: false,
  two_factor_enabled: false,
  has_pin: false,
  roles: ['admin'],
}

/** Limpia localStorage entre tests para ensuramento. */
beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  vi.clearAllMocks()
})

describe('auth store', () => {
  it('al inicio, esta deslogueado', () => {
    const auth = useAuthStore()
    expect(auth.token).toBeNull()
    expect(auth.tenant).toBeNull()
    expect(auth.user).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
  })

  it('login exitoso guarda token, tenant, user y configura el cliente', async () => {
    vi.mocked(apiLogin).mockResolvedValue({
      data: {
        data: { user: fakeUser, token: 'aks-tok', token_type: 'Bearer' },
      },
      error: undefined,
    } as unknown)

    const auth = useAuthStore()
    await auth.login('alice@example.com', 'secret', 'acme')

    expect(auth.token).toBe('aks-tok')
    expect(auth.tenant).toBe('acme')
    expect(auth.user).toEqual(fakeUser)
    expect(auth.isAuthenticated).toBe(true)
    expect(localStorage.getItem('pos:auth:token')).toBe('aks-tok')
    expect(localStorage.getItem('pos:auth:tenant')).toBe('acme')
    expect(setApiTenant).toHaveBeenCalledWith('acme')
    expect(setApiToken).toHaveBeenCalledWith('aks-tok')
  })

  it('login pasa el tenant en el header X-Tenant de la llamada', async () => {
    vi.mocked(apiLogin).mockResolvedValue({
      data: {
        data: { user: fakeUser, token: 't', token_type: 'Bearer' },
      },
      error: undefined,
    } as unknown)

    const auth = useAuthStore()
    await auth.login('a@ex.com', 'x', 'tenant-z')

    expect(apiLogin).toHaveBeenCalledWith({
      body: { email: 'a@ex.com', password: 'x' },
      headers: { 'X-Tenant': 'tenant-z' },
    })
  })

  it('login fallido lanza error y deja el store intacto', async () => {
    vi.mocked(apiLogin).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'UNAUTH', message: 'bad creds' } },
    } as unknown)

    const auth = useAuthStore()

    await expect(auth.login('a@ex.com', 'x', 'acme')).rejects.toBeTruthy()
    expect(auth.token).toBeNull()
    expect(auth.tenant).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('pos:auth:token')).toBeNull()
    expect(localStorage.getItem('pos:auth:tenant')).toBeNull()
    expect(setApiToken).not.toHaveBeenCalled()
    expect(setApiTenant).not.toHaveBeenCalled()
  })

  it('logout limpia estado persistido, headers y tenant', async () => {
    vi.mocked(apiLogin).mockResolvedValue({
      data: {
        data: { user: fakeUser, token: 'tok1', token_type: 'Bearer' },
      },
      error: undefined,
    } as unknown)
    vi.mocked(apiLogout).mockResolvedValue({
      data: {},
      error: undefined,
    } as unknown)

    const auth = useAuthStore()
    await auth.login('a@ex.com', 'x', 'acme')
    expect(auth.isAuthenticated).toBe(true)

    await auth.logout()

    expect(auth.token).toBeNull()
    expect(auth.tenant).toBeNull()
    expect(auth.user).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('pos:auth:token')).toBeNull()
    expect(localStorage.getItem('pos:auth:tenant')).toBeNull()
    expect(clearApiToken).toHaveBeenCalled()
    expect(clearApiTenant).toHaveBeenCalled()
  })

  it('hydrate restaura sesion cuando hay token y tenant validos en localStorage', async () => {
    localStorage.setItem('pos:auth:token', 'prev-tok')
    localStorage.setItem('pos:auth:tenant', 'acme')
    vi.mocked(apiMe).mockResolvedValue({
      data: { data: fakeUser },
      error: undefined,
    } as unknown)

    const auth = useAuthStore()
    await auth.hydrate()

    expect(auth.token).toBe('prev-tok')
    expect(auth.tenant).toBe('acme')
    expect(auth.user).toEqual(fakeUser)
    expect(auth.isAuthenticated).toBe(true)
    expect(setApiToken).toHaveBeenCalledWith('prev-tok')
    expect(setApiTenant).toHaveBeenCalledWith('acme')
  })

  it('hydrate sin token o sin tenant en localStorage no hace nada', async () => {
    // Solo token, sin tenant
    localStorage.setItem('pos:auth:token', 'orphan-tok')

    const auth = useAuthStore()
    await auth.hydrate()

    expect(auth.token).toBeNull()
    expect(auth.tenant).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
    expect(setApiToken).not.toHaveBeenCalled()
    expect(apiMe).not.toHaveBeenCalled()
  })

  it('hydrate con token invalido limpia en silencio', async () => {
    localStorage.setItem('pos:auth:token', 'bad-tok')
    localStorage.setItem('pos:auth:tenant', 'acme')
    vi.mocked(apiMe).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'UNAUTH', message: 'bad tok' } },
    } as unknown)

    const auth = useAuthStore()
    await auth.hydrate()

    expect(auth.token).toBeNull()
    expect(auth.tenant).toBeNull()
    expect(auth.user).toBeNull()
    expect(localStorage.getItem('pos:auth:token')).toBeNull()
    expect(localStorage.getItem('pos:auth:tenant')).toBeNull()
    expect(clearApiToken).toHaveBeenCalled()
    expect(clearApiTenant).toHaveBeenCalled()
  })
})
