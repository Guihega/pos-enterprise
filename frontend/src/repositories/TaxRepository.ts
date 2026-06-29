/**
 * TaxRepository — escritura de impuestos en IndexedDB.
 *
 * Fase 2 / Iteracion 2. Metodos de escritura granular requeridos por
 * PullStream (sec. 38.5). TaxLocal no tiene updatedAt: el servidor
 * siempre gana (catalogo puro de servidor).
 */
import type { Tax } from '@/lib/api/generated'
import { db, type TaxLocal } from '@/db/schema'

// ---------------------------------------------------------------------------
// Mapeo API -> local
// ---------------------------------------------------------------------------

function toLocal(t: Tax): TaxLocal {
  return {
    uuid:        t.uuid,
    code:        t.code,
    name:        t.name,
    rate:        t.rate,
    isInclusive: t.is_inclusive,
  }
}

// ---------------------------------------------------------------------------
// API publica
// ---------------------------------------------------------------------------

/**
 * Upsert de multiples impuestos en IndexedDB.
 * El servidor siempre gana (sin updatedAt en TaxLocal).
 */
export async function upsertMany(items: Tax[]): Promise<void> {
  if (items.length === 0) return
  await db.taxes.bulkPut(items.map(toLocal))
}

/**
 * Elimina impuestos por uuid (borrado logico del servidor).
 */
export async function deleteMany(uuids: string[]): Promise<void> {
  if (uuids.length === 0) return
  await db.taxes.bulkDelete(uuids)
}
