/**
 * Composable de listado de almacenes para selectores.
 *
 * Carga todos los almacenes activos del tenant (paginando hasta 200 de una)
 * para poblar el <select> de almacen en la vista de inventario y el modal
 * de ajuste. Expone init() separado para testeabilidad.
 */
import { ref } from 'vue'
import { listWarehouses } from '@/lib/api/generated'
import type { Warehouse } from '@/lib/api/generated'
import { getTenantOrThrow, humanizeError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

const PER_PAGE = 200

export function useWarehouses() {
  const authStore = useAuthStore()

  const items = ref<Warehouse[]>([])
  const loading = ref(false)
  const errorMessage = ref<string | null>(null)

  async function init(): Promise<void> {
    loading.value = true
    errorMessage.value = null
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await listWarehouses({
        headers: { 'X-Tenant': tenant },
        query: { active: true, per_page: PER_PAGE },
      })
      if (error !== undefined || data === undefined) {
        errorMessage.value = humanizeError(error, 'No se pudieron cargar los almacenes.')
        return
      }
      items.value = data.data
    } catch {
      errorMessage.value = 'Error inesperado al cargar almacenes.'
    } finally {
      loading.value = false
    }
  }

  return { init, items, loading, errorMessage }
}
