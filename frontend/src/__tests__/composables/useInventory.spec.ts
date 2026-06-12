import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

import { useInventory } from '@/composables/useInventory'

vi.mock('@/lib/api/generated', () => ({
  listInventoryStocks: vi.fn<typeof apiListStocks>(),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

import { listInventoryStocks as apiListStocks } from '@/lib/api/generated'

/** Fake stock minimo con los campos requeridos por el tipo Stock. */
function makeStock(productUuid: string, onHand = 10): unknown {
  return {
    product: { uuid: productUuid, sku: `SKU-${productUuid}`, name: `Prod ${productUuid}` },
    warehouse: { uuid: 'w-1', code: 'CTR', name: 'Centro' },
    quantity: { on_hand: onHand, reserved: 0, available: onHand },
    thresholds: { min: null, max: null, is_low: false, is_overstock: false },
    average_cost: 10,
    last_movement_at: null,
  }
}

function makeResponse(items: Array<unknown>, page: number, lastPage: number, total: number): unknown {
  return {
    data: items,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: page, from: 1, last_page: lastPage, per_page: 50, to: items.length, total },
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

describe('useInventory', () => {
  it('init() carga la primera pagina', async () => {
    vi.mocked(apiListStocks).mockResolvedValue({
      data: makeResponse([makeStock('p-1'), makeStock('p-2')], 1, 1, 2),
      error: undefined,
    } as unknown)

    const { init, items, loading, total, hasMore } = useInventory()

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

  it('cambiar almacen reinicia y llama con warehouse en query', async () => {
    vi.mocked(apiListStocks).mockResolvedValue({
      data: makeResponse([makeStock('p-1')], 1, 1, 1),
      error: undefined,
    } as unknown)

    const { init, warehouseUuid, items } = useInventory()
    await init()
    await nextTick()

    vi.mocked(apiListStocks).mockClear()

    warehouseUuid.value = 'w-99'
    await nextTick()
    await vi.advanceTimersByTimeAsync(10)
    await nextTick()

    expect(apiListStocks).toHaveBeenCalledTimes(1)
    expect(apiListStocks).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'demo' },
      query: expect.objectContaining({ warehouse: 'w-99' }),
    })
    expect(items.value).toHaveLength(1)
  })

  it('lowStockOnly true agrega low_stock a la query', async () => {
    vi.mocked(apiListStocks).mockResolvedValue({
      data: makeResponse([], 1, 1, 0),
      error: undefined,
    } as unknown)

    const { init, lowStockOnly } = useInventory()
    await init()
    await nextTick()

    vi.mocked(apiListStocks).mockClear()

    lowStockOnly.value = true
    await nextTick()
    await vi.advanceTimersByTimeAsync(10)
    await nextTick()

    expect(apiListStocks).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'demo' },
      query: expect.objectContaining({ low_stock: true }),
    })
  })

  it('loadMore agrega resultados y actualiza paginacion', async () => {
    vi.mocked(apiListStocks).mockResolvedValueOnce({
      data: makeResponse([makeStock('p-1'), makeStock('p-2')], 1, 2, 4),
      error: undefined,
    } as unknown)

    const { init, items, hasMore, loadMore } = useInventory()
    await init()
    await nextTick()
    expect(items.value).toHaveLength(2)
    expect(hasMore.value).toBe(true)

    vi.mocked(apiListStocks).mockResolvedValueOnce({
      data: makeResponse([makeStock('p-3'), makeStock('p-4')], 2, 2, 4),
      error: undefined,
    } as unknown)

    await loadMore()

    expect(items.value).toHaveLength(4)
    expect(hasMore.value).toBe(false)
  })

  it('error: poblea errorMessage y deja items vacios', async () => {
    vi.mocked(apiListStocks).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'SERVER_ERROR', message: 'Backend caido' } },
    } as unknown)

    const { init, items, loading, errorMessage } = useInventory()
    await init()
    await nextTick()

    expect(loading.value).toBe(false)
    expect(items.value).toHaveLength(0)
    expect(errorMessage.value).toBe('Backend caido')
  })
})
