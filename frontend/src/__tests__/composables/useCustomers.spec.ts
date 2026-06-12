import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

import { useCustomers } from '@/composables/useCustomers'

/**
 * Mock del SDK generado por Hey API. Solo nos importa listCustomers en
 * este composable.
 */
vi.mock('@/lib/api/generated', () => ({
  listCustomers: vi.fn<typeof apiListCustomers>(),
}))

/**
 * Mock del store de auth para que useCustomers vea un tenant valido.
 */
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    tenant: 'acme',
  }),
}))

import { listCustomers as apiListCustomers } from '@/lib/api/generated'

/** Fake cliente minimo con los campos requeridos por el tipo Customer (anidado). */
function makeCustomer(uuid: string, name: string, available = 0): unknown {
  return {
    uuid,
    code: null,
    type: 'individual',
    name,
    legal_name: null,
    tax: { tax_id: null, data: null },
    contact: { email: null, phone: null, mobile: null },
    address: { line: null, city: null, state: null, postal_code: null, country_code: null },
    credit: { limit: 0, balance: 0, available },
    flags: { is_active: true, is_blocked: false, blocked_reason: null },
    notes: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  }
}

/** Construye un payload de respuesta paginada. */
function makeResponse(items: Array<unknown>, page: number, lastPage: number, total: number): unknown {
  return {
    data: items,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: page,
      from: 1,
      last_page: lastPage,
      per_page: 20,
      to: items.length,
      total,
    },
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  vi.useFakeTimers()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('useCustomers', () => {
  it('init() carga la primera pagina', async () => {
    vi.mocked(apiListCustomers).mockResolvedValue({
      data: makeResponse(
        [makeCustomer('c-1', 'Ana'), makeCustomer('c-2', 'Luis')],
        1,
        1,
        2,
      ),
      error: undefined,
    } as unknown)

    const { init, items, loading, total, hasMore } = useCustomers()

    expect(loading.value).toBe(false)
    expect(items.value).toHaveLength(0)

    const initPromise = init()
    expect(loading.value).toBe(true)
    await initPromise
    await nextTick()

    expect(loading.value).toBe(false)
    expect(items.value).toHaveLength(2)
    expect(total.value).toBe(2)
    expect(hasMore.value).toBe(false)
  })

  it('busqueda con debounce: cambia el termino, espera 300ms, llama con q', async () => {
    vi.mocked(apiListCustomers).mockResolvedValue({
      data: makeResponse([makeCustomer('c-1', 'Ana')], 1, 1, 1),
      error: undefined,
    } as unknown)

    const { init, searchTerm, items } = useCustomers()

    await init()
    await nextTick()

    vi.mocked(apiListCustomers).mockClear()

    searchTerm.value = 'ana'
    await nextTick()
    expect(apiListCustomers).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(350)
    await nextTick()

    expect(apiListCustomers).toHaveBeenCalledTimes(1)
    expect(apiListCustomers).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'acme' },
      query: expect.objectContaining({ q: 'ana' }),
    })
    expect(items.value).toHaveLength(1)
  })

  it('loadMore: agrega resultados a la lista existente y actualiza paginacion', async () => {
    vi.mocked(apiListCustomers).mockResolvedValueOnce({
      data: makeResponse(
        [makeCustomer('c-1', 'A'), makeCustomer('c-2', 'B')],
        1,
        2,
        4,
      ),
      error: undefined,
    } as unknown)

    const { init, items, hasMore, loadMore } = useCustomers()
    await init()
    await nextTick()
    expect(items.value).toHaveLength(2)
    expect(hasMore.value).toBe(true)

    vi.mocked(apiListCustomers).mockResolvedValueOnce({
      data: makeResponse(
        [makeCustomer('c-3', 'C'), makeCustomer('c-4', 'D')],
        2,
        2,
        4,
      ),
      error: undefined,
    } as unknown)

    await loadMore()

    expect(items.value).toHaveLength(4)
    expect(items.value[0]?.uuid).toBe('c-1')
    expect(items.value[3]?.uuid).toBe('c-4')
    expect(hasMore.value).toBe(false)
  })

  it('error: poblea errorMessage y deja items vacios', async () => {
    vi.mocked(apiListCustomers).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'SERVER_ERROR', message: 'Backend caido' } },
    } as unknown)

    const { init, items, loading, errorMessage } = useCustomers()
    await init()
    await nextTick()

    expect(loading.value).toBe(false)
    expect(items.value).toHaveLength(0)
    expect(errorMessage.value).toBe('Backend caido')
  })

  it('retry: tras error, intenta de nuevo y limpia el mensaje si tiene exito', async () => {
    vi.mocked(apiListCustomers).mockResolvedValueOnce({
      data: undefined,
      error: { error: { code: 'NET', message: 'red caida' } },
    } as unknown)

    const { init, items, errorMessage, retry } = useCustomers()
    await init()
    await nextTick()
    expect(errorMessage.value).toBe('red caida')

    vi.mocked(apiListCustomers).mockResolvedValueOnce({
      data: makeResponse([makeCustomer('c-1', 'X')], 1, 1, 1),
      error: undefined,
    } as unknown)

    await retry()

    expect(errorMessage.value).toBeNull()
    expect(items.value).toHaveLength(1)
  })
})
