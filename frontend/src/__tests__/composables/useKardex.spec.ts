import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useKardex } from '@/composables/useKardex'
import { listInventoryMovements as apiList } from '@/lib/api/generated'

vi.mock('@/lib/api/generated', () => ({
  listInventoryMovements: vi.fn(),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

const mockList = vi.mocked(apiList)

function page(items: unknown[], current: number, last: number, total: number): unknown {
  return {
    data: { data: items, meta: { current_page: current, last_page: last, total } },
    error: undefined,
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('useKardex', () => {
  it('init carga la primera pagina del producto', async () => {
    mockList.mockResolvedValue(page([{ uuid: 'm1' }], 1, 1, 1) as never)
    const k = useKardex()
    await k.init('prod-1')
    expect(k.items.value).toHaveLength(1)
    expect(k.total.value).toBe(1)
    expect(k.hasMore.value).toBe(false)
  })

  it('hasMore es true cuando hay mas paginas', async () => {
    mockList.mockResolvedValue(page([{ uuid: 'm1' }], 1, 3, 150) as never)
    const k = useKardex()
    await k.init('prod-1')
    expect(k.hasMore.value).toBe(true)
  })

  it('loadMore agrega la siguiente pagina a los items existentes', async () => {
    mockList.mockResolvedValueOnce(page([{ uuid: 'm1' }], 1, 2, 2) as never)
    const k = useKardex()
    await k.init('prod-1')
    mockList.mockResolvedValueOnce(page([{ uuid: 'm2' }], 2, 2, 2) as never)
    await k.loadMore()
    expect(k.items.value).toHaveLength(2)
    expect(k.hasMore.value).toBe(false)
  })

  it('loadMore no hace nada si no hay mas paginas', async () => {
    mockList.mockResolvedValue(page([{ uuid: 'm1' }], 1, 1, 1) as never)
    const k = useKardex()
    await k.init('prod-1')
    mockList.mockClear()
    await k.loadMore()
    expect(mockList).not.toHaveBeenCalled()
  })

  it('expone error cuando el SDK falla', async () => {
    mockList.mockResolvedValue({ data: undefined, error: { message: 'boom' } } as never)
    const k = useKardex()
    await k.init('prod-1')
    expect(k.errorMessage.value).not.toBeNull()
  })

  it('retry recarga la primera pagina', async () => {
    mockList.mockResolvedValue(page([{ uuid: 'm1' }], 1, 1, 1) as never)
    const k = useKardex()
    await k.init('prod-1')
    mockList.mockClear()
    mockList.mockResolvedValue(page([{ uuid: 'm9' }], 1, 1, 1) as never)
    await k.retry()
    expect(mockList).toHaveBeenCalledTimes(1)
    expect(k.items.value[0]).toEqual({ uuid: 'm9' })
  })
})
