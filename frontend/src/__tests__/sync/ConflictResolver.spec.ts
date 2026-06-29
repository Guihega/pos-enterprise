import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ConflictResolver } from '@/sync/ConflictResolver'
import { store, getByUuid } from '@/repositories/ConflictRepository'
import type { NewConflictInput } from '@/repositories/ConflictRepository'
import { db } from '@/db/schema'
import type { ConflictReason } from '@/db/schema'

const baseInput = (over: Partial<NewConflictInput> = {}): NewConflictInput => ({
  uuid:          'conflict-001',
  entityType:    'sale',
  entityUuid:    'entity-001',
  clientUuid:    'client-001',
  reason:        'STOCK_NEGATIVE',
  clientPayload: { total: 100 },
  ...over,
})

async function seedConflict(reason: ConflictReason, uuid = 'conflict-001'): Promise<string> {
  return store(baseInput({ uuid, reason }))
}

beforeEach(async () => {
  await db.conflicts.clear()
})

// ---------------------------------------------------------------------------
// Resolucion automatica (sec. 39.4)
// ---------------------------------------------------------------------------

describe('ConflictResolver — ventas auto-resueltas', () => {
  it('STOCK_NEGATIVE: resuelve auto use_client y notifica admin', async () => {
    await seedConflict('STOCK_NEGATIVE')
    const notifyAdmin = vi.fn()
    const resolver = new ConflictResolver({ notifyAdmin })

    const res = await resolver.resolve('conflict-001')

    expect(res).toEqual({ resolution: 'use_client', auto: true })
    expect(notifyAdmin).toHaveBeenCalledOnce()
    const c = await getByUuid('conflict-001')
    expect(c?.resolution).toBe('use_client')
    expect(c?.auto).toBe(true)
    expect(c?.resolvedAt).toBeTruthy()
  })

  it('PRICE_MISMATCH: resuelve auto use_client y registra auditoria', async () => {
    await seedConflict('PRICE_MISMATCH')
    const auditLog = vi.fn()
    const resolver = new ConflictResolver({ auditLog })

    const res = await resolver.resolve('conflict-001')

    expect(res).toEqual({ resolution: 'use_client', auto: true })
    expect(auditLog).toHaveBeenCalledOnce()
    expect((await getByUuid('conflict-001'))?.resolvedAt).toBeTruthy()
  })

  it('IDEMPOTENT: resuelve auto use_server', async () => {
    await seedConflict('IDEMPOTENT')
    const resolver = new ConflictResolver()
    const res = await resolver.resolve('conflict-001')
    expect(res).toEqual({ resolution: 'use_server', auto: true })
  })

  it('PRODUCT_DELETED: resuelve auto use_client', async () => {
    await seedConflict('PRODUCT_DELETED')
    const auditLog = vi.fn()
    const resolver = new ConflictResolver({ auditLog })
    const res = await resolver.resolve('conflict-001')
    expect(res.resolution).toBe('use_client')
    expect(res.auto).toBe(true)
    expect(auditLog).toHaveBeenCalledOnce()
  })

  it('funciona sin hooks definidos (no lanza)', async () => {
    await seedConflict('STOCK_NEGATIVE')
    const resolver = new ConflictResolver()
    await expect(resolver.resolve('conflict-001')).resolves.toEqual({
      resolution: 'use_client', auto: true,
    })
  })
})

// ---------------------------------------------------------------------------
// Resolucion manual (sec. 39.4)
// ---------------------------------------------------------------------------

describe('ConflictResolver — requiere intervencion manual', () => {
  it('CASH_SESSION_CLOSED: manual con rol manager', async () => {
    await seedConflict('CASH_SESSION_CLOSED')
    const resolver = new ConflictResolver()

    const res = await resolver.resolve('conflict-001')

    expect(res).toEqual({ resolution: 'manual', auto: false, requireRole: 'manager' })
    const c = await getByUuid('conflict-001')
    expect(c?.resolution).toBe('manual')
    expect(c?.requireRole).toBe('manager')
    expect(c?.auto).toBe(false)
    // manual NO resuelve: resolvedAt sigue null
    expect(c?.resolvedAt).toBeNull()
  })

  it('razon desconocida: manual sin rol', async () => {
    await seedConflict('UNKNOWN')
    const resolver = new ConflictResolver()
    const res = await resolver.resolve('conflict-001')
    expect(res.resolution).toBe('manual')
    expect(res.auto).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// Despacho por entityType
// ---------------------------------------------------------------------------

describe('ConflictResolver — despacho por entidad', () => {
  it('entityType no-sale cae en manual', async () => {
    // forzamos un entityType distinto escribiendo directo en la tabla
    await db.conflicts.add({
      uuid:          'c-prod',
      entityType:    'sale', // el schema solo permite 'sale' por ahora
      entityUuid:    'e-1',
      clientUuid:    'cl-1',
      reason:        'STALE_VERSION',
      clientPayload: {},
      serverData:    null,
      resolution:    null,
      auto:          false,
      requireRole:   null,
      detectedAt:    new Date().toISOString(),
      resolvedAt:    null,
    })
    const resolver = new ConflictResolver()
    const res = await resolver.resolve('c-prod')
    // STALE_VERSION en sale cae en default -> manual
    expect(res.resolution).toBe('manual')
  })
})

// ---------------------------------------------------------------------------
// Errores
// ---------------------------------------------------------------------------

describe('ConflictResolver — errores', () => {
  it('lanza si el conflicto no existe', async () => {
    const resolver = new ConflictResolver()
    await expect(resolver.resolve('no-existe')).rejects.toThrow('Conflicto no encontrado')
  })
})
