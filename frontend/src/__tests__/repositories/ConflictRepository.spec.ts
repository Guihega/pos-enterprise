import { beforeEach, describe, expect, it } from 'vitest'
import {
  store,
  markResolved,
  requireManual,
  getUnresolved,
  countUnresolved,
  getByUuid,
  getByEntity,
} from '@/repositories/ConflictRepository'
import type { NewConflictInput } from '@/repositories/ConflictRepository'
import { db } from '@/db/schema'

const baseInput = (over: Partial<NewConflictInput> = {}): NewConflictInput => ({
  uuid:          'conflict-001',
  entityType:    'sale',
  entityUuid:    'entity-001',
  clientUuid:    'client-001',
  reason:        'STOCK_NEGATIVE',
  clientPayload: { total: 100 },
  ...over,
})

beforeEach(async () => {
  await db.conflicts.clear()
})

describe('store', () => {
  it('registra conflicto en estado pendiente', async () => {
    const uuid = await store(baseInput())
    const c = await getByUuid(uuid)
    expect(c?.resolution).toBeNull()
    expect(c?.resolvedAt).toBeNull()
    expect(c?.reason).toBe('STOCK_NEGATIVE')
    expect(c?.detectedAt).toBeTruthy()
  })

  it('serverData default a null si no se provee', async () => {
    await store(baseInput())
    const c = await getByUuid('conflict-001')
    expect(c?.serverData).toBeNull()
  })

  it('guarda serverData si se provee', async () => {
    await store(baseInput({ serverData: { price: 27 } }))
    const c = await getByUuid('conflict-001')
    expect(c?.serverData).toEqual({ price: 27 })
  })

  it('es idempotente por uuid (no duplica)', async () => {
    await store(baseInput())
    await store(baseInput({ reason: 'PRICE_MISMATCH' }))
    const all = await db.conflicts.toArray()
    expect(all).toHaveLength(1)
    // conserva el primero
    expect(all[0].reason).toBe('STOCK_NEGATIVE')
  })
})

describe('markResolved', () => {
  it('marca resuelto con resolution, auto y resolvedAt', async () => {
    await store(baseInput())
    await markResolved('conflict-001', 'use_client', true)
    const c = await getByUuid('conflict-001')
    expect(c?.resolution).toBe('use_client')
    expect(c?.auto).toBe(true)
    expect(c?.resolvedAt).toBeTruthy()
  })
})

describe('requireManual', () => {
  it('marca como manual con rol requerido', async () => {
    await store(baseInput({ reason: 'CASH_SESSION_CLOSED' }))
    await requireManual('conflict-001', 'manager')
    const c = await getByUuid('conflict-001')
    expect(c?.resolution).toBe('manual')
    expect(c?.requireRole).toBe('manager')
    expect(c?.auto).toBe(false)
    // sigue sin resolverse (resolvedAt null)
    expect(c?.resolvedAt).toBeNull()
  })

  it('rol default null si no se especifica', async () => {
    await store(baseInput())
    await requireManual('conflict-001')
    const c = await getByUuid('conflict-001')
    expect(c?.requireRole).toBeNull()
  })
})

describe('getUnresolved / countUnresolved', () => {
  it('solo devuelve conflictos sin resolver', async () => {
    await store(baseInput({ uuid: 'c-1', entityUuid: 'e-1' }))
    await store(baseInput({ uuid: 'c-2', entityUuid: 'e-2' }))
    await store(baseInput({ uuid: 'c-3', entityUuid: 'e-3' }))
    await markResolved('c-2', 'use_client', true)

    const unresolved = await getUnresolved()
    expect(unresolved).toHaveLength(2)
    expect(unresolved.map(c => c.uuid).sort()).toEqual(['c-1', 'c-3'])
    expect(await countUnresolved()).toBe(2)
  })

  it('requireManual NO resuelve: sigue contando como pendiente', async () => {
    await store(baseInput({ uuid: 'c-1' }))
    await requireManual('c-1', 'manager')
    expect(await countUnresolved()).toBe(1)
  })

  it('ordena por detectedAt (FIFO)', async () => {
    await store(baseInput({ uuid: 'c-old', entityUuid: 'e-old' }))
    await new Promise(r => setTimeout(r, 5))
    await store(baseInput({ uuid: 'c-new', entityUuid: 'e-new' }))
    const list = await getUnresolved()
    expect(list[0].uuid).toBe('c-old')
  })
})

describe('getByEntity', () => {
  it('lista conflictos de una entidad', async () => {
    await store(baseInput({ uuid: 'c-1', entityUuid: 'venta-X' }))
    await store(baseInput({ uuid: 'c-2', entityUuid: 'venta-X', reason: 'PRICE_MISMATCH' }))
    await store(baseInput({ uuid: 'c-3', entityUuid: 'venta-Y' }))

    const list = await getByEntity('venta-X')
    expect(list).toHaveLength(2)
  })
})
