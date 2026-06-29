/**
 * PullStream — consumidor de GET /api/v1/sync/changes.
 *
 * Doc maestro sec. 38.5: descarga cambios del servidor (created/updated/deleted)
 * para products, taxes y customers, y los aplica en IndexedDB.
 *
 * Estrategia LWW para 'updated' (strings ISO UTC son lexicograficamente comparables):
 *   - Products / Customers: remote.updated_at >= local.updatedAt => servidor gana.
 *                           remote.updated_at <  local.updatedAt => local gana (skip).
 *   - Taxes: TaxLocal sin updatedAt => servidor siempre gana.
 *
 * NO hace scheduling ni polling (eso es BackgroundSync.ts).
 * El orquestador SyncEngine.ts decide cuando llamar pullOnce().
 */

import type { Product, Tax, Customer } from '@/lib/api/generated'
import * as ProductRepo from '@/repositories/ProductRepository'
import * as TaxRepo from '@/repositories/TaxRepository'
import * as CustomerRepo from '@/repositories/CustomerRepository'
import { db, SETTING_LAST_PULL } from '@/db/schema'

// ---------------------------------------------------------------------------
// Tipos de respuesta de /api/v1/sync/changes
// ---------------------------------------------------------------------------

interface DeletedItem {
  uuid: string
}

interface EntityChanges<T> {
  created: T[]
  updated: T[]
  deleted: DeletedItem[]
}

interface SyncChangesData {
  products?:  EntityChanges<Product>
  taxes?:     EntityChanges<Tax>
  customers?: EntityChanges<Customer>
}

interface SyncChangesMeta {
  snapshot_timestamp: string
  has_more:           boolean
  next_cursor:        string | null
}

interface SyncChangesResponse {
  data: SyncChangesData
  meta: SyncChangesMeta
}

// ---------------------------------------------------------------------------
// Eventos
// ---------------------------------------------------------------------------

export type PullEvent =
  | { type: 'sync.pull.start' }
  | { type: 'sync.pull.done';  snapshotTimestamp: string }
  | { type: 'sync.pull.error'; error: string }

export type PullEventListener = (event: PullEvent) => void

// ---------------------------------------------------------------------------
// Conteo por entidad
// ---------------------------------------------------------------------------

export interface EntityPullCounts {
  created: number
  updated: number
  deleted: number
  /** Items de 'updated' donde local era mas reciente (LWW: local gano). */
  skipped: number
}

const ZERO_COUNTS: EntityPullCounts = {
  created: 0,
  updated: 0,
  deleted: 0,
  skipped: 0,
}

// ---------------------------------------------------------------------------
// Resultado del pull
// ---------------------------------------------------------------------------

export interface PullResult {
  products:          EntityPullCounts
  taxes:             EntityPullCounts
  customers:         EntityPullCounts
  snapshotTimestamp: string
  networkError:      boolean
}

const EMPTY_RESULT: PullResult = {
  products:          { ...ZERO_COUNTS },
  taxes:             { ...ZERO_COUNTS },
  customers:         { ...ZERO_COUNTS },
  snapshotTimestamp: '',
  networkError:      false,
}

// ---------------------------------------------------------------------------
// Opciones
// ---------------------------------------------------------------------------

export interface PullStreamOptions {
  /** Slug del tenant activo — se envia como X-Tenant. */
  tenantSlug: string
  /** URL base de la API (default: ''). */
  apiBase?:  string
  authToken?: string
  /** Escuchar eventos del pull. */
  onEvent?:  PullEventListener
  /** Senal de aborto para cancelar el fetch en curso. */
  signal?:   AbortSignal
}

// Entidades solicitadas en cada pull (simetria con backend sec. 38.5)
const PULL_ENTITIES = ['products', 'taxes', 'customers'] as const

// ---------------------------------------------------------------------------
// PullStream
// ---------------------------------------------------------------------------

export class PullStream {
  private tenantSlug: string
  private apiBase:    string
  private authToken:   string
  private onEvent?:   PullEventListener
  private signal?:    AbortSignal

  constructor(opts: PullStreamOptions) {
    this.tenantSlug = opts.tenantSlug
    this.apiBase    = opts.apiBase ?? ''
    this.authToken  = opts.authToken ?? ''
    this.onEvent    = opts.onEvent
    this.signal     = opts.signal
  }

  /**
   * Descarga y aplica un ciclo de cambios desde el servidor.
   * Lee SETTING_LAST_PULL de IndexedDB como parametro since.
   * Actualiza SETTING_LAST_PULL a meta.snapshot_timestamp tras exito.
   */
  async pullOnce(): Promise<PullResult> {
    this.emit({ type: 'sync.pull.start' })

    const since = await this.getLastPull()
    const url   = this.buildUrl(since)

    let response: SyncChangesResponse
    try {
      response = await this.fetchChanges(url)
    } catch (err) {
      const error = err instanceof Error ? err.message : 'network error'
      this.emit({ type: 'sync.pull.error', error })
      return { ...EMPTY_RESULT, networkError: true }
    }

    const products  = await this.applyProducts(response.data.products)
    const taxes     = await this.applyTaxes(response.data.taxes)
    const customers = await this.applyCustomers(response.data.customers)

    await db.settings.put({
      key:       SETTING_LAST_PULL,
      value:     response.meta.snapshot_timestamp,
      updatedAt: new Date().toISOString(),
    })

    const result: PullResult = {
      products,
      taxes,
      customers,
      snapshotTimestamp: response.meta.snapshot_timestamp,
      networkError:      false,
    }

    this.emit({ type: 'sync.pull.done', snapshotTimestamp: response.meta.snapshot_timestamp })
    return result
  }

