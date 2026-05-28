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
}))

import { login as apiLogin, logout as apiLogout, me as apiMe } from '@/lib/api/generated'
import { clearApiToken, setApiToken } from '@/lib/api/client'

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
    expect(auth.user).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
  })

  it('login exitoso guarda token, user y configura el cliente', async () => {
    vi.mocked(apiLogin).mockResolvedValue({
      data: {
        data: { user: fakeUser, token: 'aks-tok', token_type: 'Bearer' },
      },
      error: undefined,
    } as unknown)

    const auth = useAuthStore()
    await auth.login('alice@example.com', 'secret')

    expect(auth.token).toBe('aks-tok')
    expect(auth.user).toEqual(fakeUser)
    expect(auth.isAuthenticated).toBe(true)
    expect(localStorage.getItem('pos:auth:token')).toBe('aks-tok')
    expect(setApiToken).toHaveBeenCalledWith('aks-tok')
  })

  it('login fallido lanza error y deja el store intacto', async () => {
    vi.mocked(apiLogin).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'UNAUTH', message: 'bad creds' } },
    } as unknown)

    const auth = useAuthStore()

    await expect(auth.login('a@ex.com', 'x')).rejects.toBeTruthy()
    expect(auth.token).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('pos:auth:token')).toBeNull()
    expect(setApiToken).not.toHaveBeenCalled()
  })

  it('logout limpia estado persistido y headers', async () => {
    vi.mocked(apiLogin).mockResolvedValue({
      data: {
        data: { user: fakeUser, token: 'tok1', token_type: 'Bearer' },
      },
      error: undefined,
    } as unknown)
    vi.mocked(apiLogout).mockResolvedValue({ data: {}, error: undefined } as unknown)

    const auth = useAuthStore()
    await auth.login('a@ex.com', 'x')
    expect(auth.isAuthenticated).toBe(true)

    await auth.logout()

    expect(auth.token).toBeNull()
    expect(auth.user).toBeNull()
    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('pos:auth:token')).toBeNull()
    expect(clearApiToken).toHaveBeenCalled()
  })

  it('hydrate restaura sesion cuando hay token valido en localStorage', async () => {
    localStorage.setItem('pos:auth:token', 'prev-tok')
    vi.mocked(apiMe).mockResolvedValue({
      data: { data: fakeUser },
      error: undefined,
    } as unknown)

    const auth = useAuthStore()
    await auth.hydrate()

    expect(auth.token).toBe('prev-tok')
    expect(auth.user).toEqual(fakeUser)
    expect(auth.isAuthenticated).toBe(true)
    expect(setApiToken).toHaveBeenCalledWith('prev-tok')
  })

  it('hydrate con token invalido limpia en silencio', async () => {
    localStorage.setItem('pos:auth:token', 'bad-tok')
    vi.mocked(apiMe).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'UNAUTH', message: 'bad tok' } },
    } as unknown)

    const auth = useAuthStore()
    await auth.hydrate()

    expect(auth.token).toBeNull()
    expect(auth.user).toBeNull()
    expect(localStorage.getItem('pos:auth:token')).toBeNull()
    expect(clearApiToken).toHaveBeenCalled()
  })
})
