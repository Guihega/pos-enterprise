/**
 * SyncQueueRepository — acceso a la tabla sync_queue en Dexie.
 *
 * Doc maestro sec. 38.3:
 *   - MAX_PARALLEL = 1 (un batch a la vez)
 *   - BATCH_SIZE   = 50
 *   - MAX_ATTEMPTS = 10
 *   - BACKOFF: 1s, 2s, 4s, 8s, 16s, 32s, 1m, 5m, 15m, 30m, 1h
 */
import { db } from '@/db/schema'
import type { SyncQueueItem, SyncStatus } from '@/db/schema'

export const BATCH_SIZE   = 50
export const MAX_ATTEMPTS = 10

/** Backoff en ms por intento (0-indexed). Doc maestro 38.3. */
const BACKOFF_MS = [
  1_000,
  2_000,
  4_000,
  8_000,
  16_000,
  32_000,
  60_000,
  300_000,
  900_000,
  1_800_000,
]

function backoffMs(attempts: number): number {
  const idx = Math.min(attempts, BACKOFF_MS.length - 1)
  return BACKOFF_MS[idx] ?? BACKOFF_MS[BACKOFF_MS.length - 1]!
}

/** Encola una operacion pendiente de sincronizar. */
export async function enqueue(
  item: Omit<SyncQueueItem, 'id' | 'attempts' | 'nextAttemptAt' | 'lastError' | 'status' | 'createdAt'>,
): Promise<number> {
  const now = new Date().toISOString()
  return db.syncQueue.add({
    ...item,
    attempts:     0,
    nextAttemptAt: now,
    lastError:    null,
    status:       'pending',
    createdAt:    now,
  })
}

/**
 * Devuelve hasta BATCH_SIZE items listos para enviar:
 * status=pending y nextAttemptAt <= ahora.
 * Ordenados por id (FIFO — garantia de orden por entidad, sec. 38.4).
 */
export async function getPending(limit = BATCH_SIZE): Promise<SyncQueueItem[]> {
  const now = new Date().toISOString()
  return db.syncQueue
    .where('status')
    .equals('pending')
    .filter(item => item.nextAttemptAt <= now)
    .limit(limit)
    .sortBy('id')
}

/** Marca los items indicados como in_flight. */
export async function markInflight(ids: number[]): Promise<void> {
  await db.transaction('rw', db.syncQueue, async () => {
    for (const id of ids) {
      await db.syncQueue.update(id, { status: 'in_flight' as SyncStatus })
    }
  })
}

/** Marca un item como completado con exito. */
export async function markSuccess(id: number): Promise<void> {
  await db.syncQueue.update(id, { status: 'success' as SyncStatus })
}

/**
 * Marca un item como fallido y lo reagenda con backoff exponencial.
 * Si supera MAX_ATTEMPTS, lo marca como 'failed' definitivo.
 */
export async function markFailed(id: number, error: string, currentAttempts: number): Promise<void> {
  const attempts = currentAttempts + 1
  if (attempts >= MAX_ATTEMPTS) {
    await db.syncQueue.update(id, {
      status:    'failed' as SyncStatus,
      attempts,
      lastError: error,
    })
    return
  }
  const nextAttemptAt = new Date(Date.now() + backoffMs(attempts)).toISOString()
  await db.syncQueue.update(id, {
    status:        'pending' as SyncStatus,
    attempts,
    lastError:     error,
    nextAttemptAt,
  })
}

/** Marca un item como conflicto (requiere resolucion manual). */
export async function markConflict(id: number, error: string): Promise<void> {
  await db.syncQueue.update(id, { status: 'conflict' as SyncStatus, lastError: error })
}

/** Cuenta items por status (util para UI de indicador de sync). */
export async function countByStatus(status: SyncStatus): Promise<number> {
  return db.syncQueue.filter(item => item.status === status).count()
}
