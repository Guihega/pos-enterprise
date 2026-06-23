/**
 * PushQueue — drenaje (push) de la cola offline hacia el servidor.
 *
 * Doc maestro sec. 38.3 (Push/drenaje) y 38.4 (Orden de operaciones).
 *
 * Responsabilidad UNICA: tomar un lote de items pendientes, enviarlo a
 * POST /api/v1/sync/batch con idempotency key = batch_uuid, y aplicar el
 * resultado a la cola local (success / conflict / failed con backoff).
 *
 * NO hace polling ni scheduling (eso es BackgroundSync.ts) ni resuelve
 * conflictos de negocio (eso es ConflictResolver.ts). El orquestador
 * SyncEngine.ts coordina estas piezas.
 *
 * Parametros canonicos (sec. 38.3):
 *   MAX_PARALLEL = 1   (un batch a la vez — garantia de orden)
 *   BATCH_SIZE   = 50
 *   MAX_ATTEMPTS = 10
 *   BACKOFF: 1s,2s,4s,8s,16s,32s,1m,5m,15m,30m,1h
 */

import {
  getPending,
  markInflight,
  markSuccess,
  markFailed,
  markConflict,
  BATCH_SIZE,
} from '@/repositories/SyncQueueRepository'
import type { SyncQueueItem } from '@/db/schema'

// ---------------------------------------------------------------------------
// Contrato con POST /api/v1/sync/batch
// ---------------------------------------------------------------------------

interface SyncBatchRequestItem {
  client_uuid:      string
  entity_type:      string
  entity_uuid:      string
  operation:        string
  client_timestamp: string
  payload:          Record<string, unknown>
}

interface SyncBatchResultItem {
  client_uuid: string
  status:      'success' | 'conflict' | 'error'
  data?:       Record<string, unknown>
  message?:    string
}

interface SyncBatchResponse {
  batch_uuid: string
  results:    SyncBatchResultItem[]
}

// ---------------------------------------------------------------------------
// Eventos
// ---------------------------------------------------------------------------

export type PushEvent =
  | { type: 'sync.item.success';      clientUuid: string; data?: Record<string, unknown> }
  | { type: 'sync.conflict.detected'; clientUuid: string; message?: string }
  | { type: 'sync.item.failed';       clientUuid: string; message?: string }
  | { type: 'sync.batch.start';       batchUuid: string;  count: number }
  | { type: 'sync.batch.done';        batchUuid: string }
  | { type: 'sync.error';             error: string }

export type PushEventListener = (event: PushEvent) => void

// ---------------------------------------------------------------------------
// Resultado de un drenaje
// ---------------------------------------------------------------------------

export interface DrainResult {
  /** Items enviados en este ciclo. */
  sent:      number
  succeeded: number
  conflicts: number
  failed:    number
  /** true si hubo error de red y no se proceso el batch. */
  networkError: boolean
}

const EMPTY_RESULT: DrainResult = {
  sent: 0, succeeded: 0, conflicts: 0, failed: 0, networkError: false,
}

// ---------------------------------------------------------------------------
// PushQueue
// ---------------------------------------------------------------------------

export interface PushQueueOptions {
  /** Slug del tenant activo — se envia como X-Tenant. */
  tenantSlug: string
  /** Token de autorizacion Sanctum. */
  authToken?: string
  /** URL base de la API (default: ''). */
  apiBase?:   string
  /** Escuchar eventos del push. */
  onEvent?:   PushEventListener
  /** Senal de aborto para cancelar el fetch en curso. */
  signal?:    AbortSignal
  /**
   * Hook invocado cuando un item vuelve 'conflict'. Recibe el contexto
   * crudo del servidor. El orquestador (SyncEngine) lo conecta a
   * ConflictRepository.store + ConflictResolver.resolve. PushQueue NO
   * infiere el reason desde el mensaje: eso es responsabilidad del caller.
   */
  onConflict?: (ctx: ConflictContext) => void | Promise<void>
}

/** Contexto que PushQueue entrega al hook onConflict. */
export interface ConflictContext {
  clientUuid:  string
  entityType:  string
  entityUuid:  string
  /** Mensaje crudo devuelto por el servidor (campo error del backend). */
  message:     string | undefined
  /** Payload original que el cliente intento sincronizar. */
  clientPayload: unknown
  /** Datos que devolvio el servidor, si los hay. */
  serverData:  Record<string, unknown> | undefined
}

export class PushQueue {
  private tenantSlug: string
  private authToken: string
  private apiBase:    string
  private onEvent?:   PushEventListener
  private signal?:    AbortSignal
  private onConflict?: (ctx: ConflictContext) => void | Promise<void>

  constructor(opts: PushQueueOptions) {
    this.tenantSlug = opts.tenantSlug
    this.authToken  = opts.authToken ?? ''
    this.apiBase    = opts.apiBase ?? ''
    this.onEvent    = opts.onEvent
    this.signal     = opts.signal
    this.onConflict  = opts.onConflict
  }

