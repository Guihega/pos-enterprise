import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useCashSessionReport } from '@/composables/useCashSessionReport'
import { getCashSessionReport as apiGet } from '@/lib/api/generated'

vi.mock('@/lib/api/generated', () => ({
  getCashSessionReport: vi.fn(),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

const mockGet = vi.mocked(apiGet)

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('useCashSessionReport', () => {
  it('arranca sin reporte', () => {
    const r = useCashSessionReport()
    expect(r.report.value).toBeNull()
    expect(r.errorMessage.value).toBeNull()
  })

  it('load llena el reporte en exito', async () => {
    mockGet.mockResolvedValue({ data: { data: { session: { uuid: 's1' } } }, error: undefined } as never)
    const r = useCashSessionReport()
    await r.load('s1')
    expect(r.report.value).not.toBeNull()
    expect(r.loading.value).toBe(false)
  })

  it('load deja report en null y expone error en fallo', async () => {
    mockGet.mockResolvedValue({ data: undefined, error: { message: 'boom' } } as never)
    const r = useCashSessionReport()
    await r.load('s1')
    expect(r.report.value).toBeNull()
    expect(r.errorMessage.value).not.toBeNull()
  })

  it('clear resetea reporte y error', async () => {
    mockGet.mockResolvedValue({ data: { data: { session: { uuid: 's1' } } }, error: undefined } as never)
    const r = useCashSessionReport()
    await r.load('s1')
    r.clear()
    expect(r.report.value).toBeNull()
    expect(r.errorMessage.value).toBeNull()
  })
})
