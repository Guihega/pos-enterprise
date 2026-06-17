/**
 * Esquema de IndexedDB (Dexie) — Iteracion 1, Fase 2 (doc maestro sec. 36).
 *
 * Solo tablas de catalogo offline: products, categories, taxes, units,
 * settings. Las tablas de ventas/sync_queue/cash_sessions se agregan en
 * version(2) cuando la Iteracion 2 las necesite (ver 36.2: cada release
 * que cambie schema incrementa la version con upgrade()).
 *
 * Los campos de ProductLocal mapean el shape REAL de ProductResource
 * (backend), no el aspiracional del doc maestro 36.1 (que incluye campos
 * como price_wholesale/is_weighable/tracks_lots que no existen en el
 * backend actual).
 */
import Dexie, { type Table } from 'dexie'

export interface ProductLocal {
  uuid: string
  sku: string
  name: string
  price: number
  cost: number
  hasDiscount: boolean
  trackInventory: boolean
  isSellable: boolean
  isPurchasable: boolean
  allowDecimals: boolean
  status: string
  categoryUuid: string | null
  categoryName: string | null
  categorySlug: string | null
  unitUuid: string | null
  unitCode: string | null
  unitName: string | null
  unitSymbol: string | null
  taxUuid: string | null
  taxCode: string | null
  taxName: string | null
  taxRate: number | null
  taxIsInclusive: boolean | null
  /** Tokens normalizados para busqueda local (indice multiEntry). */
  searchBlob: string[]
  updatedAt: string
}

export interface CategoryLocal {
  uuid: string
  name: string
  parentUuid: string | null
}

export interface TaxLocal {
  uuid: string
  code: string
  name: string
  rate: number
  isInclusive: boolean
}

export interface UnitLocal {
  uuid: string
  code: string
  name: string
  symbol: string | null
}

export interface SettingLocal {
  key: string
  value: unknown
  updatedAt: string
}

export const SETTING_LAST_PRODUCT_SYNC = 'catalog:last_full_sync'

export const SETTING_DEVICE_ID = 'device:id'

export interface FolioRangeLocal {
  /** cashRegisterUuid:series:deviceId */
  id: string
  cashRegisterUuid: string
  series: string
  deviceId: string
  rangeStart: number
  rangeEnd: number
  /** Proximo folio a usar; se incrementa atomicamente con cada venta. */
  nextValue: number
  syncedAt: string
}

export type SyncStatus = 'pending' | 'in_flight' | 'success' | 'conflict' | 'failed'
export type SyncOperation = 'create' | 'update' | 'delete'
export type SyncEntityType = 'sale'

export interface SyncQueueItem {
  /** Auto-incremented by Dexie. */
  id?: number
  clientUuid: string
  entityType: SyncEntityType
  entityUuid: string
  operation: SyncOperation
  payload: unknown
  clientTimestamp: string
  attempts: number
  nextAttemptAt: string
  lastError: string | null
  status: SyncStatus
  createdAt: string
}

export type SaleLocalStatus = 'draft' | 'completed' | 'voided'

export interface SaleLocal {
  uuid: string
  folio: string
  cashRegisterUuid: string
  cashSessionUuid: string
  customerUuid: string | null
  subtotal: number
  discountTotal: number
  taxTotal: number
  total: number
  amountPaid: number
  change: number
  paymentMethod: string
  status: SaleLocalStatus
  /** true = creada sin red, pendiente de sync */
  createdOffline: boolean
  syncStatus: SyncStatus
  clientTimestamp: string
  serverTimestamp: string | null
  createdAt: string
}

export type ConflictReason =
  | 'IDEMPOTENT'
  | 'STOCK_NEGATIVE'
  | 'PRICE_MISMATCH'
  | 'PRODUCT_DELETED'
  | 'CASH_SESSION_CLOSED'
  | 'STALE_VERSION'
  | 'FOLIO_DUPLICATE'
  | 'TENANT_SUSPENDED'
  | 'UNKNOWN'

export type ConflictResolutionKind = 'use_client' | 'use_server' | 'manual'

export interface ConflictLocal {
  /** uuid del conflicto (generado en cliente al detectar). */
  uuid: string
  /** Tipo de entidad en conflicto: 'sale' | 'product' | 'customer' ... */
  entityType: SyncEntityType
  /** uuid de la entidad en conflicto. */
  entityUuid: string
  /** clientUuid del item de la cola que origino el conflicto. */
  clientUuid: string
  /** Razon del conflicto (ver tabla 39.1). */
  reason: ConflictReason
  /** Payload original que el cliente intento sincronizar. */
  clientPayload: unknown
  /** Estado/datos que devolvio el servidor (si aplica). */
  serverData: unknown
  /** Resolucion aplicada, null si pendiente. */
  resolution: ConflictResolutionKind | null
  /** true si se resolvio automaticamente, false si requiere humano. */
  auto: boolean
  /** Rol requerido para resolver manualmente (ej: 'manager'), null si auto. */
  requireRole: string | null
  /** ISO timestamp de deteccion. */
  detectedAt: string
  /** ISO timestamp de resolucion, null si pendiente. */
  resolvedAt: string | null
}

export class POSDatabase extends Dexie {
  products!: Table<ProductLocal, string>
  categories!: Table<CategoryLocal, string>
  taxes!: Table<TaxLocal, string>
  units!: Table<UnitLocal, string>
  settings!: Table<SettingLocal, string>
  folioRanges!: Table<FolioRangeLocal, string>
  syncQueue!: Table<SyncQueueItem, number>
  sales!: Table<SaleLocal, string>
  conflicts!: Table<ConflictLocal, string>

  constructor() {
    super('POSDatabase')
    this.version(1).stores({
      products: 'uuid, sku, name, categoryUuid, status, *searchBlob, updatedAt',
      categories: 'uuid, parentUuid, name',
      taxes: 'uuid, code',
      units: 'uuid, code',
      settings: 'key',
    })
    this.version(2).stores({
      products: 'uuid, sku, name, categoryUuid, status, *searchBlob, updatedAt',
      categories: 'uuid, parentUuid, name',
      taxes: 'uuid, code',
      units: 'uuid, code',
      settings: 'key',
      folioRanges: 'id, cashRegisterUuid, series',
      syncQueue: '++id, status, nextAttemptAt, entityType, entityUuid, clientUuid',
      sales: 'uuid, folio, cashRegisterUuid, cashSessionUuid, status, syncStatus, createdAt',
    })
    this.version(3).stores({
      products: 'uuid, sku, name, categoryUuid, status, *searchBlob, updatedAt',
      categories: 'uuid, parentUuid, name',
      taxes: 'uuid, code',
      units: 'uuid, code',
      settings: 'key',
      folioRanges: 'id, cashRegisterUuid, series',
      syncQueue: '++id, status, nextAttemptAt, entityType, entityUuid, clientUuid',
      sales: 'uuid, folio, cashRegisterUuid, cashSessionUuid, status, syncStatus, createdAt',
      conflicts: 'uuid, entityType, entityUuid, clientUuid, reason, resolvedAt, detectedAt',
    })
  }
}

export const db = new POSDatabase()
