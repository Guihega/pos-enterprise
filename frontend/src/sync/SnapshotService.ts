/**
 * SnapshotService — repoblado completo de IndexedDB (snapshot inicial).
 *
 * Doc maestro sec. 38.6: al instalar el dispositivo o tras perdida de
 * IndexedDB, el cliente descarga el catalogo completo y repuebla la base
 * local. Orden 38.6: products -> customers -> taxes; al final marca
 * last_full_sync = now.
 *
 * Realidad del proyecto (no el job async aspiracional de 38.6):
 *   - El catalogo es acotado y no hay cola de jobs montada en el backend
 *     (ver TODO deuda-9 en TenantContext: no hay ShouldQueue todavia).
 *   - La paginacion real de los endpoints es offset-based (products tiene
 *     page; taxes y customers traen todo en una pagina con per_page alto).
 *   Por tanto el snapshot se hace client-side sobre los listados REST que
 *   ya existen, sin endpoint server-side dedicado ni job async. Cuando el
 *   catalogo escale a decenas de miles, se migrara al POST /sync/snapshot
 *   con job async + cursor (deuda documentada).
 *
 * NO decide CUANDO repoblar (eso es el arranque, sec. 35.4 paso 5: si
 * last_full_sync > 7 dias o nunca). Solo ejecuta el repoblado cuando se
 * lo piden, y expone needsSnapshot() para que el caller decida.
 */

import type { Tax, Customer } from '@/lib/api/generated'
import { listTaxes, listCustomers } from '@/lib/api/generated'
import {
  fullSync as fullSyncProducts,
  hasData as hasProductData,
  getLastFullSync,
} from '@/repositories/ProductRepository'
import * as TaxRepo from '@/repositories/TaxRepository'
import * as CustomerRepo from '@/repositories/CustomerRepository'
import { db, SETTING_LAST_PULL } from '@/db/schema'

// ---------------------------------------------------------------------------
// Parametros
// ---------------------------------------------------------------------------

/** Antiguedad maxima del ultimo snapshot antes de forzar uno nuevo (35.4 paso 5). */
export const SNAPSHOT_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000

/** per_page alto para traer taxes/customers en una sola llamada. */
const CUSTOMERS_PER_PAGE = 200
const TAXES_PER_PAGE = 200

// ---------------------------------------------------------------------------
// Progreso (38.6 paso 4)
// ---------------------------------------------------------------------------

export type SnapshotEntity = 'products' | 'customers' | 'taxes'

export interface SnapshotProgress {
  entity: SnapshotEntity
  /** Fase del repoblado de esta entidad. */
  phase:  'start' | 'done'
  /** Conteo de registros cargados (cuando phase === 'done'). */
  count?: number
}

export type SnapshotProgressListener = (p: SnapshotProgress) => void

export interface SnapshotResult {
  customers: number
  taxes:     number
  /** ISO timestamp marcado como last_full_sync. */
  completedAt: string
}

// ---------------------------------------------------------------------------
// SnapshotService
// ---------------------------------------------------------------------------

export interface SnapshotServiceOptions {
  tenantSlug: string
  onProgress?: SnapshotProgressListener
}

export class SnapshotService {
  private tenantSlug: string
  private onProgress?: SnapshotProgressListener

  constructor(opts: SnapshotServiceOptions) {
    this.tenantSlug = opts.tenantSlug
    this.onProgress = opts.onProgress
  }

  /**
   * True si hace falta un snapshot: no hay datos locales, o nunca se
   * sincronizo, o el ultimo full sync supera SNAPSHOT_MAX_AGE_MS (35.4 paso 5).
   */
  async needsSnapshot(nowMs: number = Date.now()): Promise<boolean> {
    const hasData = await hasProductData()
    if (!hasData) return true

    const last = await getLastFullSync()
    if (last === null) return true

    const lastMs = new Date(last).getTime()
    return nowMs - lastMs > SNAPSHOT_MAX_AGE_MS
  }

  /**
   * Repuebla IndexedDB completo en el orden de 38.6: products -> customers
   * -> taxes. fullSync de products ya marca last_full_sync; aqui ademas
   * actualizamos SETTING_LAST_PULL para que el pull incremental arranque
   * desde el momento del snapshot.
   */
  async run(): Promise<SnapshotResult> {
    // 1. Products (reusa fullSync existente, multi-pagina).
    this.emit({ entity: 'products', phase: 'start' })
    await fullSyncProducts(this.tenantSlug)
    const productCount = await db.products.count()
    this.emit({ entity: 'products', phase: 'done', count: productCount })

    // 2. Customers.
    this.emit({ entity: 'customers', phase: 'start' })
    const customers = await this.fetchAllCustomers()
    await CustomerRepo.upsertMany(customers)
    this.emit({ entity: 'customers', phase: 'done', count: customers.length })

    // 3. Taxes.
    this.emit({ entity: 'taxes', phase: 'start' })
    const taxes = await this.fetchAllTaxes()
    await TaxRepo.upsertMany(taxes)
    this.emit({ entity: 'taxes', phase: 'done', count: taxes.length })

    // 6. Marca last_full_sync (products ya lo hizo) + last_pull para el
    // pull incremental posterior.
    const completedAt = new Date().toISOString()
    await db.settings.put({
      key:       SETTING_LAST_PULL,
      value:     completedAt,
      updatedAt: completedAt,
    })

    return {
      customers: customers.length,
      taxes:     taxes.length,
      completedAt,
    }
  }

  // -------------------------------------------------------------------------
  // Fetch helpers
  // -------------------------------------------------------------------------

  private async fetchAllCustomers(): Promise<Customer[]> {
    const { data, error } = await listCustomers({
      headers: { 'X-Tenant': this.tenantSlug },
      query: { per_page: CUSTOMERS_PER_PAGE, sort: 'name', direction: 'asc' },
    })
    if (error || !data) {
      throw new Error(`Error en snapshot customers: ${JSON.stringify(error)}`)
    }
    return data.data
  }

  private async fetchAllTaxes(): Promise<Tax[]> {
    const { data, error } = await listTaxes({
      headers: { 'X-Tenant': this.tenantSlug },
      query: { per_page: TAXES_PER_PAGE },
    })
    if (error || !data) {
      throw new Error(`Error en snapshot taxes: ${JSON.stringify(error)}`)
    }
    return data.data
  }

  private emit(p: SnapshotProgress): void {
    this.onProgress?.(p)
  }
}
