import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

import { useProducts } from '@/composables/useProducts'
import type { listProducts as ListProductsFn } from '@/lib/api/generated'

/**
 * Mock del SDK generado por Hey API. Solo nos importa listProducts en
 * este composable.
 */
vi.mock('@/lib/api/generated', () => ({
  listProducts: vi.fn<typeof ListProductsFn>(),
}))

/**
 * Mock del store de auth para que useProducts vea un tenant valido.
 * Definimos un store fake con la forma minima que el composable consume.
 */
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    tenant: 'acme',
  }),
}))

import { listProducts as apiListProducts } from '@/lib/api/generated'

/** Fake producto minimo con los campos requeridos por el tipo Product. */
function makeProduct(uuid: string, name: string, price = 100): unknown {
  return {
    uuid,
    sku: `SKU-${uuid}`,
    name,
    pricing: {
      cost: price * 0.6,
      price,
      has_discount: false,
    },
    flags: {
      track_inventory: true,
      is_sellable: true,
      is_purchasable: true,
      allow_decimals: false,
    },
    status: 'active',
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

describe('useProducts', () => {
  it('init() carga la primera pagina', async () => {
    vi.mocked(apiListProducts).mockResolvedValue({
      data: makeResponse(
        [makeProduct('p-1', 'Cafe'), makeProduct('p-2', 'Te')],
        1,
        1,
        2,
      ),
      error: undefined,
    } as unknown)

    const { init, items, loading, total, hasMore } = useProducts()

    // Antes de init: loading false (todavia no hicimos nada).
    expect(loading.value).toBe(false)
    expect(items.value).toHaveLength(0)

    const initPromise = init()
    // Mientras la peticion esta in-flight, loading debe ser true.
    expect(loading.value).toBe(true)
    await initPromise
    await nextTick()

    expect(loading.value).toBe(false)
    expect(items.value).toHaveLength(2)
    expect(total.value).toBe(2)
    expect(hasMore.value).toBe(false)
  })

  it('busqueda con debounce: cambia el termino, espera 300ms, llama con q', async () => {
    vi.mocked(apiListProducts).mockResolvedValue({
      data: makeResponse([makeProduct('p-1', 'Cafe')], 1, 1, 1),
      error: undefined,
    } as unknown)

    const { init, searchTerm, items } = useProducts()

    // Drenar la carga inicial.
    await init()
    await nextTick()

    vi.mocked(apiListProducts).mockClear()

    // Cambiar termino: no dispara inmediatamente.
    searchTerm.value = 'cafe'
    await nextTick()
    expect(apiListProducts).not.toHaveBeenCalled()

    // Avanzar 300ms para disparar el debounce.
    await vi.advanceTimersByTimeAsync(350)
    await nextTick()

    expect(apiListProducts).toHaveBeenCalledTimes(1)
    expect(apiListProducts).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'acme' },
      query: expect.objectContaining({ q: 'cafe' }),
    })
    expect(items.value).toHaveLength(1)
  })

  it('loadMore: agrega resultados a la lista existente y actualiza paginacion', async () => {
    // Primera pagina: 2 items, hay segunda pagina.
    vi.mocked(apiListProducts).mockResolvedValueOnce({
      data: makeResponse(
        [makeProduct('p-1', 'A'), makeProduct('p-2', 'B')],
        1,
        2,
        4,
      ),
      error: undefined,
    } as unknown)

    const { init, items, hasMore, loadMore } = useProducts()
    await init()
    await nextTick()
    expect(items.value).toHaveLength(2)
    expect(hasMore.value).toBe(true)

    // Segunda pagina: 2 items mas, no mas paginas.
    vi.mocked(apiListProducts).mockResolvedValueOnce({
      data: makeResponse(
        [makeProduct('p-3', 'C'), makeProduct('p-4', 'D')],
        2,
        2,
        4,
      ),
      error: undefined,
    } as unknown)

    await loadMore()

    // Verifica que la segunda llamada envio page: 2 (regresion del bug
    // donde fetchPage no pasaba el numero de pagina a listProducts y
    // loadMore siempre repetia la pagina 1 del backend).
    expect(apiListProducts).toHaveBeenNthCalledWith(2, {
      headers: { 'X-Tenant': 'acme' },
      query: expect.objectContaining({ page: 2 }),
    })

    expect(items.value).toHaveLength(4)
    expect(items.value[0]?.uuid).toBe('p-1')
    expect(items.value[3]?.uuid).toBe('p-4')
    expect(hasMore.value).toBe(false)
  })

  it('error: poblea errorMessage y deja items vacios', async () => {
    vi.mocked(apiListProducts).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'SERVER_ERROR', message: 'Backend caido' } },
    } as unknown)

    const { init, items, loading, errorMessage } = useProducts()
    await init()
    await nextTick()

    expect(loading.value).toBe(false)
    expect(items.value).toHaveLength(0)
    expect(errorMessage.value).toBe('Backend caido')
  })

  it('retry: tras error, intenta de nuevo y limpia el mensaje si tiene exito', async () => {
    // Primera carga falla.
    vi.mocked(apiListProducts).mockResolvedValueOnce({
      data: undefined,
      error: { error: { code: 'NET', message: 'red caida' } },
    } as unknown)

    const { init, items, errorMessage, retry } = useProducts()
    await init()
    await nextTick()
    expect(errorMessage.value).toBe('red caida')

    // Reintento: succeede.
    vi.mocked(apiListProducts).mockResolvedValueOnce({
      data: makeResponse([makeProduct('p-1', 'X')], 1, 1, 1),
      error: undefined,
    } as unknown)

    await retry()

    expect(errorMessage.value).toBeNull()
    expect(items.value).toHaveLength(1)
  })
})
