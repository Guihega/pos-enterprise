import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { nextTick } from 'vue'
import { useSalesSummary } from '@/composables/useSalesSummary'

vi.mock('@/lib/api/generated', () => ({
  getSalesSummary: vi.fn<typeof apiGetSummary>(),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

import { getSalesSummary as apiGetSummary } from '@/lib/api/generated'

/** Fake summary con los campos requeridos por el tipo SalesSummary. */
function makeSummary(overrides: Record<string, unknown> = {}): unknown {
  return {
    data: {
      date: '2026-06-10',
      branch: null,
      totals: {
        sales_count: 2,
        gross_amount: 348,
        subtotal_amount: 300,
        discount_amount: 0,
        tax_amount: 48,
        average_ticket: 174,
      },
      payments: [{ method: 'cash', count: 2, amount: 348 }],
      top_products: [{ product_uuid: 'p-1', sku: 'SKU-1', name: 'Prod 1', quantity: 2, amount: 348 }],
      ...overrides,
    },
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('useSalesSummary', () => {
  it('init carga el resumen del dia actual', async () => {
    vi.mocked(apiGetSummary).mockResolvedValue({
      data: makeSummary(), error: undefined,
    } as unknown as Awaited<ReturnType<typeof apiGetSummary>>)

    const { init, summary, loading } = useSalesSummary()
    await init()

    expect(summary.value).not.toBeNull()
    expect(summary.value?.totals.sales_count).toBe(2)
    expect(summary.value?.totals.gross_amount).toBe(348)
    expect(loading.value).toBe(false)
    expect(apiGetSummary).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'demo' },
      query: expect.objectContaining({ date: expect.any(String) }),
    })
  })

  it('cambiar la fecha recarga con la nueva fecha en query', async () => {
    vi.mocked(apiGetSummary).mockResolvedValue({
      data: makeSummary(), error: undefined,
    } as unknown as Awaited<ReturnType<typeof apiGetSummary>>)

    const { init, date } = useSalesSummary()
    await init()

    date.value = '2026-01-15'
    await nextTick()

    expect(apiGetSummary).toHaveBeenLastCalledWith({
      headers: { 'X-Tenant': 'demo' },
      query: expect.objectContaining({ date: '2026-01-15' }),
    })
  })

  it('branchUuid agrega branch_uuid a la query', async () => {
    vi.mocked(apiGetSummary).mockResolvedValue({
      data: makeSummary(), error: undefined,
    } as unknown as Awaited<ReturnType<typeof apiGetSummary>>)

    const { init, branchUuid } = useSalesSummary()
    await init()

    branchUuid.value = 'branch-123'
    await nextTick()

    expect(apiGetSummary).toHaveBeenLastCalledWith({
      headers: { 'X-Tenant': 'demo' },
      query: expect.objectContaining({ branch_uuid: 'branch-123' }),
    })
  })

  it('error poblea errorMessage y deja summary en null', async () => {
    vi.mocked(apiGetSummary).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'SERVER_ERROR', message: 'Backend caido' } },
    } as unknown as Awaited<ReturnType<typeof apiGetSummary>>)

    const { init, summary, errorMessage, loading } = useSalesSummary()
    await init()

    expect(summary.value).toBeNull()
    expect(errorMessage.value).toBe('Backend caido')
    expect(loading.value).toBe(false)
  })
})
