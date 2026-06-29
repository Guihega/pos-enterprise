/**
 * IntegrityService — deteccion y recuperacion ante IndexedDB corrupta.
 *
 * Doc maestro sec. 42.3 (IndexedDB corrupta) y pruebas obligatorias 87.2
 * ("Recovery de IndexedDB corrupta").
 *
 * Deteccion (42.3):
 *   - Error en lecturas/escrituras Dexie  -> implementado aqui (checkIntegrity).
 *   - Hash de integridad sobre `device_metadata` que no coincide -> DEUDA:
 *     la tabla device_metadata no existe en el schema actual. Crear esa
 *     tabla + hash seria infra sin consumidor todavia; se activa cuando se
 *     implemente el registro de dispositivo (POST /sync/registration). Hoy
 *     la senal primaria de corrupcion es el fallo de I/O de Dexie.
 *
 * Recuperacion (42.3):
 *   - exportPending(): serializa los datos no sincronizados (sync_queue
 *     pendientes + sales pendientes) a un objeto JSON para que el cajero los
 *     exporte/envie a soporte ANTES de borrar.
 *   - restore(): borra todas las tablas locales. El repoblado (snapshot
 *     inicial) lo dispara el caller (SnapshotService.run); IntegrityService
 *     no se acopla a el para mantener responsabilidad unica.
 *
 * La UI (modal "Datos locales corruptos", botones Exportar/Restaurar) es
 * capa de presentacion y consume estos metodos; no vive aqui.
 */

import { db, type SyncQueueItem, type SaleLocal } from '@/db/schema'

// ---------------------------------------------------------------------------
// Resultado del chequeo de integridad
// ---------------------------------------------------------------------------

export interface IntegrityCheck {
  /** true si IndexedDB respondio a lectura+escritura de prueba. */
  ok: boolean
  /** Mensaje del error si ok === false. */
  error?: string
}

// ---------------------------------------------------------------------------
// Export de datos pendientes (42.3 paso 2)
// ---------------------------------------------------------------------------

export interface PendingExport {
  /** Version del formato de export, para futura compatibilidad de import. */
  version: number
  /** ISO timestamp del momento de exportacion. */
  exportedAt: string
  /** Items de la cola de sync que no se sincronizaron. */
  syncQueue: SyncQueueItem[]
  /** Ventas creadas localmente aun no sincronizadas. */
  sales: SaleLocal[]
}

const EXPORT_FORMAT_VERSION = 1

// Clave de prueba para el chequeo de I/O. Se escribe y borra; no persiste.
const PROBE_KEY = '__integrity_probe__'

// ---------------------------------------------------------------------------
// IntegrityService
// ---------------------------------------------------------------------------

export class IntegrityService {
  /**
   * Verifica que IndexedDB responde haciendo una escritura + lectura +
   * borrado de prueba sobre la tabla settings. Si Dexie lanza, la base se
   * considera corrupta o inaccesible (42.3 deteccion por error de I/O).
   */
  async checkIntegrity(): Promise<IntegrityCheck> {
    try {
      const probeValue = Date.now().toString()
      await db.settings.put({
        key:       PROBE_KEY,
        value:     probeValue,
        updatedAt: new Date().toISOString(),
      })
      const read = await db.settings.get(PROBE_KEY)
      await db.settings.delete(PROBE_KEY)

      if (!read || read.value !== probeValue) {
        return { ok: false, error: 'La lectura de prueba no coincide con la escritura' }
      }
      return { ok: true }
    } catch (err) {
      const error = err instanceof Error ? err.message : 'error de I/O en IndexedDB'
      return { ok: false, error }
    }
  }

  /**
   * Serializa los datos pendientes de sincronizar a un objeto JSON-able.
   * Pensado para descargarse/enviarse a soporte ANTES de restaurar (42.3).
   * No borra nada.
   */
  async exportPending(): Promise<PendingExport> {
    const syncQueue = await db.syncQueue
      .where('status').equals('pending')
      .toArray()

    const sales = await db.sales
      .filter((s) => s.syncStatus === 'pending')
      .toArray()

    return {
      version:    EXPORT_FORMAT_VERSION,
      exportedAt: new Date().toISOString(),
      syncQueue,
      sales,
    }
  }

  /**
   * Borra todas las tablas locales para recuperar de un estado corrupto
   * (42.3 paso 3). El repoblado (snapshot inicial) NO se hace aqui: el
   * caller debe invocar SnapshotService.run() despues. Devuelve el conteo
   * de tablas vaciadas para verificacion.
   */
  async restore(): Promise<{ clearedTables: number }> {
    const tables = db.tables
    await db.transaction('rw', tables, async () => {
      await Promise.all(tables.map((t) => t.clear()))
    })
    return { clearedTables: tables.length }
  }
}
