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
import { createSale } from '@/lib/api/generated'
import type {
  CreateSaleItem,
  CreateSalePayment,
  Sale,
} from '@/lib/api/generated'
import { errorCode, getTenantOrThrow, humanizeError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { useCashSessionStore } from '@/stores/cashSession'

export interface CheckoutResult {
  ok: boolean
  sale?: Sale
  /** True si el error implica que la sesion de caja ya no esta abierta. */
  sessionLost?: boolean
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

    submitting.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await createSale({
        headers: { 'X-Tenant': tenant },
        body: {
          cash_session_uuid: session.uuid,
          warehouse_uuid: warehouseUuid,
          items,
          payments,
        },
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
    } finally {
      submitting.value = false
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
    clearError,
    clearLastSale,
    clear,
  }
})