  // -------------------------------------------------------------------------
  // Aplicar cambios por entidad
  // -------------------------------------------------------------------------

  private async applyProducts(
    changes: EntityChanges<Product> | undefined,
  ): Promise<EntityPullCounts> {
    if (!changes) return { ...ZERO_COUNTS }

    await ProductRepo.upsertMany(changes.created)

    const { applied: updated, skipped } =
      await this.applyProductsWithLWW(changes.updated)

    await ProductRepo.deleteMany(changes.deleted.map((d) => d.uuid))

    return {
      created: changes.created.length,
      updated,
      deleted: changes.deleted.length,
      skipped,
    }
  }

  /**
   * LWW products: aplica si remote.updated_at >= local.updatedAt.
   * Strings ISO UTC son lexicograficamente comparables.
   */
  private async applyProductsWithLWW(
    items: Product[],
  ): Promise<{ applied: number; skipped: number }> {
    if (items.length === 0) return { applied: 0, skipped: 0 }

    const uuids  = items.map((i) => i.uuid)
    const locals = await db.products.bulkGet(uuids)

    const toApply: Product[] = []
    let skipped = 0

    for (let i = 0; i < items.length; i++) {
      const remote = items[i]!
      const local  = locals[i]
      if (local && local.updatedAt > remote.updated_at) {
        skipped++
      } else {
        toApply.push(remote)
      }
    }

    if (toApply.length > 0) await ProductRepo.upsertMany(toApply)
    return { applied: toApply.length, skipped }
  }

  private async applyTaxes(
    changes: EntityChanges<Tax> | undefined,
  ): Promise<EntityPullCounts> {
    if (!changes) return { ...ZERO_COUNTS }

    // TaxLocal sin updatedAt: servidor siempre gana en created y updated.
    await TaxRepo.upsertMany([...changes.created, ...changes.updated])
    await TaxRepo.deleteMany(changes.deleted.map((d) => d.uuid))

    return {
      created: changes.created.length,
      updated: changes.updated.length,
      deleted: changes.deleted.length,
      skipped: 0,
    }
  }

  private async applyCustomers(
    changes: EntityChanges<Customer> | undefined,
  ): Promise<EntityPullCounts> {
    if (!changes) return { ...ZERO_COUNTS }

    await CustomerRepo.upsertMany(changes.created)

    const { applied: updated, skipped } =
      await this.applyCustomersWithLWW(changes.updated)

    await CustomerRepo.deleteMany(changes.deleted.map((d) => d.uuid))

    return {
      created: changes.created.length,
      updated,
      deleted: changes.deleted.length,
      skipped,
    }
  }

  /**
   * LWW customers: aplica si remote.updated_at >= local.updatedAt.
   */
  private async applyCustomersWithLWW(
    items: Customer[],
  ): Promise<{ applied: number; skipped: number }> {
    if (items.length === 0) return { applied: 0, skipped: 0 }

    const uuids  = items.map((i) => i.uuid)
    const locals = await db.customers.bulkGet(uuids)

    const toApply: Customer[] = []
    let skipped = 0

    for (let i = 0; i < items.length; i++) {
      const remote = items[i]!
      const local  = locals[i]
      if (local && local.updatedAt > remote.updated_at) {
        skipped++
      } else {
        toApply.push(remote)
      }
    }

    if (toApply.length > 0) await CustomerRepo.upsertMany(toApply)
    return { applied: toApply.length, skipped }
  }

  // -------------------------------------------------------------------------
  // HTTP
  // -------------------------------------------------------------------------

  private async getLastPull(): Promise<string | null> {
    try {
      const setting = await db.settings.get(SETTING_LAST_PULL)
      return setting ? (setting.value as string) : null
    } catch {
      return null
    }
  }

  private buildUrl(since: string | null): string {
    const entities = PULL_ENTITIES.join(',')
    const base     = `${this.apiBase}/sync/changes?entities=${entities}`
    return since ? `${base}&since=${encodeURIComponent(since)}` : base
  }

  private async fetchChanges(url: string): Promise<SyncChangesResponse> {
    const res = await fetch(url, {
      method:      'GET',
      headers:      { 'X-Tenant': this.tenantSlug, ...(this.authToken ? { Authorization: `Bearer ${this.authToken}` } : {}) },
      signal:      this.signal ?? null,
    })

    if (!res.ok) {
      const payload = await res.json().catch(() => ({}))
      throw new Error(
        (payload as { message?: string }).message ?? `HTTP ${res.status}`,
      )
    }

    return res.json() as Promise<SyncChangesResponse>
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private emit(event: PullEvent): void {
    this.onEvent?.(event)
  }
}
