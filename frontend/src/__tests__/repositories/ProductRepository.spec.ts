/**
 * Tests del ProductRepository (Fase 2, Iteracion 1).
 *
 * Usan fake-indexeddb (registrado en setup.ts) para que Dexie opere en
 * memoria sin necesitar un navegador real. Cada test trabaja con una
 * instancia fresca de la DB para evitar contaminacion entre tests.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { db } from '@/db/schema'
import {
  hasData,
  getLastFullSync,
  fullSync,
  getPage,
} from '@/repositories/ProductRepository'

// ---------------------------------------------------------------------------
// Mock del SDK: listProducts no debe tocar red en tests unitarios.
// ---------------------------------------------------------------------------
vi.mock('@/lib/api/generated', () => ({
  listProducts: vi.fn<typeof import('@/lib/api/generated').listProducts>(),
}))

import { listProducts as apiListProducts } from '@/lib/api/generated'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeApiProduct(uuid: string, name: string, sku = `SKU-${uuid}`) {
  return {
    uuid,
    sku,
    name,
    pricing: { price: 100, cost: 60, has_discount: false },
    flags: {
      track_inventory: true,
      is_sellable: true,
      is_purchasable: true,
      allow_decimals: false,
    },
    status: 'active' as const,
    category: { uuid: 'cat-1', name: 'Bebidas', slug: 'bebidas' },
    unit: { uuid: 'unit-1', code: 'PZA', name: 'Pieza', symbol: 'pza' },
    tax: { uuid: 'tax-1', code: 'IVA16', name: 'IVA 16%', rate: 0.16, is_inclusive: true },
    updated_at: '2026-06-15T00:00:00Z',
    created_at: '2026-06-15T00:00:00Z',
  }
}

function makePageResponse(items: unknown[], page: number, lastPage: number) {
  return {
    data: {
      data: items,
      meta: {
        current_page: page,
        last_page: lastPage,
        per_page: 100,
        total: items.length,
        from: 1,
        to: items.length,
      },
      links: { first: null, last: null, prev: null, next: null },
    },
    error: undefined,
  }
}

// ---------------------------------------------------------------------------
// Limpieza de DB entre tests para evitar contaminacion.
// ---------------------------------------------------------------------------
beforeEach(async () => {
  await db.products.clear()
  await db.settings.clear()
})

afterEach(() => {
  vi.clearAllMocks()
})

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('hasData', () => {
  it('devuelve false cuando la tabla products esta vacia', async () => {
    expect(await hasData()).toBe(false)
  })

  it('devuelve true cuando hay al menos un producto', async () => {
    await db.products.put({
      uuid: 'p-1', sku: 'S1', name: 'Producto', price: 10, cost: 5,
      hasDiscount: false, trackInventory: true, isSellable: true,
      isPurchasable: true, allowDecimals: false, status: 'active',
      categoryUuid: null, categoryName: null, categorySlug: null,
      unitUuid: null, unitCode: null, unitName: null, unitSymbol: null,
      taxUuid: null, taxCode: null, taxName: null, taxRate: null, taxIsInclusive: null,
      searchBlob: ['producto', 's1'], updatedAt: '2026-01-01T00:00:00Z',
    })
    expect(await hasData()).toBe(true)
  })
})

describe('getLastFullSync', () => {
  it('devuelve null cuando nunca se ha sincronizado', async () => {
    expect(await getLastFullSync()).toBeNull()
  })

  it('devuelve el ISO string de la ultima sincronizacion', async () => {
    const iso = '2026-06-15T10:00:00.000Z'
    await db.settings.put({ key: 'catalog:last_full_sync', value: iso, updatedAt: iso })
    expect(await getLastFullSync()).toBe(iso)
  })
})

describe('fullSync', () => {
  it('descarga una sola pagina y persiste los productos en IndexedDB', async () => {
    vi.mocked(apiListProducts).mockResolvedValueOnce(
      makePageResponse([makeApiProduct('p-1', 'Agua'), makeApiProduct('p-2', 'Cafe')], 1, 1) as never,
    )

    await fullSync('demo')

    expect(await db.products.count()).toBe(2)
    const agua = await db.products.get('p-1')
    expect(agua?.name).toBe('Agua')
    expect(agua?.price).toBe(100)
    expect(agua?.categoryUuid).toBe('cat-1')
    expect(agua?.searchBlob).toContain('agua')
  })

  it('pagina correctamente cuando hay multiples paginas', async () => {
    vi.mocked(apiListProducts)
      .mockResolvedValueOnce(makePageResponse([makeApiProduct('p-1', 'A')], 1, 2) as never)
      .mockResolvedValueOnce(makePageResponse([makeApiProduct('p-2', 'B')], 2, 2) as never)

    await fullSync('demo')

    expect(apiListProducts).toHaveBeenCalledTimes(2)
    expect(apiListProducts).toHaveBeenNthCalledWith(1,
      expect.objectContaining({ query: expect.objectContaining({ page: 1 }) }))
    expect(apiListProducts).toHaveBeenNthCalledWith(2,
      expect.objectContaining({ query: expect.objectContaining({ page: 2 }) }))
    expect(await db.products.count()).toBe(2)
  })

  it('actualiza last_full_sync en settings tras sincronizar', async () => {
    vi.mocked(apiListProducts).mockResolvedValueOnce(
      makePageResponse([], 1, 1) as never,
    )

    const before = new Date().toISOString()
    await fullSync('demo')
    const after = new Date().toISOString()

    const sync = await getLastFullSync()
    expect(sync).not.toBeNull()
    expect(sync! >= before).toBe(true)
    expect(sync! <= after).toBe(true)
  })

  it('reemplaza productos existentes (clear + bulkPut)', async () => {
    // Producto viejo en cache.
    await db.products.put({
      uuid: 'old', sku: 'OLD', name: 'Viejo', price: 1, cost: 0,
      hasDiscount: false, trackInventory: false, isSellable: true,
      isPurchasable: true, allowDecimals: false, status: 'active',
      categoryUuid: null, categoryName: null, categorySlug: null,
      unitUuid: null, unitCode: null, unitName: null, unitSymbol: null,
      taxUuid: null, taxCode: null, taxName: null, taxRate: null, taxIsInclusive: null,
      searchBlob: ['viejo'], updatedAt: '2026-01-01T00:00:00Z',
    })

    vi.mocked(apiListProducts).mockResolvedValueOnce(
      makePageResponse([makeApiProduct('new-1', 'Nuevo')], 1, 1) as never,
    )

    await fullSync('demo')

    expect(await db.products.count()).toBe(1)
    expect(await db.products.get('old')).toBeUndefined()
    expect(await db.products.get('new-1')).toBeDefined()
  })

  it('lanza si la API devuelve error', async () => {
    vi.mocked(apiListProducts).mockResolvedValueOnce({
      data: undefined,
      error: { code: 'SERVER_ERROR', message: 'Backend caido' },
    } as never)

    await expect(fullSync('demo')).rejects.toThrow('fullSync')
  })
})

describe('getPage', () => {
  beforeEach(async () => {
    // Poblar con 5 productos para probar paginacion y busqueda.
    vi.mocked(apiListProducts).mockResolvedValueOnce(
      makePageResponse([
        makeApiProduct('p-1', 'Agua Bonafont', 'AG-001'),
        makeApiProduct('p-2', 'Cafe Nescafe', 'CF-001'),
        makeApiProduct('p-3', 'Arroz Verde', 'AR-001'),
        makeApiProduct('p-4', 'Azucar Estandar', 'AZ-001'),
        makeApiProduct('p-5', 'Atun Dolores', 'AT-001'),
      ], 1, 1) as never,
    )
    await fullSync('demo')
  })

  it('devuelve todos los productos paginados (pagina 1)', async () => {
    const result = await getPage({ page: 1, perPage: 3 })
    expect(result.data).toHaveLength(3)
    expect(result.meta.total).toBe(5)
    expect(result.meta.last_page).toBe(2)
    expect(result.meta.current_page).toBe(1)
  })

  it('devuelve la segunda pagina correctamente', async () => {
    const result = await getPage({ page: 2, perPage: 3 })
    expect(result.data).toHaveLength(2)
    expect(result.meta.current_page).toBe(2)
    expect(result.meta.last_page).toBe(2)
  })

  it('filtra por termino de busqueda (nombre)', async () => {
    const result = await getPage({ search: 'agua', page: 1, perPage: 10 })
    expect(result.data).toHaveLength(1)
    expect(result.data[0]?.uuid).toBe('p-1')
  })

  it('busqueda es insensible a mayusculas y acentos', async () => {
    const result = await getPage({ search: 'Café', page: 1, perPage: 10 })
    expect(result.data).toHaveLength(1)
    expect(result.data[0]?.uuid).toBe('p-2')
  })

  it('busqueda por SKU', async () => {
    const result = await getPage({ search: 'ar-001', page: 1, perPage: 10 })
    expect(result.data).toHaveLength(1)
    expect(result.data[0]?.uuid).toBe('p-3')
  })

  it('busqueda sin resultados devuelve data vacia con total 0', async () => {
    const result = await getPage({ search: 'xyz-no-existe', page: 1, perPage: 10 })
    expect(result.data).toHaveLength(0)
    expect(result.meta.total).toBe(0)
    expect(result.meta.last_page).toBe(1)
    expect(result.meta.from).toBeNull()
    expect(result.meta.to).toBeNull()
  })

  it('devuelve Product con campos correctos (mapeo fromLocal)', async () => {
    const result = await getPage({ search: 'agua', page: 1, perPage: 10 })
    const product = result.data[0]!
    expect(product.uuid).toBe('p-1')
    expect(product.pricing.price).toBe(100)
    expect(product.flags.track_inventory).toBe(true)
    expect(product.category?.uuid).toBe('cat-1')
    expect(product.unit?.code).toBe('PZA')
    expect(product.tax?.rate).toBe(0.16)
  })
})
