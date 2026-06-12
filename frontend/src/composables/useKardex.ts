/**
 * Composable del kardex (historial de movimientos) de un producto.
 *
 * Se inicializa con el UUID del producto cuyo historial se quiere ver
 * (desde el modal de kardex). Lista movimientos paginados, ordenados del
 * mas reciente al mas antiguo (el backend ya ordena desc). Soporta filtro
 * opcional por almacen. init(productUuid) carga la primera pagina.
 */
import { ref } from 'vue'
import { listInventoryMovements } from '@/lib/api/generated'
import type { InventoryMovement } from '@/lib/api/generated'
import { humanizeError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

const PER_PAGE = 50

export function useKardex() {
  const authStore = useAuthStore()

  const productUuid = ref<string>('')
  const warehouseUuid = ref<string>('')

  const items = ref<InventoryMovement[]>([])
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
    if (!productUuid.value) {
      errorMessage.value = 'Producto no especificado.'
      return
    }

    if (append) {
      loadingMore.value = true
    } else {
      loading.value = true
      errorMessage.value = null
    }

    const { data, error } = await listInventoryMovements({
      headers: { 'X-Tenant': tenant },
      query: {
        product: productUuid.value,
        ...(warehouseUuid.value ? { warehouse: warehouseUuid.value } : {}),
        per_page: PER_PAGE,
      },
    })

    if (error || !data) {
      errorMessage.value = humanizeError(error, 'No se pudo cargar el historial. Intenta de nuevo.')
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

  /**
   * Carga el historial de un producto. Llamar al abrir el modal de kardex.
   * Opcionalmente filtra por almacen.
   */
  async function init(product: string, warehouse?: string): Promise<void> {
    productUuid.value = product
    warehouseUuid.value = warehouse ?? ''
    currentPage.value = 1
    await fetchPage(1, false)
  }

  return {
    init,
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
