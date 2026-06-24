/**
 * Store de ventas.
 *
 * Action principal: checkout(items, payments) que arma el payload de
 * POST /sales con el cash_session_uuid de la sesion activa y el
 * warehouse_uuid del branch default del usuario, y registra la venta.
 *
 * No persiste nada en localStorage: las ventas viven en el backend.
 * El state local es transitorio (submitting, error, ultima venta para
 * mostrar folio en el banner de exito).
 *
 * Errores comunes traducidos:
 *  - PAYMENT_MISMATCH: pago insuficiente o sobrepago invalido. Modal
 *    queda abierto, cajero ajusta.
 *  - INSUFFICIENT_STOCK: algun item no tiene stock. Modal abierto.
 *  - SESSION_NOT_OPEN: la caja se cerro por otro lado. Refrescamos
 *    cashSession para que el modal de apertura aparezca de nuevo.
 *  - 422 validacion Laravel: mostramos el primer error.
 *  - Cualquier otro: mensaje generico.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { createSale, cancelSale } from '@/lib/api/generated'
import { db } from '@/db/schema'
import type { SaleLocal } from '@/db/schema'
import { enqueue } from '@/repositories/SyncQueueRepository'
import { nextFolio, DEFAULT_SERIES, FolioExhaustedError } from '@/lib/FolioGenerator'
import { useSyncStore } from '@/stores/sync'
import type {
  CreateSaleItem,
  CreateSalePayment,
  Sale,
} from '@/lib/api/generated'
import { errorCode, getTenantOrThrow, humanizeError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { useCashSessionStore } from '@/stores/cashSession'

export interface CancelResult {
  ok: boolean
  sale?: Sale
  errorMessage?: string
}

export interface CheckoutResult {
  ok: boolean
  sale?: Sale
  /** True si el error implica que la sesion de caja ya no esta abierta. */
  sessionLost?: boolean
  /** True si la venta se guardo localmente y se encolo para sync (9a, RN-150). */
  offline?: boolean
  /** Venta local creada en modo offline. */
  localSale?: SaleLocal
}

