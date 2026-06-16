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

export class POSDatabase extends Dexie {
  products!: Table<ProductLocal, string>
  categories!: Table<CategoryLocal, string>
  taxes!: Table<TaxLocal, string>
  units!: Table<UnitLocal, string>
  settings!: Table<SettingLocal, string>

  constructor() {
    super('POSDatabase')
    this.version(1).stores({
      products: 'uuid, sku, name, categoryUuid, status, *searchBlob, updatedAt',
      categories: 'uuid, parentUuid, name',
      taxes: 'uuid, code',
      units: 'uuid, code',
      settings: 'key',
    })
  }
}

export const db = new POSDatabase()
