/**
 * Store del carrito del POS.
 *
 * Estado y operaciones:
 *  - Lista de items con snapshot del producto al momento de anadir.
 *    Los snapshots evitan que cambios del backend (precio nuevo, producto
 *    archivado) modifiquen una venta en curso.
 *  - Una linea por producto: si vuelves a anadir el mismo, suma a su qty.
 *  - Cantidades con decimales si el producto lo permite (productos KG/LT).
 *  - Calculos: subtotal sin IVA, total de IVA, total general.
 *    Soporta IVA inclusivo y no-inclusivo por producto.
 *  - Persistencia en localStorage por tenant. Si el tenant cambia,
 *    el carrito anterior NO se restaura.
 *
 * El carrito se vacia al confirmar venta (3e) o explicitamente con
 * `clear()`. Tambien se limpia automaticamente al logout.
 */
import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import type { Product } from '@/lib/api/generated'
import { useAuthStore } from '@/stores/auth'

const CART_ITEMS_KEY = 'pos:cart:items'
const CART_TENANT_KEY = 'pos:cart:tenant'

/**
 * Item del carrito. Contiene un snapshot de los datos del producto al
 * momento de anadirse. NO se re-consulta del backend ante cambios.
 */
export interface CartItem {
  productUuid: string
  name: string
  sku: string
  unitSymbol: string
  unitPrice: number
  quantity: number
  taxRate: number
  taxIsInclusive: boolean
  allowDecimals: boolean
}

/** Total parcial de una linea (qty * unitPrice). */
export function lineTotal(item: CartItem): number {
  return round2(item.unitPrice * item.quantity)
}

/**
 * Para una linea, devuelve (montoBase, montoIVA) considerando si el
 * precio es inclusivo o no.
 *
 * Inclusivo:  precio_mostrado = base + iva  =>  base = total / (1+rate)
 * Exclusivo:  precio_mostrado = base        =>  iva = total * rate
 */
function lineBaseAndTax(item: CartItem): { base: number; tax: number } {
  const total = lineTotal(item)
  if (item.taxIsInclusive) {
    const base = total / (1 + item.taxRate)
    return { base: round2(base), tax: round2(total - base) }
  }
  return { base: round2(total), tax: round2(total * item.taxRate) }
}

function round2(value: number): number {
  return Math.round(value * 100) / 100
}

