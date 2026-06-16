/**
 * ProductRepository — acceso al catalogo desde IndexedDB (offline-first).
 *
 * Fase 2 / Iteracion 1. Patron doc maestro 35.1:
 *   - Lee SIEMPRE de IndexedDB (Dexie).
 *   - fullSync() pobla IndexedDB desde la API cuando hay conexion.
 *   - Los stores/composables consumen este repositorio; no hablan
 *     directamente con el SDK para operaciones de lectura del catalogo.
 *
 * El metodo getPage() devuelve el mismo shape { data, meta } que
 * listProducts() del SDK, para que useProducts.fetchPage() no cambie
 * su logica de paginacion ni sus tests.
 */
import { listProducts } from '@/lib/api/generated'
import type { Product } from '@/lib/api/generated'
import {
  db,
  SETTING_LAST_PRODUCT_SYNC,
  type ProductLocal,
} from '@/db/schema'
import { buildProductSearchBlob, matchesSearch } from '@/db/text'

// ---------------------------------------------------------------------------
// Mapeo API -> local
// ---------------------------------------------------------------------------

function toLocal(p: Product): ProductLocal {
  return {
    uuid: p.uuid,
    sku: p.sku,
    name: p.name,
    price: p.pricing.price,
    cost: p.pricing.cost,
    hasDiscount: p.pricing.has_discount,
    trackInventory: p.flags.track_inventory,
    isSellable: p.flags.is_sellable,
    isPurchasable: p.flags.is_purchasable,
    allowDecimals: p.flags.allow_decimals,
    status: p.status,
    categoryUuid: p.category?.uuid ?? null,
    categoryName: p.category?.name ?? null,
    categorySlug: p.category?.slug ?? null,
    unitUuid: p.unit?.uuid ?? null,
    unitCode: p.unit?.code ?? null,
    unitName: p.unit?.name ?? null,
    unitSymbol: p.unit?.symbol ?? null,
    taxUuid: p.tax?.uuid ?? null,
    taxCode: p.tax?.code ?? null,
    taxName: p.tax?.name ?? null,
    taxRate: p.tax?.rate ?? null,
    taxIsInclusive: p.tax?.is_inclusive ?? null,
    searchBlob: buildProductSearchBlob(p.name, p.sku),
    updatedAt: p.updated_at,
  }
}

// ---------------------------------------------------------------------------
// Mapeo local -> Product (para que useProducts mantenga su tipo Product[])
// ---------------------------------------------------------------------------

function fromLocal(l: ProductLocal): Product {
  return {
    uuid: l.uuid,
    sku: l.sku,
    name: l.name,
    pricing: {
      price: l.price,
      cost: l.cost,
      has_discount: l.hasDiscount,
    },
    flags: {
      track_inventory: l.trackInventory,
      is_sellable: l.isSellable,
      is_purchasable: l.isPurchasable,
      allow_decimals: l.allowDecimals,
    },
    status: l.status as Product['status'],
    category: l.categoryUuid
      ? { uuid: l.categoryUuid, name: l.categoryName ?? '', slug: l.categorySlug ?? '' }
      : null,
    unit: l.unitUuid
      ? {
          uuid: l.unitUuid,
          code: l.unitCode ?? '',
          name: l.unitName ?? '',
          symbol: l.unitSymbol ?? '',
        }
      : undefined,
    tax: l.taxUuid
      ? {
          uuid: l.taxUuid,
          code: l.taxCode ?? '',
          name: l.taxName ?? '',
          rate: l.taxRate ?? 0,
          is_inclusive: l.taxIsInclusive ?? false,
        }
      : null,
    // Campos opcionales no cacheados en Iteracion 1 (se agregan si se
    // necesitan en iteraciones futuras, sin romper el schema existente).
    updated_at: l.updatedAt,
    created_at: l.updatedAt, // aproximacion; no se cachea created_at
  }
}

// ---------------------------------------------------------------------------
// API publica del repositorio
// ---------------------------------------------------------------------------

/** True si la tabla products tiene al menos un registro. */
export async function hasData(): Promise<boolean> {
  const count = await db.products.count()
  return count > 0
}

/** ISO string de la ultima sincronizacion completa, o null si nunca. */
export async function getLastFullSync(): Promise<string | null> {
  const setting = await db.settings.get(SETTING_LAST_PRODUCT_SYNC)
  return setting ? (setting.value as string) : null
}

/**
 * Descarga el catalogo completo desde la API y lo guarda en IndexedDB.
 * Reemplaza todos los productos existentes en una sola transaccion.
 * Lanza si la API devuelve error (el llamador decide si reintentar).
 */
export async function fullSync(tenant: string): Promise<void> {
  const PER_PAGE = 100
  let page = 1
  let lastPage = 1
  const allProducts: ProductLocal[] = []

  do {
    const { data, error } = await listProducts({
      headers: { 'X-Tenant': tenant },
      query: { per_page: PER_PAGE, page, sort: 'name', direction: 'asc' },
    })

    if (error || !data) {
      throw new Error(`Error en fullSync pagina ${page}: ${JSON.stringify(error)}`)
    }

    for (const p of data.data) {
      allProducts.push(toLocal(p))
    }

    lastPage = data.meta.last_page
    page++
  } while (page <= lastPage)

  await db.transaction('rw', db.products, db.settings, async () => {
    await db.products.clear()
    await db.products.bulkPut(allProducts)
    await db.settings.put({
      key: SETTING_LAST_PRODUCT_SYNC,
      value: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    })
  })
}

/**
 * Lee productos de IndexedDB con paginacion y busqueda local.
 * Devuelve el mismo shape { data, meta } que listProducts() del SDK,
 * para que useProducts.fetchPage() no cambie su contrato.
 */
export async function getPage(params: {
  search?: string
  page: number
  perPage: number
}): Promise<{
  data: Product[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}> {
  const { search = '', page, perPage } = params

  // Leer todos y filtrar en memoria (catalogo tipico < 10k productos).
  // Para > 50k usar Web Worker + flexsearch (doc maestro 36.4).
  let all: ProductLocal[]

  if (search.trim()) {
    all = await db.products
      .filter((p) => matchesSearch(p.searchBlob, search))
      .toArray()
  } else {
    all = await db.products.orderBy('name').toArray()
  }

  const total = all.length
  const lastPage = Math.max(1, Math.ceil(total / perPage))
  const clampedPage = Math.min(Math.max(1, page), lastPage)
  const from = total === 0 ? null : (clampedPage - 1) * perPage + 1
  const to = total === 0 ? null : Math.min(clampedPage * perPage, total)
  const slice = all.slice((clampedPage - 1) * perPage, clampedPage * perPage)

  return {
    data: slice.map(fromLocal),
    meta: {
      current_page: clampedPage,
      last_page: lastPage,
      per_page: perPage,
      total,
      from,
      to,
    },
  }
}
