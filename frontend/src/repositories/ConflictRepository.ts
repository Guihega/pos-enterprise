/**
 * ConflictRepository — acceso a la tabla conflicts en Dexie.
 *
 * Doc maestro sec. 39.3 (cola de conflictos para humanos) y 39.4.
 *
 * Persiste los conflictos detectados durante el push para que:
 *   - los auto-resueltos queden auditados (resolvedAt + resolution).
 *   - los que requieren intervencion humana aparezcan en la UI dedicada
 *     (getUnresolved), filtrables por rol.
 */

import { db } from '@/db/schema'
import type {
  ConflictLocal,
  ConflictReason,
  ConflictResolutionKind,
  SyncEntityType,
} from '@/db/schema'

/** Datos minimos para registrar un conflicto nuevo. */
export interface NewConflictInput {
  uuid:          string
  entityType:    SyncEntityType
  entityUuid:    string
  clientUuid:    string
  reason:        ConflictReason
  clientPayload: unknown
  serverData?:   unknown
}

/**
 * Registra un conflicto nuevo en estado pendiente (sin resolver).
 * Idempotente por uuid: si ya existe, no lo duplica.
 */
export async function store(input: NewConflictInput): Promise<string> {
  const existing = await db.conflicts.get(input.uuid)
  if (existing) return existing.uuid

  const conflict: ConflictLocal = {
    uuid:          input.uuid,
    entityType:    input.entityType,
    entityUuid:    input.entityUuid,
    clientUuid:    input.clientUuid,
    reason:        input.reason,
    clientPayload: input.clientPayload,
    serverData:    input.serverData ?? null,
    resolution:    null,
    auto:          false,
    requireRole:   null,
    detectedAt:    new Date().toISOString(),
    resolvedAt:    null,
  }
  await db.conflicts.add(conflict)
  return conflict.uuid
}

/**
 * Marca un conflicto como resuelto.
 * @param auto true si fue resolucion automatica, false si manual.
 */
export async function markResolved(
  uuid:       string,
  resolution: ConflictResolutionKind,
  auto:       boolean,
): Promise<void> {
  await db.conflicts.update(uuid, {
    resolution,
    auto,
    resolvedAt: new Date().toISOString(),
  })
}

/**
 * Marca un conflicto como pendiente de resolucion manual, anotando el rol
 * minimo requerido (ej: 'manager'). Sec. 39.4 CASH_SESSION_CLOSED.
 */
export async function requireManual(uuid: string, role: string | null = null): Promise<void> {
  await db.conflicts.update(uuid, {
    resolution:  'manual' as ConflictResolutionKind,
    auto:        false,
    requireRole: role,
  })
}

/** Devuelve los conflictos sin resolver (resolvedAt === null), FIFO. */
export async function getUnresolved(): Promise<ConflictLocal[]> {
  return db.conflicts
    .filter(c => c.resolvedAt === null)
    .sortBy('detectedAt')
}

/** Cuenta conflictos sin resolver (para badge de UI). */
export async function countUnresolved(): Promise<number> {
  return db.conflicts.filter(c => c.resolvedAt === null).count()
}

/** Obtiene un conflicto por uuid. */
export async function getByUuid(uuid: string): Promise<ConflictLocal | undefined> {
  return db.conflicts.get(uuid)
}

/** Lista conflictos por entidad (auditoria / historial). */
export async function getByEntity(entityUuid: string): Promise<ConflictLocal[]> {
  return db.conflicts.where('entityUuid').equals(entityUuid).sortBy('detectedAt')
}
