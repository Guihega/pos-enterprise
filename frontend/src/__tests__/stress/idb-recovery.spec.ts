/**
 * Prueba de recovery de IndexedDB corrupta (doc maestro 87.2 + 42.3).
 *
 * Integracion end-to-end del flujo de recuperacion SIN mocks, sobre la
 * BD real (fake-indexeddb): se siembran ventas offline pendientes, se
 * exportan a JSON (paso 2 de 42.3), se restaura borrando todo (paso 3) y
 * se verifica que la base queda limpia y reutilizable.
 *
 * checkIntegrity sano se valida tambien: tras restaurar, el probe de I/O
 * vuelve a pasar.
 */
import { beforeEach, describe, expect, it } from 'vitest'
import { db } from '@/db/schema'
import { IntegrityService } from '@/sync/IntegrityService'

const service = new IntegrityService()

async function seedPendingSales(n: number): Promise<void> {
  for (let i = 0; i < n; i++) {
    await db.sales.put({
      uuid: `sale-${i}`,
      folio: `A${String(i + 1).padStart(6, '0')}`,
      cashRegisterUuid: 'caja-1',
      cashSessionUuid: 'sesion-1',
      customerUuid: null,
      subtotal: 100,
      discountTotal: 0,
      taxTotal: 16,
      total: 116,
      amountPaid: 116,
      change: 0,
      paymentMethod: 'cash',
      status: 'completed',
      createdOffline: true,
      syncStatus: 'pending',
      clientTimestamp: new Date().toISOString(),
      serverTimestamp: null,
      createdAt: new Date().toISOString(),
    })
    await db.syncQueue.add({
      clientUuid: `sale-${i}`,
      entityType: 'sale',
      entityUuid: `sale-${i}`,
      operation: 'create',
      payload: { folio: `A${String(i + 1).padStart(6, '0')}` },
      clientTimestamp: new Date().toISOString(),
      attempts: 0,
      nextAttemptAt: new Date().toISOString(),
      lastError: null,
      status: 'pending',
      createdAt: new Date().toISOString(),
    })
  }
}

beforeEach(async () => {
  await Promise.all(db.tables.map((t) => t.clear()))
})

describe('Recovery IndexedDB corrupta (87.2 / 42.3)', { timeout: 30000 }, () => {
  it('checkIntegrity pasa cuando la BD responde (probe I/O sano)', async () => {
    const result = await service.checkIntegrity()
    expect(result.ok).toBe(true)
  })

  it('exportPending serializa todas las ventas y cola pendientes sin perder ninguna', async () => {
    await seedPendingSales(25)
    const dump = await service.exportPending()
    expect(dump.sales).toHaveLength(25)
    expect(dump.syncQueue).toHaveLength(25)
    expect(dump.version).toBeTruthy()
    expect(dump.exportedAt).toBeTruthy()
    // El JSON es serializable (no estructuras circulares ni clases).
    expect(() => JSON.stringify(dump)).not.toThrow()
    // El folio del primer registro se conserva intacto en el export.
    const folios = dump.sales.map((s) => s.folio).sort()
    expect(folios[0]).toBe('A000001')
    expect(folios[24]).toBe('A000025')
  })

  it('flujo completo 42.3: export -> restore deja la BD vacia y reutilizable', async () => {
    await seedPendingSales(50)
    expect(await db.sales.count()).toBe(50)
    expect(await db.syncQueue.count()).toBe(50)

    // Paso 2: exportar pendientes ANTES de borrar.
    const dump = await service.exportPending()
    expect(dump.sales).toHaveLength(50)
    expect(dump.syncQueue).toHaveLength(50)

    // Paso 3: restaurar (borra todas las tablas).
    const { clearedTables } = await service.restore()
    expect(clearedTables).toBe(db.tables.length)

    // BD vacia tras restore.
    expect(await db.sales.count()).toBe(0)
    expect(await db.syncQueue.count()).toBe(0)
    expect(await db.products.count()).toBe(0)

    // La BD sigue siendo reutilizable: el probe de integridad pasa
    // y se pueden escribir nuevos registros sin error.
    const check = await service.checkIntegrity()
    expect(check.ok).toBe(true)
    await db.sales.put({
      uuid: 'nueva-venta',
      folio: 'A000001',
      cashRegisterUuid: 'caja-1',
      cashSessionUuid: 'sesion-1',
      customerUuid: null,
      subtotal: 10, discountTotal: 0, taxTotal: 1, total: 11,
      amountPaid: 11, change: 0, paymentMethod: 'cash',
      status: 'completed', createdOffline: true, syncStatus: 'pending',
      clientTimestamp: new Date().toISOString(),
      serverTimestamp: null, createdAt: new Date().toISOString(),
    })
    expect(await db.sales.count()).toBe(1)
  })

  it('el JSON exportado puede re-importarse: round-trip preserva los datos', async () => {
    await seedPendingSales(10)
    const dump = await service.exportPending()

    // Simular el viaje a soporte: serializar y volver a parsear.
    const roundTrip = JSON.parse(JSON.stringify(dump)) as typeof dump

    await service.restore()
    expect(await db.sales.count()).toBe(0)

    // Re-importar las ventas del dump a la BD limpia.
    await db.sales.bulkPut(roundTrip.sales)
    await db.syncQueue.bulkAdd(roundTrip.syncQueue)

    expect(await db.sales.count()).toBe(10)
    expect(await db.syncQueue.count()).toBe(10)
    const reimported = await db.sales.get('sale-0')
    expect(reimported?.folio).toBe('A000001')
    expect(reimported?.syncStatus).toBe('pending')
  })
})
