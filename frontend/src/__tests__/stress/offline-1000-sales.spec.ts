/**
 * Prueba de estres (doc maestro 87.2): 1000 ventas offline.
 *
 * Valida el camino offline-first a escala:
 *  - 1000 folios consecutivos sin colision ni huecos (RN 40.2.2).
 *  - 1000 items encolados sin perdida ni duplicado.
 *  - Drenaje completo de la cola en lotes FIFO (BATCH_SIZE).
 *  - Rendimiento dentro de un presupuesto razonable.
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { db } from '@/db/schema'
import { nextFolio, DEFAULT_SERIES } from '@/lib/FolioGenerator'
import {
  enqueue,
  getPending,
  markSuccess,
  countByStatus,
  BATCH_SIZE,
} from '@/repositories/SyncQueueRepository'

const TOTAL = 1000
const CASH_REGISTER = 'caja-stress-uuid'
const DEVICE = 'device-stress-uuid'

beforeEach(async () => {
  await db.syncQueue.clear()
  await db.folioRanges.clear()
  await db.sales.clear()
  await db.folioRanges.put({
    id: `${CASH_REGISTER}:${DEFAULT_SERIES}:${DEVICE}`,
    cashRegisterUuid: CASH_REGISTER,
    series: DEFAULT_SERIES,
    deviceId: DEVICE,
    rangeStart: 1,
    rangeEnd: TOTAL,
    nextValue: 1,
    syncedAt: new Date().toISOString(),
  })
})

describe('Stress 1000 ventas offline (87.2)', () => {
  it('genera 1000 folios consecutivos unicos sin colision ni huecos', async () => {
    const folios: string[] = []
    for (let i = 0; i < TOTAL; i++) {
      folios.push(await nextFolio(CASH_REGISTER, DEFAULT_SERIES))
    }
    expect(new Set(folios).size).toBe(TOTAL)
    expect(folios[0]).toBe('A000001')
    expect(folios[TOTAL - 1]).toBe('A001000')
    for (let i = 1; i < TOTAL; i++) {
      const prev = Number(folios[i - 1]!.slice(1))
      const curr = Number(folios[i]!.slice(1))
      expect(curr - prev).toBe(1)
    }
  })

  it('encola 1000 ventas sin perdida y todas quedan pending', async () => {
    for (let i = 0; i < TOTAL; i++) {
      const folio = await nextFolio(CASH_REGISTER, DEFAULT_SERIES)
      await enqueue({
        clientUuid: `client-${i}`,
        entityType: 'sale',
        entityUuid: `sale-${i}`,
        operation: 'create',
        payload: { folio, total: 100 + i },
        clientTimestamp: new Date().toISOString(),
      })
    }
    expect(await countByStatus('pending')).toBe(TOTAL)
    expect(await db.syncQueue.count()).toBe(TOTAL)
  })

  it('drena 1000 items en lotes FIFO de BATCH_SIZE sin saltar ninguno', async () => {
    const enqueuedOrder: string[] = []
    for (let i = 0; i < TOTAL; i++) {
      enqueuedOrder.push(`sale-${i}`)
      await enqueue({
        clientUuid: `client-${i}`,
        entityType: 'sale',
        entityUuid: `sale-${i}`,
        operation: 'create',
        payload: { total: 100 + i },
        clientTimestamp: new Date().toISOString(),
      })
    }

    const drainedOrder: string[] = []
    let batches = 0
    for (;;) {
      const batch = await getPending(BATCH_SIZE)
      if (batch.length === 0) break
      batches++
      expect(batch.length).toBeLessThanOrEqual(BATCH_SIZE)
      for (const item of batch) {
        drainedOrder.push(item.entityUuid)
        await markSuccess(item.id!)
      }
    }

    expect(drainedOrder.length).toBe(TOTAL)
    expect(drainedOrder).toEqual(enqueuedOrder)
    expect(batches).toBe(Math.ceil(TOTAL / BATCH_SIZE))
    expect(await countByStatus('pending')).toBe(0)
    expect(await countByStatus('success')).toBe(TOTAL)
  })

  it('completa el ciclo folio+encolado de 1000 ventas en presupuesto de tiempo', async () => {
    const start = performance.now()
    for (let i = 0; i < TOTAL; i++) {
      const folio = await nextFolio(CASH_REGISTER, DEFAULT_SERIES)
      await enqueue({
        clientUuid: `client-${i}`,
        entityType: 'sale',
        entityUuid: `sale-${i}`,
        operation: 'create',
        payload: { folio, total: 100 + i },
        clientTimestamp: new Date().toISOString(),
      })
    }
    const elapsed = performance.now() - start
    expect(elapsed).toBeLessThan(15000)
    expect(await db.syncQueue.count()).toBe(TOTAL)
  })
})
