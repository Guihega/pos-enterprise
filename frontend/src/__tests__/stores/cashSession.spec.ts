import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useCashSessionStore } from '@/stores/cashSession'

/**
 * Mock del SDK generado. Solo nos importan los 3 endpoints de cash
 * que el store consume.
 */
vi.mock('@/lib/api/generated', () => ({
  listCashRegisters: vi.fn<typeof apiListCashRegisters>(),
  listCashSessions: vi.fn<typeof apiListCashSessions>(),
  openCashSession: vi.fn<typeof apiOpenCashSession>(),
  closeCashSession: vi.fn<typeof apiCloseCashSession>(),
}))

/** Mock del store de auth con tenant fijo. */
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

import {
  listCashRegisters as apiListCashRegisters,
  listCashSessions as apiListCashSessions,
  openCashSession as apiOpenCashSession,
  closeCashSession as apiCloseCashSession,
} from '@/lib/api/generated'

/** Fake CashRegister minimo. */
function makeRegister(uuid: string, code: string): unknown {
  return {
    uuid,
    code,
    name: `Caja ${code}`,
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
  }
}

/** Fake CashSession minima (status open). */
function makeSession(uuid: string, openingAmount = 500): unknown {
  return {
    uuid,
    status: 'open',
    opened_at: '2026-01-01T08:00:00Z',
    opening: {
      amount: openingAmount,
      notes: null,
    },
  }
}

