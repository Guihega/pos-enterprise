/**
 * Composable de listado de existencias (stock) para la vista de inventario.
 *
 * A diferencia de useStock (que arma un mapa uuid->disponible para el POS),
 * este expone la lista paginada completa de existencias con sus cantidades
 * y umbrales, filtrable por almacen. Patron de paginacion espejo de
 * useProducts/useCustomers: items, loading, loadMore, retry, init().
 *
 * El filtro de almacen se controla con `warehouseUuid` (ref). Al cambiar,
 * reinicia la lista. No hay busqueda libre: el endpoint stocks no expone `q`.
 */
import { ref, watch } from 'vue'
import { listInventoryStocks } from '@/lib/api/generated'
import type { Stock } from '@/lib/api/generated'
import { humanizeError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

const PER_PAGE = 50

export function useInventory() {
  const authStore = useAuthStore()

  const warehouseUuid = ref<string>('')
  const lowStockOnly = ref(false)

  const items = ref<Stock[]>([])
  const loading = ref(false)
  const loadingMore = ref(false)
  const errorMessage = ref<string | null>(null)
  const currentPage = ref(1)
  const lastPage = ref(1)
  const total = ref(0)
  const hasMore = ref(false)

  async function fetchPage(page: number, append: boolean): Promise<void> {
    const tenant = authStore.tenant
    if (!tenant) {
      errorMessage.value = 'Sesion no inicializada.'
      return
    }

    if (append) {
      loadingMore.value = true
    } else {
      loading.value = true
      errorMessage.value = null
    }

    const { data, error } = await listInventoryStocks({
      headers: { 'X-Tenant': tenant },
      query: {
        ...(warehouseUuid.value ? { warehouse: warehouseUuid.value } : {}),
        ...(lowStockOnly.value ? { low_stock: true } : {}),
        per_page: PER_PAGE,
      },
    })

    if (error || !data) {
      errorMessage.value = humanizeError(error, 'No se pudo cargar el inventario. Intenta de nuevo.')
      loading.value = false
      loadingMore.value = false
      return
    }

    if (append) {
      items.value = [...items.value, ...data.data]
    } else {
      items.value = data.data
    }

    currentPage.value = data.meta.current_page
    lastPage.value = data.meta.last_page
    total.value = data.meta.total
    hasMore.value = data.meta.current_page < data.meta.last_page

    loading.value = false
    loadingMore.value = false
  }

  async function loadMore(): Promise<void> {
    if (!hasMore.value || loading.value || loadingMore.value) {
      return
    }
    await fetchPage(currentPage.value + 1, true)
  }

  async function retry(): Promise<void> {
    await fetchPage(1, false)
  }

  // Al cambiar almacen o el filtro de stock bajo, reiniciar la lista.
  watch([warehouseUuid, lowStockOnly], () => {
    currentPage.value = 1
    void fetchPage(1, false)
  })

  /**
   * Carga inicial. Llamar desde onMounted del componente. Separado para
   * que el composable sea testeable sin un componente Vue real.
   */
  async function init(): Promise<void> {
    await fetchPage(1, false)
  }

  return {
    init,
    warehouseUuid,
    lowStockOnly,
    items,
    loading,
    loadingMore,
    errorMessage,
    hasMore,
    total,
    loadMore,
    retry,
  }
}
