/**
 * Store de escritura de clientes.
 *
 * El LISTADO y la busqueda viven en el composable useCustomers (paginacion,
 * debounce). Este store se enfoca en las mutaciones: crear, actualizar y
 * eliminar. Sigue el patron { ok, ... } de products para que la vista
 * decida que hacer segun el resultado sin acoplarse a la API.
 *
 * Nota de shapes: el backend recibe el cliente PLANO (CustomerInput) pero
 * devuelve el recurso ANIDADO (Customer con tax/contact/address/credit/flags).
 * El mapeo plano<->anidado lo hace el form; aqui solo pasamos el payload.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { createCustomer, updateCustomer, deleteCustomer } from '@/lib/api/generated'
import type { Customer, CustomerInput } from '@/lib/api/generated'
import { getTenantOrThrow, humanizeError, humanizeValidationError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

export interface CustomerMutationResult {
  ok: boolean
  customer?: Customer
  errorMessage?: string
}

export interface CustomerDeleteResult {
  ok: boolean
  errorMessage?: string
}

export const useCustomersStore = defineStore('customers', () => {
  const authStore = useAuthStore()

  const saving = ref(false)
  const deleting = ref(false)

  async function create(payload: CustomerInput): Promise<CustomerMutationResult> {
    if (saving.value) return { ok: false, errorMessage: 'Operacion en curso.' }
    saving.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await createCustomer({
        headers: { 'X-Tenant': tenant },
        body: payload,
      })
      if (error !== undefined || data === undefined) {
        return { ok: false, errorMessage: humanizeValidationError(error, 'No se pudo crear el cliente.') }
      }
      return { ok: true, customer: data.data }
    } catch {
      return { ok: false, errorMessage: 'Error inesperado al crear el cliente.' }
    } finally {
      saving.value = false
    }
  }

  async function update(uuid: string, payload: CustomerInput): Promise<CustomerMutationResult> {
    if (saving.value) return { ok: false, errorMessage: 'Operacion en curso.' }
    saving.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await updateCustomer({
        headers: { 'X-Tenant': tenant },
        path: { uuid },
        body: payload,
      })
      if (error !== undefined || data === undefined) {
        return { ok: false, errorMessage: humanizeValidationError(error, 'No se pudo actualizar el cliente.') }
      }
      return { ok: true, customer: data.data }
    } catch {
      return { ok: false, errorMessage: 'Error inesperado al actualizar el cliente.' }
    } finally {
      saving.value = false
    }
  }

  async function remove(uuid: string): Promise<CustomerDeleteResult> {
    if (deleting.value) return { ok: false, errorMessage: 'Operacion en curso.' }
    deleting.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { error } = await deleteCustomer({
        headers: { 'X-Tenant': tenant },
        path: { uuid },
      })
      if (error !== undefined) {
        // Incluye 409 CUSTOMER_HAS_BALANCE: cliente con saldo deudor.
        return { ok: false, errorMessage: humanizeError(error, 'No se pudo eliminar el cliente.') }
      }
      return { ok: true }
    } catch {
      return { ok: false, errorMessage: 'Error inesperado al eliminar el cliente.' }
    } finally {
      deleting.value = false
    }
  }

  return { saving, deleting, create, update, remove }
})
