/**
 * Tests de SnapshotService (Fase 2, Iteracion 2).
 *
 * Doc maestro sec. 38.6. fullSync de products y los listados SDK
 * (listTaxes/listCustomers) se mockean. Las tablas taxes/customers operan
 * sobre Dexie real (fake-indexeddb).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { db, SETTING_LAST_PULL } from '@/db/schema'
import {
  SnapshotService,
  SNAPSHOT_MAX_AGE_MS,
  type SnapshotProgress,
} from '@/sync/SnapshotService'

// ---- mocks ----

vi.mock('@/lib/api/generated', () => ({
  listTaxes: vi.fn(),
  listCustomers: vi.fn(),
}))

vi.mock('@/repositories/ProductRepository', async () => {
  const actual = await vi.importActual<typeof import('@/repositories/ProductRepository')>(
    '@/repositories/ProductRepository',
  )
  return {
    ...actual,
    fullSync: vi.fn(),
  }
})

import { listTaxes, listCustomers } from '@/lib/api/generated'
import { fullSync, hasData } from '@/repositories/ProductRepository'

// ---- fixtures ----

function makeApiTax(uuid: string) {
  return {
    uuid, code: `TAX-${uuid}`, name: `Impuesto ${uuid}`, description: null,
    rate: 0.16, rate_percent: 16, type: null, is_inclusive: true,
    is_active: true, is_default: false,
    created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z',
  }
}

function makeApiCustomer(uuid: string) {
  return {
    uuid, code: `C-${uuid}`, type: 'individual' as const, name: `Cliente ${uuid}`,
    legal_name: null, tax: { tax_id: null, data: null },
    contact: { email: null, phone: null, mobile: null },
    address: { line: null, city: null, state: null, postal_code: null, country_code: null },
    credit: { limit: 0, balance: 0, available: 0 },
    flags: { is_active: true, is_blocked: false, blocked_reason: null },
    notes: null, created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z',
  }
}

function listResponse(items: unknown[]) {
  return { data: { data: items, meta: {}, links: {} }, error: undefined }
}

function makeService(onProgress?: (p: SnapshotProgress) => void) {
  return new SnapshotService({ tenantSlug: 'demo', onProgress })
}

// ---- setup ----

beforeEach(async () => {
  await db.products.clear()
  await db.taxes.clear()
  await db.customers.clear()
  await db.settings.clear()
  vi.clearAllMocks()
  // Por defecto, fullSync no hace nada (los tests que cuentan products
  // siembran la tabla manualmente).
  vi.mocked(fullSync).mockResolvedValue(undefined)
})

afterEach(() => { vi.clearAllMocks() })

// ---------------------------------------------------------------------------
// needsSnapshot (35.4 paso 5)
// ---------------------------------------------------------------------------

describe('SnapshotService.needsSnapshot', () => {
  it('true cuando no hay datos de productos', async () => {
    expect(await makeService().needsSnapshot()).toBe(true)
  })

  it('true cuando hay datos pero nunca se sincronizo', async () => {
    await db.products.put({
      uuid: 'p-1', sku: 'S', name: 'P', price: 1, cost: 0,
      hasDiscount: false, trackInventory: false, isSellable: true,
      isPurchasable: true, allowDecimals: false, status: 'active',
      categoryUuid: null, categoryName: null, categorySlug: null,
      unitUuid: null, unitCode: null, unitName: null, unitSymbol: null,
      taxUuid: null, taxCode: null, taxName: null, taxRate: null, taxIsInclusive: null,
      searchBlob: ['p'], updatedAt: '2026-01-01T00:00:00Z',
    })
    // sin SETTING_LAST_PRODUCT_SYNC en settings
    expect(await makeService().needsSnapshot()).toBe(true)
  })

  it('false cuando hay datos y el ultimo sync es reciente', async () => {
    await db.products.put({
      uuid: 'p-1', sku: 'S', name: 'P', price: 1, cost: 0,
      hasDiscount: false, trackInventory: false, isSellable: true,
      isPurchasable: true, allowDecimals: false, status: 'active',
      categoryUuid: null, categoryName: null, categorySlug: null,
      unitUuid: null, unitCode: null, unitName: null, unitSymbol: null,
      taxUuid: null, taxCode: null, taxName: null, taxRate: null, taxIsInclusive: null,
      searchBlob: ['p'], updatedAt: '2026-01-01T00:00:00Z',
    })
    const recent = new Date().toISOString()
    await db.settings.put({ key: 'catalog:last_full_sync', value: recent, updatedAt: recent })
    expect(await makeService().needsSnapshot()).toBe(false)
  })

  it('true cuando el ultimo sync supera SNAPSHOT_MAX_AGE_MS', async () => {
    await db.products.put({
      uuid: 'p-1', sku: 'S', name: 'P', price: 1, cost: 0,
      hasDiscount: false, trackInventory: false, isSellable: true,
      isPurchasable: true, allowDecimals: false, status: 'active',
      categoryUuid: null, categoryName: null, categorySlug: null,
      unitUuid: null, unitCode: null, unitName: null, unitSymbol: null,
      taxUuid: null, taxCode: null, taxName: null, taxRate: null, taxIsInclusive: null,
      searchBlob: ['p'], updatedAt: '2026-01-01T00:00:00Z',
    })
    const old = new Date(Date.now() - SNAPSHOT_MAX_AGE_MS - 1000).toISOString()
    await db.settings.put({ key: 'catalog:last_full_sync', value: old, updatedAt: old })
    expect(await makeService().needsSnapshot()).toBe(true)
  })
})

// ---------------------------------------------------------------------------
// run (38.6)
// ---------------------------------------------------------------------------

describe('SnapshotService.run', () => {
  it('llama fullSync de products con el tenant', async () => {
    vi.mocked(listCustomers).mockResolvedValue(listResponse([]) as never)
    vi.mocked(listTaxes).mockResolvedValue(listResponse([]) as never)

    await makeService().run()

    expect(fullSync).toHaveBeenCalledWith('demo')
  })

  it('upserta customers y taxes en IndexedDB', async () => {
    vi.mocked(listCustomers).mockResolvedValue(
      listResponse([makeApiCustomer('c-1'), makeApiCustomer('c-2')]) as never,
    )
    vi.mocked(listTaxes).mockResolvedValue(
      listResponse([makeApiTax('t-1')]) as never,
    )

    const result = await makeService().run()

    expect(await db.customers.count()).toBe(2)
    expect(await db.taxes.count()).toBe(1)
    expect(result.customers).toBe(2)
    expect(result.taxes).toBe(1)
  })

  it('marca SETTING_LAST_PULL al completar', async () => {
    vi.mocked(listCustomers).mockResolvedValue(listResponse([]) as never)
    vi.mocked(listTaxes).mockResolvedValue(listResponse([]) as never)

    const result = await makeService().run()

    const setting = await db.settings.get(SETTING_LAST_PULL)
    expect(setting?.value).toBe(result.completedAt)
  })

  it('emite progreso por entidad en orden products -> customers -> taxes', async () => {
    vi.mocked(listCustomers).mockResolvedValue(listResponse([makeApiCustomer('c-1')]) as never)
    vi.mocked(listTaxes).mockResolvedValue(listResponse([makeApiTax('t-1')]) as never)

    const events: SnapshotProgress[] = []
    await makeService((p) => events.push(p)).run()

    const order = events.filter((e) => e.phase === 'start').map((e) => e.entity)
    expect(order).toEqual(['products', 'customers', 'taxes'])
  })

  it('progreso done incluye el conteo cargado', async () => {
    vi.mocked(listCustomers).mockResolvedValue(
      listResponse([makeApiCustomer('c-1'), makeApiCustomer('c-2')]) as never,
    )
    vi.mocked(listTaxes).mockResolvedValue(listResponse([makeApiTax('t-1')]) as never)

    const events: SnapshotProgress[] = []
    await makeService((p) => events.push(p)).run()

    const customersDone = events.find((e) => e.entity === 'customers' && e.phase === 'done')
    expect(customersDone?.count).toBe(2)
  })

  it('lanza si listCustomers devuelve error', async () => {
    vi.mocked(listCustomers).mockResolvedValue({ data: undefined, error: { message: 'fail' } } as never)
    vi.mocked(listTaxes).mockResolvedValue(listResponse([]) as never)

    await expect(makeService().run()).rejects.toThrow('snapshot customers')
  })

  it('lanza si listTaxes devuelve error', async () => {
    vi.mocked(listCustomers).mockResolvedValue(listResponse([]) as never)
    vi.mocked(listTaxes).mockResolvedValue({ data: undefined, error: { message: 'fail' } } as never)

    await expect(makeService().run()).rejects.toThrow('snapshot taxes')
  })
})

// hasData se reexporta del repo real (no mockeado), confirmamos que el
// import sigue siendo la funcion real.
describe('integracion con ProductRepository', () => {
  it('hasData refleja el estado real de la tabla', async () => {
    expect(await hasData()).toBe(false)
  })
})
