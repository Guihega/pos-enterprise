/**
 * Store de escritura del catalogo de productos.
 *
 * El LISTADO y la busqueda viven en el composable useProducts (paginacion,
 * debounce). Este store se enfoca en las mutaciones: crear, actualizar y
 * eliminar. Sigue el patron { ok, ... } de cashSession/sales para que la
 * vista decida que hacer segun el resultado sin acoplarse a la API.
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { createProduct, updateProduct, deleteProduct } from '@/lib/api/generated'
import type { Product, StoreProductRequest, UpdateProductRequest } from '@/lib/api/generated'
import { getTenantOrThrow, humanizeError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

export interface ProductMutationResult {
  ok: boolean
  product?: Product
  errorMessage?: string
}

export interface ProductDeleteResult {
  ok: boolean
  errorMessage?: string
}

export const useProductsStore = defineStore('products', () => {
  const authStore = useAuthStore()

  const saving = ref(false)
  const deleting = ref(false)

  async function create(payload: StoreProductRequest): Promise<ProductMutationResult> {
    if (saving.value) return { ok: false, errorMessage: 'Operacion en curso.' }
    saving.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await createProduct({
        headers: { 'X-Tenant': tenant },
        body: payload,
      })
      if (error !== undefined || data === undefined) {
        return { ok: false, errorMessage: humanizeError(error, 'No se pudo crear el producto.') }
      }
      return { ok: true, product: data.data }
    } catch {
      return { ok: false, errorMessage: 'Error inesperado al crear el producto.' }
    } finally {
      saving.value = false
    }
  }

  async function update(uuid: string, payload: UpdateProductRequest): Promise<ProductMutationResult> {
    if (saving.value) return { ok: false, errorMessage: 'Operacion en curso.' }
    saving.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await updateProduct({
        headers: { 'X-Tenant': tenant },
        path: { uuid },
        body: payload,
      })
      if (error !== undefined || data === undefined) {
        return { ok: false, errorMessage: humanizeError(error, 'No se pudo actualizar el producto.') }
      }
      return { ok: true, product: data.data }
    } catch {
      return { ok: false, errorMessage: 'Error inesperado al actualizar el producto.' }
    } finally {
      saving.value = false
    }
  }

  async function remove(uuid: string): Promise<ProductDeleteResult> {
    if (deleting.value) return { ok: false, errorMessage: 'Operacion en curso.' }
    deleting.value = true
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { error } = await deleteProduct({
        headers: { 'X-Tenant': tenant },
        path: { uuid },
      })
      if (error !== undefined) {
        return { ok: false, errorMessage: humanizeError(error, 'No se pudo eliminar el producto.') }
      }
      return { ok: true }
    } catch {
      return { ok: false, errorMessage: 'Error inesperado al eliminar el producto.' }
    } finally {
      deleting.value = false
    }
  }

  return { saving, deleting, create, update, remove }
})