/** Construye respuesta paginada de Laravel. */
function makeListResponse(items: Array<unknown>): unknown {
  return {
    data: items,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: 1,
      last_page: 1,
      per_page: 50,
      to: items.length,
      total: items.length,
    },
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('cashSession store', () => {
  it('inicial: sin sesion activa', () => {
    const store = useCashSessionStore()

    expect(store.currentSession).toBeNull()
    expect(store.registers).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.errorMessage).toBeNull()
    expect(store.hasActiveSession).toBe(false)
  })

  it('loadCurrent: encuentra sesion activa y la guarda', async () => {
    vi.mocked(apiListCashSessions).mockResolvedValue({
      data: makeListResponse([makeSession('s-1', 500)]),
      error: undefined,
    } as unknown)

    const store = useCashSessionStore()
    await store.loadCurrent()

    expect(store.currentSession).not.toBeNull()
    expect(store.hasActiveSession).toBe(true)
    expect(store.currentSession?.uuid).toBe('s-1')
  })

  it('loadCurrent: no hay sesion abierta, currentSession queda null', async () => {
    vi.mocked(apiListCashSessions).mockResolvedValue({
      data: makeListResponse([]),
      error: undefined,
    } as unknown)

    const store = useCashSessionStore()
    await store.loadCurrent()

    expect(store.currentSession).toBeNull()
    expect(store.hasActiveSession).toBe(false)
  })

  it('loadRegisters: poblea registers', async () => {
    vi.mocked(apiListCashRegisters).mockResolvedValue({
      data: makeListResponse([
        makeRegister('r-1', 'CTR-CAJA-01'),
        makeRegister('r-2', 'NRT-CAJA-01'),
      ]),
      error: undefined,
    } as unknown)

    const store = useCashSessionStore()
    await store.loadRegisters()

    expect(store.registers).toHaveLength(2)
    expect(store.registers[0]?.code).toBe('CTR-CAJA-01')
  })

  it('open exitoso: guarda la sesion devuelta', async () => {
    vi.mocked(apiOpenCashSession).mockResolvedValue({
      data: { data: makeSession('s-new', 1000) },
      error: undefined,
    } as unknown)

    const store = useCashSessionStore()
    const ok = await store.open('r-1', 1000, null)

    expect(ok).toBe(true)
    expect(store.currentSession?.uuid).toBe('s-new')
    expect(store.errorMessage).toBeNull()
  })

  it('open con 409 SESSION_ALREADY_OPEN: refresca y mensaje claro', async () => {
    // open() devuelve 409
    vi.mocked(apiOpenCashSession).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'SESSION_ALREADY_OPEN', message: 'La caja ya tiene una sesion abierta.' } },
    } as unknown)

    // loadCurrent() (que open llama internamente para refrescar)
    // encuentra la sesion existente.
    vi.mocked(apiListCashSessions).mockResolvedValue({
      data: makeListResponse([makeSession('s-existente', 200)]),
      error: undefined,
    } as unknown)

    const store = useCashSessionStore()
    const ok = await store.open('r-1', 500, null)

    expect(ok).toBe(true)
    expect(store.errorMessage).toBe('Esta caja ya tiene una sesion abierta.')
    expect(store.currentSession?.uuid).toBe('s-existente')
  })

  it('open con error generico: errorMessage poblado, sesion null', async () => {
    vi.mocked(apiOpenCashSession).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'INTERNAL_ERROR', message: 'Boom' } },
    } as unknown)

    const store = useCashSessionStore()
    const ok = await store.open('r-1', 500, null)

    expect(ok).toBe(false)
    expect(store.errorMessage).toBe('Boom')
    expect(store.currentSession).toBeNull()
  })

  it('clear: resetea todo el state', async () => {
    vi.mocked(apiListCashSessions).mockResolvedValue({
      data: makeListResponse([makeSession('s-1', 500)]),
      error: undefined,
    } as unknown)
    vi.mocked(apiListCashRegisters).mockResolvedValue({
      data: makeListResponse([makeRegister('r-1', 'X')]),
      error: undefined,
    } as unknown)

    const store = useCashSessionStore()
    await store.loadCurrent()
    await store.loadRegisters()
    expect(store.currentSession).not.toBeNull()
    expect(store.registers).toHaveLength(1)

    store.clear()

    expect(store.currentSession).toBeNull()
    expect(store.registers).toEqual([])
    expect(store.errorMessage).toBeNull()
  })

  // ====================================================================
  //  close: cierre de caja
  // ====================================================================
  it('close exitoso: devuelve ok+session y limpia currentSession', async () => {
    vi.mocked(apiListCashSessions).mockResolvedValue({
      data: makeListResponse([makeSession('s-1', 500)]),
      error: undefined,
    } as unknown)
    vi.mocked(apiCloseCashSession).mockResolvedValue({
      data: { data: { ...(makeSession('s-1', 500) as object), status: 'closed' } },
      error: undefined,
    } as unknown)
    const store = useCashSessionStore()
    await store.loadCurrent()
    expect(store.currentSession).not.toBeNull()

    const result = await store.close('s-1', 480, 'Cierre turno')
    expect(result.ok).toBe(true)
    expect(result.session).toMatchObject({ uuid: 's-1', status: 'closed' })
    expect(store.currentSession).toBeNull()
    expect(store.errorMessage).toBeNull()
    expect(apiCloseCashSession).toHaveBeenCalledTimes(1)
  })

  it('close con SESSION_NOT_OPEN: ok=false, sessionLost, refresca y mensaje claro', async () => {
    vi.mocked(apiCloseCashSession).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'SESSION_NOT_OPEN', message: 'La sesion no esta abierta.' } },
    } as unknown)
    // loadCurrent (que close invoca al detectar SESSION_NOT_OPEN) no halla sesion.
    vi.mocked(apiListCashSessions).mockResolvedValue({
      data: makeListResponse([]),
      error: undefined,
    } as unknown)
    const store = useCashSessionStore()

    const result = await store.close('s-1', 500, null)
    expect(result.ok).toBe(false)
    expect(result.sessionLost).toBe(true)
    expect(apiListCashSessions).toHaveBeenCalledTimes(1)
    expect(store.errorMessage).toContain('Recarga el POS')
    expect(store.currentSession).toBeNull()
  })

  it('close con error generico: ok=false, mensaje humanizado del backend', async () => {
    vi.mocked(apiCloseCashSession).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'INTERNAL_ERROR', message: 'Boom al cerrar' } },
    } as unknown)
    const store = useCashSessionStore()

    const result = await store.close('s-1', 500, null)
    expect(result.ok).toBe(false)
    expect(result.sessionLost).toBeUndefined()
    expect(store.errorMessage).toBe('Boom al cerrar')
  })

  it('close limpia un errorMessage previo al iniciar', async () => {
    vi.mocked(apiOpenCashSession).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'INTERNAL_ERROR', message: 'Error viejo' } },
    } as unknown)
    const store = useCashSessionStore()
    await store.open('r-1', 500, null)
    expect(store.errorMessage).toBe('Error viejo')

    vi.mocked(apiCloseCashSession).mockResolvedValue({
      data: { data: { ...(makeSession('s-1', 500) as object), status: 'closed' } },
      error: undefined,
    } as unknown)
    const result = await store.close('s-1', 500, null)
    expect(result.ok).toBe(true)
    expect(store.errorMessage).toBeNull()
  })

})
