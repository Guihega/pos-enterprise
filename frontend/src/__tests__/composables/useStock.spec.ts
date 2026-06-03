import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useStock } from '@/composables/useStock'
import * as sdk from '@/lib/api/generated/sdk.gen'

vi.mock('@/lib/api/generated/sdk.gen', () => ({
  listInventoryStocks: vi.fn(),
}))

vi.mock('@/lib/api/errors', () => ({
  getTenantOrThrow: vi.fn(),
}))

const mockListInventoryStocks = vi.fn<typeof sdk.listInventoryStocks>()

function makeStockResponse(items: { uuid: string; available: number }[]) {
  return {
    data: {
      data: items.map((i) => ({
        product: { uuid: i.uuid, sku: 'SKU', name: 'Producto' },
        warehouse: { uuid: 'wh-1', code: 'WH', name: 'Almacen' },
        quantity: { on_hand: i.available, reserved: 0, available: i.available },
      })),
      links: { first: '', last: '', prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 100, total: items.length, from: 1, to: items.length, path: '' },
    },
    error: undefined,
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(sdk.listInventoryStocks).mockImplementation(mockListInventoryStocks)
  // Resetear estado module-level reiniciando el composable a mapa vacio
  mockListInventoryStocks.mockResolvedValue(makeStockResponse([]))
})

describe('useStock', () => {
  it('availableFor devuelve Infinity si el producto no esta en el mapa', () => {
    const { availableFor } = useStock()
    expect(availableFor('uuid-desconocido')).toBe(Infinity)
  })

  it('isOutOfStock devuelve false si track_inventory es false sin importar el mapa', () => {
    const { isOutOfStock } = useStock()
    expect(isOutOfStock('uuid-x', false)).toBe(false)
  })

  it('isOutOfStock devuelve false si el producto no esta en el mapa (Infinity > 0)', () => {
    const { isOutOfStock } = useStock()
    expect(isOutOfStock('uuid-desconocido', true)).toBe(false)
  })

  it('init carga el mapa y availableFor refleja el stock real', async () => {
    mockListInventoryStocks.mockResolvedValue(
      makeStockResponse([
        { uuid: 'prod-1', available: 10 },
        { uuid: 'prod-2', available: 0 },
      ]),
    )
    const { init, availableFor, isOutOfStock } = useStock()
    await init('demo', 'wh-uuid')

    expect(availableFor('prod-1')).toBe(10)
    expect(availableFor('prod-2')).toBe(0)
    expect(isOutOfStock('prod-1', true)).toBe(false)
    expect(isOutOfStock('prod-2', true)).toBe(true)
  })

  it('isOutOfStock devuelve false para prod-2 si track_inventory es false aunque available sea 0', async () => {
    mockListInventoryStocks.mockResolvedValue(
      makeStockResponse([{ uuid: 'prod-2', available: 0 }]),
    )
    const { init, isOutOfStock } = useStock()
    await init('demo', 'wh-uuid')
    expect(isOutOfStock('prod-2', false)).toBe(false)
  })

  it('init llama a listInventoryStocks con el tenant y warehouse correctos', async () => {
    mockListInventoryStocks.mockResolvedValue(makeStockResponse([]))
    const { init } = useStock()
    await init('mi-tenant', 'mi-warehouse')

    expect(mockListInventoryStocks).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'mi-tenant' },
      query: { warehouse: 'mi-warehouse', per_page: 100 },
    })
  })

  it('init pone error si la API devuelve error', async () => {
    mockListInventoryStocks.mockResolvedValue({
      data: undefined,
      error: { error: { code: 'TENANT_NOT_RESOLVED', message: 'fail' } },
    } as never)
    const { init, error } = useStock()
    await init('demo', 'wh-uuid')
    expect(error.value).toBe('Error al cargar existencias')
  })

  it('loading es false antes y despues de init', async () => {
    mockListInventoryStocks.mockResolvedValue(makeStockResponse([]))
    const { init, loading } = useStock()
    expect(loading.value).toBe(false)
    const p = init('demo', 'wh-uuid')
    await p
    expect(loading.value).toBe(false)
  })
})
