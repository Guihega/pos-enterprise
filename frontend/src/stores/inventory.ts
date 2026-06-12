/**
 * Store de escritura de inventario.
 *
 * El LISTADO de existencias y el kardex viven en composables
 * (useInventory, useKardex). Este store cubre la mutacion: ajustar stock.
 * Sigue el patron { ok, ... } para que la vista decida segun el resultado.
 *
 * adjust() envia un delta (positivo o negativo, nunca cero) con un motivo
 * obligatorio y devuelve el movimiento de inventario creado. Maneja el
 * 409 INSUFFICIENT_STOCK (ajuste que dejaria el stock en negativo) y el
 * 422 de validacion (delta/reason invalidos).
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { adjustStock } from '@/lib/api/generated'
import type { InventoryMovement, AdjustStockInput } from '@/lib/api/generated'
import { getTenantOrThrow, humanizeValidationError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

export interface AdjustResult {
  ok: boolean
  movement?: InventoryMovement
  errorMessage?: string
}

export const useInventoryStore = defineStore('inventory', () => {
  const authStore = useAuthStore()

  const adjusting = ref(false)

  async function adjust(payload: AdjustStockInput): Promise<AdjustResult> {
    if (adjusting.value) return { ok: false, errorMessage: 'Operacion en curso.' }
    adjusting.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await adjustStock({
        headers: { 'X-Tenant': tenant },
        body: payload,
      })
      if (error !== undefined || data === undefined) {
        // Incluye 409 INSUFFICIENT_STOCK (ErrorEnvelope) y 422 de validacion.
        return { ok: false, errorMessage: humanizeValidationError(error, 'No se pudo aplicar el ajuste.') }
      }
      return { ok: true, movement: data.data }
    } catch {
      return { ok: false, errorMessage: 'Error inesperado al aplicar el ajuste.' }
    } finally {
      adjusting.value = false
    }
  }

  return { adjusting, adjust }
})
