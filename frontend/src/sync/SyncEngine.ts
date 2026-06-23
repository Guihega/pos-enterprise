/**
 * SyncEngine — orquestador del modulo de sincronizacion.
 *
 * Doc maestro sec. 38 (Cola de Sincronizacion), arquitectura sec. 6543.
 *
 * Coordina PushQueue + PullStream + ConflictResolver:
 *   - pushOnce(): drena la cola de push una vez (sec. 38.3).
 *   - pullOnce(): descarga cambios del servidor una vez (sec. 38.5).
 *   - syncOnce(): push + pull en un ciclo (usado por BackgroundSync).
 *
 * El scheduling (cada 5 min, on-connect) es responsabilidad de
 * BackgroundSync.ts; SyncEngine ejecuta el ciclo cuando se lo piden.
 *
 * Inferencia de ConflictReason: PushQueue entrega el mensaje crudo del
 * servidor; SyncEngine lo parsea buscando keywords conocidos (sec. 39.1).
 * Si no reconoce ningun reason, usa 'UNKNOWN'.
 */

import { PushQueue } from '@/sync/PushQueue'
import { PullStream } from '@/sync/PullStream'
import { ConflictResolver, type ConflictResolverHooks } from '@/sync/ConflictResolver'
import { store as storeConflict } from '@/repositories/ConflictRepository'
import type { DrainResult, PushEvent, PushQueueOptions } from './PushQueue'
import type { PullResult, PullEvent } from './PullStream'
import type { ConflictReason, SyncEntityType } from '@/db/schema'

// ---------------------------------------------------------------------------
// Tipos publicos
// ---------------------------------------------------------------------------

export type SyncEvent         = PushEvent | PullEvent
export type SyncEventListener = (event: SyncEvent) => void

export interface SyncEngineOptions {
  tenantSlug:     string
  apiBase?:       string
  authToken?:      string
  signal?:        AbortSignal
  onEvent?:       SyncEventListener
  resolverHooks?: ConflictResolverHooks
}

export interface SyncResult {
  push: DrainResult
  pull: PullResult
}

// ---------------------------------------------------------------------------
// Inferencia de reason desde mensaje del servidor (sec. 39.1)
// PushQueue NO infiere el reason; esa responsabilidad es del orquestador.
// ---------------------------------------------------------------------------

const KNOWN_REASONS: ConflictReason[] = [
  'IDEMPOTENT',
  'STOCK_NEGATIVE',
  'PRICE_MISMATCH',
  'PRODUCT_DELETED',
  'CASH_SESSION_CLOSED',
  'STALE_VERSION',
  'FOLIO_DUPLICATE',
  'TENANT_SUSPENDED',
]

export function inferReason(message: string | undefined): ConflictReason {
  if (!message) return 'UNKNOWN'
  const upper = message.toUpperCase()
  for (const r of KNOWN_REASONS) {
    if (upper.includes(r)) return r
  }
  return 'UNKNOWN'
}

// ---------------------------------------------------------------------------
// SyncEngine
// ---------------------------------------------------------------------------

export class SyncEngine {
  private pushQueue: PushQueue
  private pullStream: PullStream
  private resolver:   ConflictResolver

  constructor(opts: SyncEngineOptions) {
    this.resolver = new ConflictResolver(opts.resolverHooks ?? {})

    const pushOpts: PushQueueOptions = {
      tenantSlug: opts.tenantSlug,
      apiBase:    opts.apiBase,
      authToken: opts.authToken,
      signal:     opts.signal,
      onEvent:    opts.onEvent,
      onConflict: async (ctx) => {
        const reason       = inferReason(ctx.message)
        const conflictUuid = crypto.randomUUID()
        await storeConflict({
          uuid:          conflictUuid,
          entityType:    ctx.entityType as SyncEntityType,
          entityUuid:    ctx.entityUuid,
          clientUuid:    ctx.clientUuid,
          reason,
          clientPayload: ctx.clientPayload,
          serverData:    ctx.serverData,
        })
        await this.resolver.resolve(conflictUuid)
      },
    }

    this.pushQueue = new PushQueue(pushOpts)
    this.pullStream = new PullStream({
      tenantSlug: opts.tenantSlug,
      apiBase:    opts.apiBase,
      signal:     opts.signal,
      onEvent:    opts.onEvent,
    })
  }

  /** Drena la cola de push una vez (sec. 38.3). */
  async pushOnce(): Promise<DrainResult> {
    return this.pushQueue.drainOnce()
  }

  /** Descarga cambios del servidor una vez (sec. 38.5). */
  async pullOnce(): Promise<PullResult> {
    return this.pullStream.pullOnce()
  }

  /**
   * Ciclo completo: push luego pull.
   * Siempre ejecuta ambos; BackgroundSync decide cuando llamar syncOnce.
   */
  async syncOnce(): Promise<SyncResult> {
    const push = await this.pushQueue.drainOnce()
    const pull = await this.pullStream.pullOnce()
    return { push, pull }
  }
}
