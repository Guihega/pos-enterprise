/**
 * Tests de SyncEngine (Fase 2, Iteracion 2).
 *
 * PushQueue y PullStream se mockean con class (compatible con new).
 * ConflictRepository y ConflictResolver corren sobre fake-indexeddb real.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { db } from '@/db/schema'
import type { DrainResult, PushQueueOptions } from '@/sync/PushQueue'
import type { PullResult, PullStreamOptions } from '@/sync/PullStream'

// ---------------------------------------------------------------------------
// Estado compartido — se reasigna en beforeEach para que los metodos
// deleguen al vi.fn() correcto en cada test.
// ---------------------------------------------------------------------------

let mockDrainOnce = vi.fn()
let mockPullOnce  = vi.fn()
// Captura de opciones del constructor para assertions
let capturedPushOpts: any
let capturedPullOpts: any

// ---------------------------------------------------------------------------
// Mocks de modulos con class para que `new PushQueue()` funcione.
// Los metodos leen mockDrainOnce/mockPullOnce en tiempo de llamada
// (closure sobre la variable, no sobre el valor).
// ---------------------------------------------------------------------------

vi.mock('@/sync/PushQueue', () => ({
  PushQueue: class {
    constructor(opts: PushQueueOptions) { capturedPushOpts = opts }
    drainOnce(...args: unknown[]) { return mockDrainOnce(...args) }
  },
}))

vi.mock('@/sync/PullStream', () => ({
  PullStream: class {
    constructor(opts: PullStreamOptions) { capturedPullOpts = opts }
    pullOnce(...args: unknown[]) { return mockPullOnce(...args) }
  },
}))

import { SyncEngine, inferReason } from '@/sync/SyncEngine'

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const EMPTY_DRAIN: DrainResult = {
  sent: 0, succeeded: 0, conflicts: 0, failed: 0, networkError: false,
}
const ZERO = { created: 0, updated: 0, deleted: 0, skipped: 0 }
const EMPTY_PULL: PullResult = {
  products: { ...ZERO }, taxes: { ...ZERO }, customers: { ...ZERO },
  snapshotTimestamp: '', networkError: false,
}

function makeEngine(opts: Partial<ConstructorParameters<typeof SyncEngine>[0]> = {}) {
  return new SyncEngine({ tenantSlug: 'demo', ...opts })
}

// ---------------------------------------------------------------------------
// Setup / teardown
// ---------------------------------------------------------------------------

beforeEach(async () => {
  await db.conflicts.clear()
  // Reasignar variables modulo: la clase mock lee la referencia actual
  // en tiempo de llamada gracias al closure sobre let.
  mockDrainOnce = vi.fn().mockResolvedValue(EMPTY_DRAIN)
  mockPullOnce  = vi.fn().mockResolvedValue(EMPTY_PULL)
})

afterEach(() => { vi.clearAllMocks() })

// ---------------------------------------------------------------------------
// inferReason
// ---------------------------------------------------------------------------

describe('inferReason', () => {
  it('devuelve UNKNOWN para mensaje undefined', () => {
    expect(inferReason(undefined)).toBe('UNKNOWN')
  })
  it('devuelve UNKNOWN para texto no reconocido', () => {
    expect(inferReason('algun error desconocido')).toBe('UNKNOWN')
  })
  it('reconoce STOCK_NEGATIVE en minusculas', () => {
    expect(inferReason('stock_negative detected')).toBe('STOCK_NEGATIVE')
  })
  it('reconoce PRICE_MISMATCH en texto mixto', () => {
    expect(inferReason('Price_Mismatch: precio diferente al cache')).toBe('PRICE_MISMATCH')
  })
  it('reconoce CASH_SESSION_CLOSED', () => {
    expect(inferReason('CASH_SESSION_CLOSED por otro dispositivo')).toBe('CASH_SESSION_CLOSED')
  })
  it('reconoce IDEMPOTENT', () => {
    expect(inferReason('IDEMPOTENT: sale already exists')).toBe('IDEMPOTENT')
  })
})

// ---------------------------------------------------------------------------
// pushOnce / pullOnce / syncOnce
// ---------------------------------------------------------------------------

describe('SyncEngine.pushOnce', () => {
  it('delega a PushQueue.drainOnce y retorna su resultado', async () => {
    const expected: DrainResult = {
      sent: 3, succeeded: 3, conflicts: 0, failed: 0, networkError: false,
    }
    mockDrainOnce.mockResolvedValueOnce(expected)
    const result = await makeEngine().pushOnce()
    expect(result).toEqual(expected)
    expect(mockDrainOnce).toHaveBeenCalledOnce()
  })
})

describe('SyncEngine.pullOnce', () => {
  it('delega a PullStream.pullOnce y retorna su resultado', async () => {
    const ts = '2026-06-10T00:00:00Z'
    mockPullOnce.mockResolvedValueOnce({ ...EMPTY_PULL, snapshotTimestamp: ts })
    const result = await makeEngine().pullOnce()
    expect(result.snapshotTimestamp).toBe(ts)
    expect(mockPullOnce).toHaveBeenCalledOnce()
  })
})

describe('SyncEngine.syncOnce', () => {
  it('ejecuta push y pull y retorna ambos resultados', async () => {
    const result = await makeEngine().syncOnce()
    expect(mockDrainOnce).toHaveBeenCalledOnce()
    expect(mockPullOnce).toHaveBeenCalledOnce()
    expect(result).toHaveProperty('push')
    expect(result).toHaveProperty('pull')
  })
  it('ejecuta pull aunque push tenga networkError', async () => {
    mockDrainOnce.mockResolvedValueOnce({ ...EMPTY_DRAIN, networkError: true })
    await makeEngine().syncOnce()
    expect(mockPullOnce).toHaveBeenCalledOnce()
  })
})

// ---------------------------------------------------------------------------
// Constructor — opciones propagadas
// ---------------------------------------------------------------------------

describe('SyncEngine constructor', () => {
  it('pasa tenantSlug y apiBase a PushQueue', () => {
    makeEngine({ tenantSlug: 'tienda', apiBase: 'https://api.test' })
    expect(capturedPushOpts.tenantSlug).toBe('tienda')
    expect(capturedPushOpts.apiBase).toBe('https://api.test')
  })
  it('pasa tenantSlug y apiBase a PullStream', () => {
    makeEngine({ tenantSlug: 'tienda', apiBase: 'https://api.test' })
    expect(capturedPullOpts.tenantSlug).toBe('tienda')
    expect(capturedPullOpts.apiBase).toBe('https://api.test')
  })
})

// ---------------------------------------------------------------------------
// onConflict — almacenamiento + resolucion
// ---------------------------------------------------------------------------

function makeCtx(overrides: Partial<{
  clientUuid: string; entityType: string; entityUuid: string
  message: string | undefined; clientPayload: unknown
  serverData: Record<string, unknown> | undefined
}> = {}) {
  return {
    clientUuid: 'client-1', entityType: 'sale', entityUuid: 'sale-1',
    message: 'STOCK_NEGATIVE', clientPayload: { amount: 100 }, serverData: undefined,
    ...overrides,
  }
}

describe('SyncEngine onConflict', () => {
  it('almacena conflicto con reason inferido del mensaje', async () => {
    makeEngine()
    await capturedPushOpts.onConflict(makeCtx({ message: 'PRICE_MISMATCH detected' }))
    const list = await db.conflicts.toArray()
    expect(list).toHaveLength(1)
    expect(list[0]!.reason).toBe('PRICE_MISMATCH')
    expect(list[0]!.entityType).toBe('sale')
    expect(list[0]!.clientUuid).toBe('client-1')
  })
  it('almacena reason UNKNOWN cuando el mensaje no es reconocido', async () => {
    makeEngine()
    await capturedPushOpts.onConflict(makeCtx({ message: 'error inesperado del servidor' }))
    const list = await db.conflicts.toArray()
    expect(list[0]!.reason).toBe('UNKNOWN')
  })
  it('resuelve STOCK_NEGATIVE automaticamente con use_client', async () => {
    makeEngine()
    await capturedPushOpts.onConflict(makeCtx({ message: 'STOCK_NEGATIVE' }))
    const list = await db.conflicts.toArray()
    expect(list[0]!.resolution).toBe('use_client')
    expect(list[0]!.auto).toBe(true)
    expect(list[0]!.resolvedAt).not.toBeNull()
  })
  it('marca CASH_SESSION_CLOSED como manual con rol manager', async () => {
    makeEngine()
    await capturedPushOpts.onConflict(makeCtx({ message: 'CASH_SESSION_CLOSED' }))
    const list = await db.conflicts.toArray()
    expect(list[0]!.resolution).toBe('manual')
    expect(list[0]!.auto).toBe(false)
    expect(list[0]!.requireRole).toBe('manager')
  })
  it('invoca hook notifyAdmin para STOCK_NEGATIVE', async () => {
    const notifyAdmin = vi.fn()
    makeEngine({ resolverHooks: { notifyAdmin } })
    await capturedPushOpts.onConflict(makeCtx({ message: 'STOCK_NEGATIVE' }))
    expect(notifyAdmin).toHaveBeenCalledOnce()
  })
})