export const useCartStore = defineStore('cart', () => {
  const authStore = useAuthStore()

  // ---- state ----
  const items = ref<CartItem[]>([])

  // ---- getters ----

  /** Numero de items distintos (no la suma de cantidades). */
  const lineCount = computed(() => items.value.length)

  /** Suma de cantidades de todas las lineas. */
  const totalQuantity = computed(() =>
    items.value.reduce((acc, it) => acc + it.quantity, 0),
  )

  /** Suma de los montos base (sin IVA) de todas las lineas. */
  const subtotal = computed(() =>
    round2(
      items.value.reduce((acc, it) => acc + lineBaseAndTax(it).base, 0),
    ),
  )

  /** Suma del IVA de todas las lineas. */
  const taxTotal = computed(() =>
    round2(
      items.value.reduce((acc, it) => acc + lineBaseAndTax(it).tax, 0),
    ),
  )

  /**
   * Total general = subtotal + IVA.
   *
   * Para items con tax inclusivo: subtotal + iva == precio mostrado,
   * asi que coincide con lo que el usuario espera.
   * Para items con tax exclusivo: subtotal == precio mostrado, y el
   * iva se SUMA encima del precio. Por eso NO podemos hacer
   * `sum(lineTotal)` ingenuamente: dejaria fuera el iva de los items
   * exclusivos.
   */
  const grandTotal = computed(() =>
    round2(subtotal.value + taxTotal.value),
  )

  const isEmpty = computed(() => items.value.length === 0)

  // ---- actions ----

  /**
   * Anade un producto al carrito.
   *
   * Si el producto ya esta en una linea, suma `qty` a la cantidad
   * existente. Si no, crea una nueva linea con el snapshot.
   */
  function add(product: Product, qty = 1): void {
    if (qty <= 0) {
      return
    }

    const existing = items.value.find((it) => it.productUuid === product.uuid)
    if (existing) {
      existing.quantity = roundQty(existing.quantity + qty, existing.allowDecimals)
      return
    }

    items.value.push(toCartItem(product, qty))
  }

  /**
   * Cambia la cantidad de un item. Si llega a 0 o menos, lo elimina.
   * Si el item no permite decimales, redondea al entero.
   */
  function setQuantity(productUuid: string, qty: number, maxQty = Infinity): void {
    const idx = items.value.findIndex((it) => it.productUuid === productUuid)
    if (idx === -1) {
      return
    }
    const item = items.value[idx]
    if (!item) {
      return
    }

    if (qty <= 0) {
      items.value.splice(idx, 1)
      return
    }

    const clamped = maxQty < Infinity ? Math.min(qty, maxQty) : qty
  item.quantity = roundQty(clamped, item.allowDecimals)
  }

  /** Elimina una linea explicitamente. */
  function remove(productUuid: string): void {
    items.value = items.value.filter((it) => it.productUuid !== productUuid)
  }

  /** Vacia el carrito. Llamar al confirmar venta o al cancelar. */
  function clear(): void {
    items.value = []
  }

  /**
   * Rehidrata el carrito desde localStorage si pertenece al tenant
   * actualmente autenticado. Si el tenant cambio, descarta el carrito
   * persistido.
   *
   * Llamar desde main.ts despues de auth.hydrate().
   */
  function hydrate(): void {
    const storedTenant = localStorage.getItem(CART_TENANT_KEY)
    const currentTenant = authStore.tenant

    if (!storedTenant || storedTenant !== currentTenant) {
      // Tenant ausente o distinto: descartamos lo persistido.
      localStorage.removeItem(CART_ITEMS_KEY)
      localStorage.removeItem(CART_TENANT_KEY)
      return
    }

    const raw = localStorage.getItem(CART_ITEMS_KEY)
    if (!raw) {
      return
    }

    try {
      const parsed = JSON.parse(raw) as unknown
      if (Array.isArray(parsed)) {
        items.value = parsed as CartItem[]
      }
    } catch {
      // localStorage corrupto: ignorar y dejar carrito vacio.
      localStorage.removeItem(CART_ITEMS_KEY)
      localStorage.removeItem(CART_TENANT_KEY)
    }
  }

  // Auto-persistencia: cualquier cambio en items se refleja en localStorage.
  // Tambien se guarda el tenant activo para evitar mezclar carritos.
  watch(
    items,
    (next) => {
      const tenant = authStore.tenant
      if (!tenant) {
        // Sin sesion: no persistir nada y limpiar lo viejo.
        localStorage.removeItem(CART_ITEMS_KEY)
        localStorage.removeItem(CART_TENANT_KEY)
        return
      }
      localStorage.setItem(CART_ITEMS_KEY, JSON.stringify(next))
      localStorage.setItem(CART_TENANT_KEY, tenant)
    },
    { deep: true },
  )

  return {
    // state
    items,
    // getters
    lineCount,
    totalQuantity,
    subtotal,
    taxTotal,
    grandTotal,
    isEmpty,
    // actions
    add,
    setQuantity,
    remove,
    clear,
    hydrate,
  }
})

/** Convierte un Product (del SDK) al snapshot que guarda el carrito. */
function toCartItem(product: Product, qty: number): CartItem {
  const allowDecimals = product.flags.allow_decimals
  return {
    productUuid: product.uuid,
    name: product.name,
    sku: product.sku,
    unitSymbol: product.unit?.symbol ?? 'u.',
    unitPrice: product.pricing.price,
    quantity: roundQty(qty, allowDecimals),
    taxRate: product.tax?.rate ?? 0,
    taxIsInclusive: product.tax?.is_inclusive ?? false,
    allowDecimals,
  }
}

/** Redondea la cantidad segun si el producto permite decimales. */
function roundQty(qty: number, allowDecimals: boolean): number {
  if (!allowDecimals) {
    return Math.max(0, Math.round(qty))
  }
  // 3 decimales es suficiente para granel (gramos en kg).
  return Math.max(0, Math.round(qty * 1000) / 1000)
}
