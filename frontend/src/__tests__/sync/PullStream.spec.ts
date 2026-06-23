/**
 * Tests de PullStream (Fase 2, Iteracion 2).
 *
 * jsdom no trae fetch: se stubea con vi.stubGlobal.
 * Dexie opera sobre fake-indexeddb (setup.ts). Cada test empieza limpio.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { db, SETTING_LAST_PULL } from '@/db/schema'
import { PullStream } from '@/sync/PullStream'

// ---------------------------------------------------------------------------
// fetch stub (global para el modulo)
// ---------------------------------------------------------------------------

const fetchMock = vi.fn()
vi.stubGlobal('fetch', fetchMock)

// ---------------------------------------------------------------------------
// Helpers de fixtures
// ---------------------------------------------------------------------------

function makeApiProduct(uuid: string, updatedAt = '2026-06-01T00:00:00Z') {
  return {
    uuid,
    sku:        `SKU-${uuid}`,
    name:       `Producto ${uuid}`,
    pricing:    { price: 100, cost: 60, has_discount: false },
    flags:      { track_inventory: true, is_sellable: true, is_purchasable: true, allow_decimals: false },
    status:     'active',
    category:   null,
    unit:       undefined,
    tax:        null,
    updated_at: updatedAt,
    created_at: updatedAt,
  }
}

function makeApiTax(uuid: string) {
  return {
    uuid,
    code:         `TAX-${uuid}`,
    name:         `Impuesto ${uuid}`,
    description:  null,
    rate:         0.16,
    rate_percent: 16,
    type:         null,
    is_inclusive: true,
    is_active:    true,
    is_default:   false,
    created_at:   '2026-01-01T00:00:00Z',
    updated_at:   '2026-01-01T00:00:00Z',
  }
}

function makeApiCustomer(uuid: string, updatedAt = '2026-06-01T00:00:00Z') {
  return {
    uuid,
    code:       `C-${uuid}`,
    type:       'individual' as const,
    name:       `Cliente ${uuid}`,
    legal_name: null,
    tax:        { tax_id: null, data: null },
    contact:    { email: null, phone: null, mobile: null },
    address:    { line: null, city: null, state: null, postal_code: null, country_code: null },
    credit:     { limit: 0, balance: 0, available: 0 },
    flags:      { is_active: true, is_blocked: false, blocked_reason: null },
    notes:      null,
    updated_at: updatedAt,
    created_at: updatedAt,
  }
}

function makeLocalProduct(uuid: string, updatedAt: string) {
  return {
    uuid,
    sku:            `SKU-${uuid}`,
    name:           `Producto ${uuid}`,
    price:          100,
    cost:           60,
    hasDiscount:    false,
    trackInventory: true,
    isSellable:     true,
    isPurchasable:  true,
    allowDecimals:  false,
    status:         'active',
    categoryUuid:   null,
    categoryName:   null,
    categorySlug:   null,
    unitUuid:       null,
    unitCode:       null,
    unitName:       null,
    unitSymbol:     null,
    taxUuid:        null,
    taxCode:        null,
    taxName:        null,
    taxRate:        null,
    taxIsInclusive: null,
    searchBlob:     [uuid],
    updatedAt,
  }
}

function makeLocalCustomer(uuid: string, updatedAt: string) {
  return {
    uuid,
    code:          null,
    type:          'individual' as const,
    name:          `Cliente ${uuid}`,
    legalName:     null,
    taxId:         null,
    email:         null,
    phone:         null,
    mobile:        null,
    addressLine:   null,
    city:          null,
    state:         null,
    postalCode:    null,
    countryCode:   null,
    creditLimit:   0,
    creditBalance: 0,
    isActive:      true,
    isBlocked:     false,
    blockedReason: null,
    notes:         null,
    updatedAt,
  }
}

function makeChangesResponse(
  data: Record<string, unknown> = {},
  snapshotTs = '2026-06-10T00:00:00Z',
) {
  return {
    ok:   true,
    json: () =>
      Promise.resolve({
        data,
        meta: { snapshot_timestamp: snapshotTs, has_more: false, next_cursor: null },
      }),
  }
}

function makePull(opts: Partial<ConstructorParameters<typeof PullStream>[0]> = {}) {
  return new PullStream({ tenantSlug: 'demo', ...opts })
}

// ---------------------------------------------------------------------------
// Limpieza
// ---------------------------------------------------------------------------

beforeEach(async () => {
  await db.products.clear()
  await db.taxes.clear()
  await db.customers.clear()
  await db.settings.clear()
  vi.clearAllMocks()
})

afterEach(() => {
  vi.clearAllMocks()
})

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('PullStream.pullOnce', () => {
  it('llama GET sin since cuando no hay SETTING_LAST_PULL', async () => {
    fetchMock.mockResolvedValueOnce(makeChangesResponse())

    await makePull().pullOnce()

    expect(fetchMock).toHaveBeenCalledOnce()
    const url = fetchMock.mock.calls[0]![0] as string
    expect(url).toContain('/sync/changes')
    expect(url).toContain('entities=products,taxes,customers')
    expect(url).not.toContain('since=')
  })

  it('incluye since cuando SETTING_LAST_PULL esta en db', async () => {
    const lastPull = '2026-06-01T00:00:00Z'
    await db.settings.put({ key: SETTING_LAST_PULL, value: lastPull, updatedAt: lastPull })
    fetchMock.mockResolvedValueOnce(makeChangesResponse())

    await makePull().pullOnce()

    const url = fetchMock.mock.calls[0]![0] as string
    expect(url).toContain(`since=${encodeURIComponent(lastPull)}`)
  })

  it('aplica created: upserta productos en IndexedDB', async () => {
    fetchMock.mockResolvedValueOnce(
      makeChangesResponse({
        products: {
          created: [makeApiProduct('p-1'), makeApiProduct('p-2')],
          updated: [],
          deleted: [],
        },
      }),
    )

    const result = await makePull().pullOnce()

    expect(await db.products.count()).toBe(2)
    expect(result.products.created).toBe(2)
    expect(result.products.updated).toBe(0)
  })

  it('updated LWW: remote mas reciente => aplica producto', async () => {
    await db.products.put(makeLocalProduct('p-1', '2026-01-01T00:00:00Z'))

    const remote = { ...makeApiProduct('p-1', '2026-06-01T00:00:00Z'), name: 'Actualizado' }
    fetchMock.mockResolvedValueOnce(
      makeChangesResponse({ products: { created: [], updated: [remote], deleted: [] } }),
    )

    const result = await makePull().pullOnce()

    expect((await db.products.get('p-1'))?.name).toBe('Actualizado')
    expect(result.products.updated).toBe(1)
    expect(result.products.skipped).toBe(0)
  })

  it('updated LWW: local mas reciente => no sobreescribe producto', async () => {
    await db.products.put(makeLocalProduct('p-1', '2026-06-10T00:00:00Z'))

    const remote = { ...makeApiProduct('p-1', '2026-01-01T00:00:00Z'), name: 'RemotoViejo' }
    fetchMock.mockResolvedValueOnce(
      makeChangesResponse({ products: { created: [], updated: [remote], deleted: [] } }),
    )

    const result = await makePull().pullOnce()

    expect((await db.products.get('p-1'))?.name).toBe('Producto p-1') // sin cambio
    expect(result.products.updated).toBe(0)
    expect(result.products.skipped).toBe(1)
  })

  it('deleted: elimina productos de IndexedDB', async () => {
    await db.products.put(makeLocalProduct('p-del', '2026-01-01T00:00:00Z'))
    fetchMock.mockResolvedValueOnce(
      makeChangesResponse({ products: { created: [], updated: [], deleted: [{ uuid: 'p-del' }] } }),
    )

    const result = await makePull().pullOnce()

    expect(await db.products.get('p-del')).toBeUndefined()
    expect(result.products.deleted).toBe(1)
  })

  it('taxes: servidor siempre gana en updated (sin LWW)', async () => {
    await db.taxes.put({ uuid: 't-1', code: 'IVA', name: 'Local', rate: 0.16, isInclusive: true })

    const remote = { ...makeApiTax('t-1'), name: 'Actualizado' }
    fetchMock.mockResolvedValueOnce(
      makeChangesResponse({ taxes: { created: [], updated: [remote], deleted: [] } }),
    )

    const result = await makePull().pullOnce()

    expect((await db.taxes.get('t-1'))?.name).toBe('Actualizado')
    expect(result.taxes.updated).toBe(1)
    expect(result.taxes.skipped).toBe(0)
  })

  it('customers updated LWW: remote mas reciente => aplica', async () => {
    await db.customers.put(makeLocalCustomer('c-1', '2026-01-01T00:00:00Z'))

    const remote = { ...makeApiCustomer('c-1', '2026-06-01T00:00:00Z'), name: 'Nuevo' }
    fetchMock.mockResolvedValueOnce(
      makeChangesResponse({ customers: { created: [], updated: [remote], deleted: [] } }),
    )

    const result = await makePull().pullOnce()

    expect((await db.customers.get('c-1'))?.name).toBe('Nuevo')
    expect(result.customers.updated).toBe(1)
    expect(result.customers.skipped).toBe(0)
  })

  it('customers updated LWW: local mas reciente => skip', async () => {
    await db.customers.put(makeLocalCustomer('c-1', '2026-06-10T00:00:00Z'))

    const remote = { ...makeApiCustomer('c-1', '2026-01-01T00:00:00Z'), name: 'Viejo' }
    fetchMock.mockResolvedValueOnce(
      makeChangesResponse({ customers: { created: [], updated: [remote], deleted: [] } }),
    )

    const result = await makePull().pullOnce()

    expect((await db.customers.get('c-1'))?.name).toBe('Cliente c-1') // sin cambio
    expect(result.customers.skipped).toBe(1)
  })

  it('actualiza SETTING_LAST_PULL al snapshot_timestamp del servidor', async () => {
    const snapshotTs = '2026-06-10T12:00:00Z'
    fetchMock.mockResolvedValueOnce(makeChangesResponse({}, snapshotTs))

    await makePull().pullOnce()

    const setting = await db.settings.get(SETTING_LAST_PULL)
    expect(setting?.value).toBe(snapshotTs)
  })

  it('error de red: networkError=true y SETTING_LAST_PULL no cambia', async () => {
    fetchMock.mockRejectedValueOnce(new Error('Network error'))

    const result = await makePull().pullOnce()

    expect(result.networkError).toBe(true)
    expect(await db.settings.get(SETTING_LAST_PULL)).toBeUndefined()
  })

  it('HTTP 4xx: networkError=true', async () => {
    fetchMock.mockResolvedValueOnce({
      ok:     false,
      status: 401,
      json:   () => Promise.resolve({ message: 'Unauthenticated.' }),
    })

    const result = await makePull().pullOnce()

    expect(result.networkError).toBe(true)
    expect(await db.settings.get(SETTING_LAST_PULL)).toBeUndefined()
  })

  it('entidades ausentes en respuesta no causan error', async () => {
    fetchMock.mockResolvedValueOnce(makeChangesResponse({})) // data = {}

    const result = await makePull().pullOnce()

    expect(result.networkError).toBe(false)
    expect(result.products.created).toBe(0)
    expect(result.taxes.created).toBe(0)
    expect(result.customers.created).toBe(0)
  })

  it('emite sync.pull.start y sync.pull.done en exito', async () => {
    fetchMock.mockResolvedValueOnce(makeChangesResponse())

    const events: string[] = []
    const pull = makePull({ onEvent: (e) => events.push(e.type) })
    await pull.pullOnce()

    expect(events).toContain('sync.pull.start')
    expect(events).toContain('sync.pull.done')
  })

  it('emite sync.pull.error cuando falla la red', async () => {
    fetchMock.mockRejectedValueOnce(new Error('timeout'))

    const events: Array<{ type: string; error?: string }> = []
    const pull = makePull({ onEvent: (e) => events.push(e as (typeof events)[0]) })
    await pull.pullOnce()

    const errEvent = events.find((e) => e.type === 'sync.pull.error')
    expect(errEvent).toBeDefined()
    expect(errEvent?.error).toBe('timeout')
  })

  it('usa apiBase en la URL cuando se proporciona', async () => {
    fetchMock.mockResolvedValueOnce(makeChangesResponse())

    await makePull({ apiBase: 'https://api.example.com' }).pullOnce()

    const url = fetchMock.mock.calls[0]![0] as string
    expect(url).toContain('https://api.example.com/sync/changes')
  })
})
