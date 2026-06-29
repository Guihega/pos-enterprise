import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useInventoryStore } from '@/stores/inventory'
import type { AdjustStockInput } from '@/lib/api/generated'

vi.mock('@/lib/api/generated', () => ({
  adjustStock: vi.fn<typeof apiAdjustStock>(),
}))

import { adjustStock as apiAdjustStock } from '@/lib/api/generated'

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

/** Movimiento minimo (shape anidado) devuelto por adjust. */
function movementResource(uuid: string, delta: number, after: number): unknown {
  return {
    uuid,
    type: 'adjustment',
    movement_at: '2026-01-01T00:00:00Z',
    product: { uuid: 'p-1', sku: 'SKU-1', name: 'Cafe' },
    warehouse: { uuid: 'w-1', code: 'CTR', name: 'Centro' },
    quantity: { delta, after },
    cost: { unit: 10, total: 10 * delta, average_after: 10 },
    transfer_id: null,
    reason: 'conteo fisico',
    reference: null,
    user: { uuid: 'u-1', name: 'Admin' },
    created_at: '2026-01-01T00:00:00Z',
  }
}

function okResource(uuid: string, delta: number, after: number): unknown {
  return { data: { data: movementResource(uuid, delta, after) }, error: undefined }
}

function apiError(code: string, message: string): unknown {
  return { data: undefined, error: { error: { code, message } } }
}

function input(delta = 5): AdjustStockInput {
  return { product_uuid: 'p-1', warehouse_uuid: 'w-1', delta, reason: 'conteo fisico' }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('inventory store', () => {
  it('inicial: adjusting=false', () => {
    const store = useInventoryStore()
    expect(store.adjusting).toBe(false)
  })

  it('adjust exito: ok=true, devuelve movement y arma headers+body', async () => {
    vi.mocked(apiAdjustStock).mockResolvedValue(okResource('m-1', 5, 105) as never)
    const store = useInventoryStore()

    const result = await store.adjust(input(5))

    expect(result.ok).toBe(true)
    expect(result.movement).toMatchObject({ uuid: 'm-1', type: 'adjustment' })
    expect(apiAdjustStock).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'demo' },
      body: { product_uuid: 'p-1', warehouse_uuid: 'w-1', delta: 5, reason: 'conteo fisico' },
    })
    expect(store.adjusting).toBe(false)
  })

  it('adjust 409 INSUFFICIENT_STOCK: ok=false, mensaje del backend', async () => {
    vi.mocked(apiAdjustStock).mockResolvedValue(
      apiError('INSUFFICIENT_STOCK', 'El ajuste dejaria el stock en negativo.') as never,
    )
    const store = useInventoryStore()

    const result = await store.adjust(input(-999))

    expect(result.ok).toBe(false)
    expect(result.errorMessage).toBe('El ajuste dejaria el stock en negativo.')
    expect(store.adjusting).toBe(false)
  })

  it('adjust 422 validacion: ok=false, primer mensaje de errors', async () => {
    vi.mocked(apiAdjustStock).mockResolvedValue({
      data: undefined,
      error: {
        message: 'The given data was invalid.',
        errors: { reason: ['El motivo es obligatorio.'] },
      },
    } as never)
    const store = useInventoryStore()

    const result = await store.adjust(input(5))

    expect(result.ok).toBe(false)
    expect(result.errorMessage).toBe('El motivo es obligatorio.')
  })

  it('adjust error generico: mensaje fallback', async () => {
    vi.mocked(apiAdjustStock).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'WHATEVER' } },
    } as never)
    const store = useInventoryStore()

    const result = await store.adjust(input(5))

    expect(result.ok).toBe(false)
    expect(result.errorMessage).toBe('No se pudo aplicar el ajuste.')
  })

  it('adjusting vuelve a false tras error inesperado (finally)', async () => {
    vi.mocked(apiAdjustStock).mockRejectedValue(new Error('boom'))
    const store = useInventoryStore()

    const result = await store.adjust(input(5))

    expect(result.ok).toBe(false)
    expect(store.adjusting).toBe(false)
  })
})
