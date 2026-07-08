import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useWarehouses } from '@/composables/useWarehouses'
import { listWarehouses as apiListWarehouses } from '@/lib/api/generated'

vi.mock('@/lib/api/generated', () => ({
  listWarehouses: vi.fn(),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

const mockList = vi.mocked(apiListWarehouses)

function wOk(items: unknown[]): unknown {
  return { data: { data: items }, error: undefined }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('useWarehouses', () => {
  it('arranca con items vacios y sin error', () => {
    const w = useWarehouses()
    expect(w.items.value).toEqual([])
    expect(w.errorMessage.value).toBeNull()
    expect(w.loading.value).toBe(false)
  })

  it('init carga los almacenes', async () => {
    mockList.mockResolvedValue(wOk([{ uuid: 'wh-1' }, { uuid: 'wh-2' }]) as never)
    const w = useWarehouses()
    await w.init()
    expect(w.items.value).toHaveLength(2)
    expect(w.loading.value).toBe(false)
  })

  it('init expone errorMessage cuando el SDK devuelve error', async () => {
    mockList.mockResolvedValue({ data: undefined, error: { message: 'boom' } } as never)
    const w = useWarehouses()
    await w.init()
    expect(w.errorMessage.value).not.toBeNull()
    expect(w.items.value).toEqual([])
  })

  it('init captura excepciones inesperadas', async () => {
    mockList.mockRejectedValue(new Error('network'))
    const w = useWarehouses()
    await w.init()
    expect(w.errorMessage.value).toContain('Error inesperado')
  })
})
