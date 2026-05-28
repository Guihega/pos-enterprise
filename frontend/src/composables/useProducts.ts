/**
 * Composable de busqueda y listado de productos.
 *
 * Maneja:
 *  - Termino de busqueda con debounce de 300ms.
 *  - Estado de items, loading, error y "hay mas paginas".
 *  - Carga inicial al montar.
 *  - Cargar pagina siguiente con `loadMore()`.
 *  - Reintento manual con `retry()`.
 *
 * Lee el tenant del store de auth automaticamente; si no hay sesion,
 * las llamadas no se hacen (el guard del router protege la ruta del
 * POS, asi que en condiciones normales esto no pasa).
 */
import { ref, watch } from 'vue'
import { refDebounced } from '@vueuse/core'
import { listProducts } from '@/lib/api/generated'
import type { Product } from '@/lib/api/generated'
import { useAuthStore } from '@/stores/auth'

/** Tamano de pagina. El backend permite hasta 100. */
const PER_PAGE = 20

/** Debounce del input de busqueda, en ms. */
const SEARCH_DEBOUNCE_MS = 300

export function useProducts() {
  const authStore = useAuthStore()

  const searchTerm = ref('')
  const debouncedTerm = refDebounced(searchTerm, SEARCH_DEBOUNCE_MS)

  const items = ref<Product[]>([])
  const loading = ref(false)
  const loadingMore = ref(false)
  const errorMessage = ref<string | null>(null)
  const currentPage = ref(1)
  const lastPage = ref(1)
  const total = ref(0)
  const hasMore = ref(false)

  /**
   * Carga una pagina. Si `append` es true, agrega al final; si false,
   * reemplaza la lista (caso busqueda nueva).
   */
  async function fetchPage(page: number, append: boolean): Promise<void> {
    const tenantSlug = authStore.tenant
    if (!tenantSlug) {
      // Defensivo. En condiciones normales, el guard del router
      // ya garantizo que hay sesion antes de montar el POS.
      errorMessage.value = 'Sesion no inicializada.'
      return
    }

    if (append) {
      loadingMore.value = true
    } else {
      loading.value = true
      errorMessage.value = null
    }

    const term = debouncedTerm.value.trim()

    const { data, error } = await listProducts({
      headers: { 'X-Tenant': tenantSlug },
      query: {
        ...(term ? { q: term } : {}),
        per_page: PER_PAGE,
        sort: 'name',
        direction: 'asc',
      },
    })

    if (error || !data) {
      errorMessage.value = humanizeError(error)
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

  // Cuando cambia el termino debounceado, reiniciar la busqueda.
  watch(debouncedTerm, () => {
    currentPage.value = 1
    void fetchPage(1, false)
  })

  /**
   * Carga inicial. Llamar desde el `onMounted` del componente que use
   * este composable. Separado de la definicion del composable para que
   * el composable sea testeable sin necesitar un componente Vue real.
   */
  async function init(): Promise<void> {
    await fetchPage(1, false)
  }

  return {
    init,
    searchTerm,
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

/**
 * Convierte el error del SDK en un mensaje legible. Sigue el shape de
 * ErrorEnvelope del backend.
 */
function humanizeError(err: unknown): string {
  if (err && typeof err === 'object' && 'error' in err) {
    const errObj = (err as { error?: { message?: string } }).error
    if (errObj?.message) {
      return errObj.message
    }
  }
  return 'No se pudo cargar el catalogo. Intenta de nuevo.'
}
