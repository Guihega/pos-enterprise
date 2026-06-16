import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  enqueue,
  getPending,
  markInflight,
  markSuccess,
  markFailed,
  markConflict,
  countByStatus,
  BATCH_SIZE,
  MAX_ATTEMPTS,
} from '@/repositories/SyncQueueRepository'
import { db } from '@/db/schema'

beforeEach(async () => {
  await db.syncQueue.clear()
})

const baseItem = () => ({
  clientUuid:      'client-uuid-001',
  entityType:      'sale' as const,
  entityUuid:      'entity-uuid-001',
  operation:       'create' as const,
  payload:         { total: 100 },
  clientTimestamp: '2026-01-01T00:00:00Z',
})

describe('enqueue', () => {
  it('agrega item con status pending y attempts=0', async () => {
    const id = await enqueue(baseItem())
    const item = await db.syncQueue.get(id)
    expect(item?.status).toBe('pending')
    expect(item?.attempts).toBe(0)
    expect(item?.lastError).toBeNull()
    expect(item?.nextAttemptAt).toBeTruthy()
  })

  it('retorna id autoincremental', async () => {
    const id1 = await enqueue(baseItem())
    const id2 = await enqueue({ ...baseItem(), entityUuid: 'entity-002' })
    expect(id2).toBeGreaterThan(id1)
  })
})

describe('getPending', () => {
  it('devuelve solo items pending con nextAttemptAt <= ahora', async () => {
    await enqueue(baseItem())
    const results = await getPending()
    expect(results).toHaveLength(1)
    expect(results[0]?.status).toBe('pending')
  })

  it('no devuelve items con nextAttemptAt en el futuro', async () => {
    const future = new Date(Date.now() + 60_000).toISOString()
    await db.syncQueue.add({
      ...baseItem(),
      attempts: 1,
      nextAttemptAt: future,
      lastError: null,
      status: 'pending',
      createdAt: new Date().toISOString(),
    })
    const results = await getPending()
    expect(results).toHaveLength(0)
  })

  it('no devuelve items in_flight, success, failed o conflict', async () => {
    for (const status of ['in_flight', 'success', 'failed', 'conflict'] as const) {
      await db.syncQueue.add({
        ...baseItem(),
        entityUuid: `entity-${status}`,
        attempts: 0,
        nextAttemptAt: new Date().toISOString(),
        lastError: null,
        status,
        createdAt: new Date().toISOString(),
      })
    }
    const results = await getPending()
    expect(results).toHaveLength(0)
  })

  it('respeta el limite BATCH_SIZE', async () => {
    for (let i = 0; i < BATCH_SIZE + 5; i++) {
      await enqueue({ ...baseItem(), entityUuid: `entity-${i}` })
    }
    const results = await getPending()
    expect(results).toHaveLength(BATCH_SIZE)
  })

  it('devuelve items ordenados por id (FIFO)', async () => {
    const id1 = await enqueue({ ...baseItem(), entityUuid: 'e1' })
    const id2 = await enqueue({ ...baseItem(), entityUuid: 'e2' })
    const results = await getPending()
    expect(results[0]?.id).toBe(id1)
    expect(results[1]?.id).toBe(id2)
  })
})

describe('markInflight', () => {
  it('cambia status a in_flight', async () => {
    const id = await enqueue(baseItem())
    await markInflight([id])
    const item = await db.syncQueue.get(id)
    expect(item?.status).toBe('in_flight')
  })

  it('acepta multiples ids', async () => {
    const id1 = await enqueue({ ...baseItem(), entityUuid: 'e1' })
    const id2 = await enqueue({ ...baseItem(), entityUuid: 'e2' })
    await markInflight([id1, id2])
    const item1 = await db.syncQueue.get(id1)
    const item2 = await db.syncQueue.get(id2)
    expect(item1?.status).toBe('in_flight')
    expect(item2?.status).toBe('in_flight')
  })
})

describe('markSuccess', () => {
  it('cambia status a success', async () => {
    const id = await enqueue(baseItem())
    await markSuccess(id)
    const item = await db.syncQueue.get(id)
    expect(item?.status).toBe('success')
  })
})

describe('markFailed', () => {
  it('reagenda con backoff si attempts < MAX_ATTEMPTS', async () => {
    const before = Date.now()
    const id = await enqueue(baseItem())
    await markFailed(id, 'red caida', 0)
    const after = Date.now()
    const item = await db.syncQueue.get(id)
    expect(item?.status).toBe('pending')
    expect(item?.attempts).toBe(1)
    expect(item?.lastError).toBe('red caida')
    // backoff(1) = 2000ms — nextAttemptAt debe estar entre before+2000 y after+2000+50ms tolerancia
    const next = new Date(item!.nextAttemptAt).getTime()
    expect(next).toBeGreaterThanOrEqual(before + 2000)
    expect(next).toBeLessThanOrEqual(after + 2000 + 50)
  })

  it('marca failed definitivo si attempts >= MAX_ATTEMPTS', async () => {
    const id = await enqueue(baseItem())
    await markFailed(id, 'error permanente', MAX_ATTEMPTS - 1)
    const item = await db.syncQueue.get(id)
    expect(item?.status).toBe('failed')
    expect(item?.attempts).toBe(MAX_ATTEMPTS)
  })
})

describe('markConflict', () => {
  it('cambia status a conflict y guarda el error', async () => {
    const id = await enqueue(baseItem())
    await markConflict(id, 'conflicto de folio')
    const item = await db.syncQueue.get(id)
    expect(item?.status).toBe('conflict')
    expect(item?.lastError).toBe('conflicto de folio')
  })
})

describe('countByStatus', () => {
  it('cuenta correctamente por status', async () => {
    await enqueue({ ...baseItem(), entityUuid: 'e1' })
    await enqueue({ ...baseItem(), entityUuid: 'e2' })
    const id3 = await enqueue({ ...baseItem(), entityUuid: 'e3' })
    await markSuccess(id3)
    expect(await countByStatus('pending')).toBe(2)
    expect(await countByStatus('success')).toBe(1)
    expect(await countByStatus('failed')).toBe(0)
  })
})
