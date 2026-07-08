import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useCatalogOptions } from '@/composables/useCatalogOptions'
import {
  listCategories as apiCat,
  listBrands as apiBrand,
  listUnits as apiUnit,
  listTaxes as apiTax,
} from '@/lib/api/generated'

vi.mock('@/lib/api/generated', () => ({
  listCategories: vi.fn(),
  listBrands: vi.fn(),
  listUnits: vi.fn(),
  listTaxes: vi.fn(),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

const mockCat = vi.mocked(apiCat)
const mockBrand = vi.mocked(apiBrand)
const mockUnit = vi.mocked(apiUnit)
const mockTax = vi.mocked(apiTax)

function ok(items: unknown[]): unknown {
  return { data: { data: items }, error: undefined }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  mockCat.mockResolvedValue(ok([{ uuid: 'c1' }]) as never)
  mockBrand.mockResolvedValue(ok([{ uuid: 'b1' }]) as never)
  mockUnit.mockResolvedValue(ok([{ uuid: 'u1' }]) as never)
  mockTax.mockResolvedValue(ok([{ uuid: 't1' }]) as never)
})

describe('useCatalogOptions', () => {
  it('arranca con todas las listas vacias', () => {
    const o = useCatalogOptions()
    expect(o.categories.value).toEqual([])
    expect(o.brands.value).toEqual([])
    expect(o.units.value).toEqual([])
    expect(o.taxes.value).toEqual([])
  })

  it('init carga las 4 listas en paralelo', async () => {
    const o = useCatalogOptions()
    await o.init()
    expect(o.categories.value).toHaveLength(1)
    expect(o.brands.value).toHaveLength(1)
    expect(o.units.value).toHaveLength(1)
    expect(o.taxes.value).toHaveLength(1)
    expect(mockCat).toHaveBeenCalledTimes(1)
    expect(mockBrand).toHaveBeenCalledTimes(1)
    expect(mockUnit).toHaveBeenCalledTimes(1)
    expect(mockTax).toHaveBeenCalledTimes(1)
  })

  it('si una de las listas falla, expone error y no puebla', async () => {
    mockBrand.mockResolvedValue({ data: undefined, error: { message: 'fail' } } as never)
    const o = useCatalogOptions()
    await o.init()
    expect(o.errorMessage.value).not.toBeNull()
    expect(o.categories.value).toEqual([])
  })

  it('init captura excepciones inesperadas', async () => {
    mockCat.mockRejectedValue(new Error('network'))
    const o = useCatalogOptions()
    await o.init()
    expect(o.errorMessage.value).toContain('Error inesperado')
  })
})