  /**
   * Drena un lote (sec. 38.3). Toma hasta BATCH_SIZE items pendientes,
   * filtra por orden de entidad (38.4), los envia y aplica el resultado.
   *
   * Retorna un DrainResult. No hace loop: el caller decide si reintentar.
   */
  async drainOnce(): Promise<DrainResult> {
    const pending = await getPending(BATCH_SIZE)
    if (pending.length === 0) return { ...EMPTY_RESULT }

    // Sec. 38.4: orden de operaciones por entidad.
    // getPending ya devuelve FIFO global (sortBy id). Para garantizar que
    // una operacion no adelante a su predecesora de la MISMA entidad, solo
    // incluimos en el batch el PRIMER item pendiente de cada entity_uuid.
    // Los siguientes de esa entidad esperan al proximo ciclo, ya con el
    // predecesor resuelto. Esto respeta la garantia 3 de 38.4: si una
    // operacion falla, las posteriores de la misma entidad quedan bloqueadas.
    const batch = this.firstPerEntity(pending)

    const batchUuid = crypto.randomUUID()
    const ids       = batch.map(i => i.id!)

    await markInflight(ids)
    this.emit({ type: 'sync.batch.start', batchUuid, count: batch.length })

    let response: SyncBatchResponse
    try {
      response = await this.postBatch(batchUuid, batch)
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'network error'
      // Error de red: reagendar todos con backoff, no consumir como error de negocio
      for (const item of batch) {
        await markFailed(item.id!, msg, item.attempts)
      }
      this.emit({ type: 'sync.error', error: msg })
      return { ...EMPTY_RESULT, networkError: true }
    }

    const result = await this.applyResults(batch, response)
    this.emit({ type: 'sync.batch.done', batchUuid })
    return result
  }

  // -------------------------------------------------------------------------
  // Orden por entidad (sec. 38.4)
  // -------------------------------------------------------------------------

  /**
   * De una lista FIFO, devuelve solo el primer item pendiente de cada
   * entity_uuid. Garantiza que dentro de un batch no haya dos operaciones
   * de la misma entidad, y que la de menor id (mas antigua) sea la que va.
   */
  private firstPerEntity(items: SyncQueueItem[]): SyncQueueItem[] {
    const seen  = new Set<string>()
    const batch: SyncQueueItem[] = []
    for (const item of items) {
      if (seen.has(item.entityUuid)) continue
      seen.add(item.entityUuid)
      batch.push(item)
    }
    return batch
  }

  // -------------------------------------------------------------------------
  // Aplicar resultados (sec. 38.3)
  // -------------------------------------------------------------------------

  private async applyResults(
    batch:    SyncQueueItem[],
    response: SyncBatchResponse,
  ): Promise<DrainResult> {
    const byClientUuid = new Map<string, SyncBatchResultItem>(
      response.results.map(r => [r.client_uuid, r]),
    )

    let succeeded = 0
    let conflicts = 0
    let failed    = 0

    for (const item of batch) {
      const result = byClientUuid.get(item.clientUuid)

      if (!result) {
        await markFailed(item.id!, 'no result from server', item.attempts)
        this.emit({ type: 'sync.item.failed', clientUuid: item.clientUuid, message: 'no result from server' })
        failed++
        continue
      }

      switch (result.status) {
        case 'success':
          await markSuccess(item.id!)
          this.emit({ type: 'sync.item.success', clientUuid: item.clientUuid, data: result.data })
          succeeded++
          break
        case 'conflict':
          await markConflict(item.id!, result.message ?? 'conflict')
          await this.onConflict?.({
            clientUuid:    item.clientUuid,
            entityType:    item.entityType,
            entityUuid:    item.entityUuid,
            message:       result.message,
            clientPayload: item.payload,
            serverData:    result.data,
          })
          this.emit({ type: 'sync.conflict.detected', clientUuid: item.clientUuid, message: result.message })
          conflicts++
          break
        case 'error':
          await markFailed(item.id!, result.message ?? 'server error', item.attempts)
          this.emit({ type: 'sync.item.failed', clientUuid: item.clientUuid, message: result.message })
          failed++
          break
      }
    }

    return { sent: batch.length, succeeded, conflicts, failed, networkError: false }
  }

  // -------------------------------------------------------------------------
  // HTTP
  // -------------------------------------------------------------------------

  private async postBatch(
    batchUuid: string,
    items:     SyncQueueItem[],
  ): Promise<SyncBatchResponse> {
    const body = {
      batch_uuid: batchUuid,
      items: items.map((item): SyncBatchRequestItem => ({
        client_uuid:      item.clientUuid,
        entity_type:      item.entityType,
        entity_uuid:      item.entityUuid,
        operation:        item.operation,
        client_timestamp: item.clientTimestamp,
        payload:          item.payload as Record<string, unknown>,
      })),
    }

    const res = await fetch(`${this.apiBase}/api/v1/sync/batch`, {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Tenant':     this.tenantSlug,
        ...(this.authToken ? { Authorization: `Bearer ${this.authToken}` } : {}),
      },
      body: JSON.stringify(body),
      signal:      this.signal ?? null,
    })

    if (!res.ok) {
      const payload = await res.json().catch(() => ({}))
      throw new Error((payload as { message?: string }).message ?? `HTTP ${res.status}`)
    }

    return res.json() as Promise<SyncBatchResponse>
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private emit(event: PushEvent): void {
    this.onEvent?.(event)
  }
}
