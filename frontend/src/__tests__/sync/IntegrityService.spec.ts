/**
 * Tests de IntegrityService (Fase 2, Iteracion 2).
 *
 * Doc maestro sec. 42.3. Opera sobre Dexie real (fake-indexeddb).
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { db, type SyncQueueItem, type SaleLocal } from '@/db/schema'
import { IntegrityService } from '@/sync/IntegrityService'

function makeQueueItem(clientUuid: string, status: SyncQueueItem['status']): SyncQueueItem {
  return {
    clientUuid,
    entityType: 'sale',
    entityUuid: `e-${clientUuid}`,
    operation: 'create',
    payload: { foo: 'bar' },
    clientTimestamp: '2026-06-01T00:00:00Z',
    attempts: 0,
    nextAttemptAt: '2026-06-01T00:00:00Z',
    lastError: null,
    status,
    createdAt: '2026-06-01T00:00:00Z',
  }
}

function makeSale(uuid: string, syncStatus: SaleLocal['syncStatus']): SaleLocal {
  return {
    uuid,
    folio: `F-${uuid}`,
    cashRegisterUuid: 'cr-1',
    cashSessionUuid: 'cs-1',
    customerUuid: null,
    subtotal: 100, discountTotal: 0, taxTotal: 16, total: 116,
    amountPaid: 116, change: 0, paymentMethod: 'cash',
    status: 'completed',
    createdOffline: true,
    syncStatus,
    clientTimestamp: '2026-06-01T00:00:00Z',
    serverTimestamp: null,
    createdAt: '2026-06-01T00:00:00Z',
  }
}

let service: IntegrityService

beforeEach(async () => {
  service = new IntegrityService()
  await Promise.all(db.tables.map((t) => t.clear()))
})

afterEach(async () => {
  await Promise.all(db.tables.map((t) => t.clear()))
})

// ---------------------------------------------------------------------------
// checkIntegrity
// ---------------------------------------------------------------------------

describe('IntegrityService.checkIntegrity', () => {
  it('devuelve ok=true cuando IndexedDB responde', async () => {
    const result = await service.checkIntegrity()
    expect(result.ok).toBe(true)
    expect(result.error).toBeUndefined()
  })

  it('no deja residuo de la prueba en settings', async () => {
    await service.checkIntegrity()
    const probe = await db.settings.get('__integrity_probe__')
    expect(probe).toBeUndefined()
  })
})

// ---------------------------------------------------------------------------
// exportPending
// ---------------------------------------------------------------------------

describe('IntegrityService.exportPending', () => {
  it('exporta solo sync_queue en estado pending', async () => {
    await db.syncQueue.bulkAdd([
      makeQueueItem('q-1', 'pending'),
      makeQueueItem('q-2', 'success'),
      makeQueueItem('q-3', 'pending'),
    ])

    const exported = await service.exportPending()

    expect(exported.syncQueue).toHaveLength(2)
    expect(exported.syncQueue.map((q) => q.clientUuid).sort()).toEqual(['q-1', 'q-3'])
  })

  it('exporta solo sales en estado pending', async () => {
    await db.sales.bulkPut([
      makeSale('s-1', 'pending'),
      makeSale('s-2', 'success'),
      makeSale('s-3', 'pending'),
    ])

    const exported = await service.exportPending()

    expect(exported.sales).toHaveLength(2)
    expect(exported.sales.map((s) => s.uuid).sort()).toEqual(['s-1', 's-3'])
  })

  it('incluye version y exportedAt', async () => {
    const exported = await service.exportPending()
    expect(exported.version).toBe(1)
    expect(typeof exported.exportedAt).toBe('string')
    expect(exported.exportedAt).toContain('T') // ISO
  })

  it('no borra datos al exportar', async () => {
    await db.syncQueue.add(makeQueueItem('q-1', 'pending'))
    await service.exportPending()
    expect(await db.syncQueue.count()).toBe(1)
  })

  it('exporta listas vacias cuando no hay pendientes', async () => {
    const exported = await service.exportPending()
    expect(exported.syncQueue).toEqual([])
    expect(exported.sales).toEqual([])
  })

  it('el export es JSON-serializable', async () => {
    await db.syncQueue.add(makeQueueItem('q-1', 'pending'))
    await db.sales.put(makeSale('s-1', 'pending'))
    const exported = await service.exportPending()
    expect(() => JSON.stringify(exported)).not.toThrow()
    const roundtrip = JSON.parse(JSON.stringify(exported))
    expect(roundtrip.syncQueue).toHaveLength(1)
    expect(roundtrip.sales).toHaveLength(1)
  })
})

// ---------------------------------------------------------------------------
// restore
// ---------------------------------------------------------------------------

describe('IntegrityService.restore', () => {
  it('borra todas las tablas locales', async () => {
    await db.products.put({
      uuid: 'p-1', sku: 'S', name: 'P', price: 1, cost: 0,
      hasDiscount: false, trackInventory: false, isSellable: true,
      isPurchasable: true, allowDecimals: false, status: 'active',
      categoryUuid: null, categoryName: null, categorySlug: null,
      unitUuid: null, unitCode: null, unitName: null, unitSymbol: null,
      taxUuid: null, taxCode: null, taxName: null, taxRate: null, taxIsInclusive: null,
      searchBlob: ['p'], updatedAt: '2026-01-01T00:00:00Z',
    })
    await db.syncQueue.add(makeQueueItem('q-1', 'pending'))
    await db.sales.put(makeSale('s-1', 'pending'))
    await db.settings.put({ key: 'k', value: 'v', updatedAt: '2026-01-01T00:00:00Z' })

    await service.restore()

    expect(await db.products.count()).toBe(0)
    expect(await db.syncQueue.count()).toBe(0)
    expect(await db.sales.count()).toBe(0)
    expect(await db.settings.count()).toBe(0)
  })

  it('devuelve el numero de tablas vaciadas', async () => {
    const result = await service.restore()
    expect(result.clearedTables).toBe(db.tables.length)
  })

  it('no falla con la base ya vacia', async () => {
    await expect(service.restore()).resolves.toEqual({ clearedTables: db.tables.length })
  })
})