export const useSalesStore = defineStore('sales', () => {
  const authStore = useAuthStore()
  const cartStore = useCartStore()
  const cashStore = useCashSessionStore()

  // ---- state ----
  const submitting = ref(false)
  const errorMessage = ref<string | null>(null)
  const lastSale = ref<Sale | null>(null)

  // ---- actions ----

  /**
   * Registra una venta con los pagos provistos. Mapea items del cart
   * store a CreateSaleItem. Lee cash_session_uuid del cashStore y
   * warehouse_uuid del user.default_branch.default_warehouse_uuid.
   *
   * Devuelve CheckoutResult con ok = true si el backend respondio 201.
   * Si ok = false, errorMessage queda con el motivo y sessionLost
   * indica si la caja dejo de estar abierta (parent debe refrescar).
   */
  async function checkout(
    payments: CreateSalePayment[],
  ): Promise<CheckoutResult> {
    errorMessage.value = null

    // Validaciones de precondicion (no llegan al backend).
    const session = cashStore.currentSession
    if (!session) {
      errorMessage.value = 'No hay sesion de caja abierta.'
      return { ok: false, sessionLost: true }
    }

    const warehouseUuid =
      authStore.user?.default_branch?.default_warehouse_uuid ?? null
    if (!warehouseUuid) {
      errorMessage.value =
        'Tu sucursal no tiene un almacen default configurado. Contacta al administrador.'
      return { ok: false }
    }

    if (cartStore.isEmpty) {
      errorMessage.value = 'El carrito esta vacio.'
      return { ok: false }
    }

    if (payments.length === 0) {
      errorMessage.value = 'Debes registrar al menos un pago.'
      return { ok: false }
    }

    // Map cart items -> CreateSaleItem.
    const items: CreateSaleItem[] = cartStore.items.map((it) => ({
      product_uuid: it.productUuid,
      quantity: it.quantity,
    }))

    // Body del checkout: identico online y offline. En offline se encola
    // como payload del item de sync (backend lo lee con CheckoutRequest::fromArray).
    const body = {
      cash_session_uuid: session.uuid,
      warehouse_uuid: warehouseUuid,
      items,
      payments,
    }

    // Modo offline (doc 9a, RN-150): si no hay red, no intentamos el POST;
    // creamos la venta local con UUID de cliente y la encolamos en sync_queue.
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      return await checkoutOffline(body, session.uuid, payments)
    }

    submitting.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await createSale({
        headers: { 'X-Tenant': tenant },
        body,
      })

      if (data && !error) {
        lastSale.value = data.data
        return { ok: true, sale: data.data }
      }

      // Manejo de codigos de error conocidos.
      const code = errorCode(error)
      if (code === 'SESSION_NOT_OPEN') {
        errorMessage.value =
          'La sesion de caja ya no esta abierta. Recarga el POS.'
        // Refrescar para que el modal de apertura aparezca.
        await cashStore.loadCurrent()
        return { ok: false, sessionLost: true }
      }
      if (code === 'INSUFFICIENT_STOCK') {
        errorMessage.value = humanizeError(
          error,
          'Algun producto no tiene stock suficiente.',
        )
        return { ok: false }
      }
      if (code === 'PAYMENT_MISMATCH') {
        errorMessage.value = humanizeError(
          error,
          'Los pagos no cuadran con el total.',
        )
        return { ok: false }
      }
      if (code === 'INSUFFICIENT_CREDIT') {
        errorMessage.value = humanizeError(
          error,
          'El cliente no tiene credito suficiente.',
        )
        return { ok: false }
      }

      // 422 de validacion Laravel: shape distinto, { message, errors }.
      if (
        error &&
        typeof error === 'object' &&
        'errors' in error &&
        typeof (error as { errors?: unknown }).errors === 'object'
      ) {
        const validationErrors = (error as { errors: Record<string, string[]> })
          .errors
        const firstKey = Object.keys(validationErrors)[0]
        const firstMsg =
          firstKey && validationErrors[firstKey]
            ? validationErrors[firstKey][0]
            : null
        errorMessage.value = firstMsg ?? 'Error de validacion en la venta.'
        return { ok: false }
      }

      errorMessage.value = humanizeError(error, 'No se pudo registrar la venta.')
      return { ok: false }
    } catch {
      // Fallo de red durante el POST (cayo la conexion a media venta):
      // degradar a modo offline y encolar (RN-150, 9a).
      submitting.value = false
      return await checkoutOffline(body, session.uuid, payments)
    } finally {
      submitting.value = false
    }
  }

  /**
   * Crea la venta en IndexedDB con UUID de cliente y la encola en sync_queue
   * para drenarla al recuperar conexion (doc 9a, RN-150). No bloquea: la venta
   * se considera completada localmente.
   */
  async function checkoutOffline(
    body: {
      cash_session_uuid: string
      warehouse_uuid: string
      items: CreateSaleItem[]
      payments: CreateSalePayment[]
    },
    cashSessionUuid: string,
    payments: CreateSalePayment[],
  ): Promise<CheckoutResult> {
    const clientUuid = crypto.randomUUID()
    const now = new Date().toISOString()
    const amountPaid = payments.reduce((acc, p) => acc + (p.amount ?? 0), 0)
    const total = cartStore.grandTotal
    const cashRegisterUuid = cashStore.currentSession?.register?.uuid ?? ''

    let folio: string
    try {
      folio = await nextFolio(cashRegisterUuid, DEFAULT_SERIES)
    } catch (err) {
      if (err instanceof FolioExhaustedError) {
        errorMessage.value = err.message
        return { ok: false }
      }
      errorMessage.value =
        err instanceof Error ? err.message : 'No se pudo asignar folio a la venta.'
      return { ok: false }
    }

    const localSale: SaleLocal = {
      uuid: clientUuid,
      folio,
      cashRegisterUuid,
      cashSessionUuid,
      customerUuid: null,
      subtotal: cartStore.subtotal,
      discountTotal: 0,
      taxTotal: cartStore.taxTotal,
      total,
      amountPaid,
      change: Math.max(0, amountPaid - total),
      paymentMethod: payments[0]?.method ?? 'cash',
      status: 'completed',
      createdOffline: true,
      syncStatus: 'pending',
      clientTimestamp: now,
      serverTimestamp: null,
      createdAt: now,
    }

    try {
      // Persistir la venta local + encolar en sync_queue (ambos en 9a).
      await db.sales.put(localSale)
      await enqueue({
        clientUuid,
        entityType: 'sale',
        entityUuid: clientUuid,
        operation: 'create',
        payload: JSON.parse(JSON.stringify(body)),
        clientTimestamp: now,
      })
      // Refrescar contadores para que el banner muestre el pendiente.
      await useSyncStore().refreshCounts()
      lastSale.value = null
      return { ok: true, offline: true, localSale }
    } catch (err) {
      errorMessage.value =
        err instanceof Error ? err.message : 'No se pudo guardar la venta offline.'
      return { ok: false }
    }
  }

  const cancelling = ref(false)

  async function cancel(saleUuid: string, reason: string): Promise<CancelResult> {
    if (cancelling.value) return { ok: false, errorMessage: 'Operacion en curso.' }
    cancelling.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await cancelSale({
        headers: { 'X-Tenant': tenant },
        path: { uuid: saleUuid },
        body: { reason },
      })
      if (error !== undefined || data === undefined) {
        const msg = humanizeError(error, 'No se pudo cancelar la venta.')
        return { ok: false, errorMessage: msg }
      }
      return { ok: true, sale: data.data }
    } catch {
      return { ok: false, errorMessage: 'Error inesperado al cancelar la venta.' }
    } finally {
      cancelling.value = false
    }
  }

  function clearError(): void {
    errorMessage.value = null
  }

  function clearLastSale(): void {
    lastSale.value = null
  }

  /** Limpia el state. Llamar al logout. */
  function clear(): void {
    submitting.value = false
    errorMessage.value = null
    lastSale.value = null
  }

  return {
    submitting,
    errorMessage,
    lastSale,
    checkout,
    cancel,
    cancelling,
    clearError,
    clearLastSale,
    clear,
  }
})
