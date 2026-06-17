/**
 * ConflictResolver — resolucion programatica de conflictos de sync.
 *
 * Doc maestro sec. 39.4 (resolucion programatica) y 39.2 (estrategias
 * por tipo de entidad).
 *
 * Despacha por entityType. Para 'sale' aplica las reglas de 39.4:
 *   - STOCK_NEGATIVE   -> notifica admin, resuelve auto con use_client
 *   - PRICE_MISMATCH   -> log auditoria, resuelve auto con use_client
 *   - CASH_SESSION_CLOSED -> manual, requiere rol manager
 *   - otros            -> manual
 *
 * Las ventas son historicos inmutables (39.2): cuando el servidor las
 * acepta, el "conflicto" suele ser una alerta auditada, no un bloqueo.
 *
 * notifyAdmin / auditLog son hooks inyectables. Mientras no exista el
 * logger estructurado (deuda tecnica #22) se pueden dejar sin definir;
 * el resolver sigue funcionando y marca la resolucion correctamente.
 */

import {
  markResolved,
  requireManual,
  getByUuid,
} from '@/repositories/ConflictRepository'
import type {
  ConflictLocal,
  ConflictReason,
  ConflictResolutionKind,
  SyncEntityType,
} from '@/db/schema'

// ---------------------------------------------------------------------------
// Resultado de una resolucion
// ---------------------------------------------------------------------------

export interface Resolution {
  resolution:   ConflictResolutionKind
  /** true si se resolvio sin intervencion humana. */
  auto:         boolean
  /** Rol minimo requerido si la resolucion es manual. */
  requireRole?: string | null
}

// ---------------------------------------------------------------------------
// Hooks inyectables (deuda tecnica #22: logging estructurado)
// ---------------------------------------------------------------------------

export interface ConflictResolverHooks {
  /** Se invoca cuando un conflicto requiere alertar a un admin. */
  notifyAdmin?: (conflict: ConflictLocal) => void | Promise<void>
  /** Se invoca para registrar un conflicto en auditoria. */
  auditLog?:    (conflict: ConflictLocal) => void | Promise<void>
}

// ---------------------------------------------------------------------------
// ConflictResolver
// ---------------------------------------------------------------------------

export class ConflictResolver {
  private hooks: ConflictResolverHooks

  constructor(hooks: ConflictResolverHooks = {}) {
    this.hooks = hooks
  }

  /**
   * Resuelve un conflicto ya persistido (por uuid) y actualiza su estado
   * en el repositorio. Retorna la Resolution aplicada.
   */
  async resolve(conflictUuid: string): Promise<Resolution> {
    const conflict = await getByUuid(conflictUuid)
    if (!conflict) {
      throw new Error(`Conflicto no encontrado: ${conflictUuid}`)
    }

    const resolution = await this.decide(conflict)

    if (resolution.auto) {
      await markResolved(conflict.uuid, resolution.resolution, true)
    } else {
      await requireManual(conflict.uuid, resolution.requireRole ?? null)
    }

    return resolution
  }

  // -------------------------------------------------------------------------
  // Despacho por tipo de entidad (sec. 39.4)
  // -------------------------------------------------------------------------

  private async decide(conflict: ConflictLocal): Promise<Resolution> {
    const entityType: SyncEntityType = conflict.entityType
    switch (entityType) {
      case 'sale':
        return this.resolveSale(conflict)
      default:
        // product / customer / otros: aun no implementados en Iteracion 2.
        // Por seguridad, requieren resolucion manual.
        return { resolution: 'manual', auto: false, requireRole: null }
    }
  }

  // -------------------------------------------------------------------------
  // Ventas (sec. 39.4 resolveSale)
  // -------------------------------------------------------------------------

  private async resolveSale(conflict: ConflictLocal): Promise<Resolution> {
    const reason: ConflictReason = conflict.reason

    switch (reason) {
      case 'STOCK_NEGATIVE':
        // Acepta venta, permite stock negativo, alerta admin (39.1)
        await this.hooks.notifyAdmin?.(conflict)
        return { resolution: 'use_client', auto: true }

      case 'PRICE_MISMATCH':
        // Acepta precio congelado del cliente, registra para auditoria (39.1)
        await this.hooks.auditLog?.(conflict)
        return { resolution: 'use_client', auto: true }

      case 'IDEMPOTENT':
        // El servidor retorna exito con la venta existente: nada que hacer.
        return { resolution: 'use_server', auto: true }

      case 'PRODUCT_DELETED':
        // Acepta venta usando snapshot guardado en sale_item (39.1)
        await this.hooks.auditLog?.(conflict)
        return { resolution: 'use_client', auto: true }

      case 'CASH_SESSION_CLOSED':
        // Bloqueo: requiere intervencion de gerente (39.1, 39.4)
        return { resolution: 'manual', auto: false, requireRole: 'manager' }

      default:
        return { resolution: 'manual', auto: false, requireRole: null }
    }
  }
}
