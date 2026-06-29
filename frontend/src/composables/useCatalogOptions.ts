/**
 * Composable que carga las opciones auxiliares del catalogo (categorias,
 * marcas, unidades, impuestos) para poblar los selects del formulario de
 * producto.
 *
 * Las 4 listas se cargan en paralelo con un solo init(). init() esta
 * separado (no onMounted interno) para que sea testeable sin un componente.
 */
import { ref } from 'vue'
import { listCategories, listBrands, listUnits, listTaxes } from '@/lib/api/generated'
import type { Category, Brand, Unit, Tax } from '@/lib/api/generated'
import { getTenantOrThrow, humanizeError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

/** Cargamos hasta 100 de cada uno (el maximo permitido por pagina). */
const PER_PAGE = 100

export function useCatalogOptions() {
  const authStore = useAuthStore()

  const categories = ref<Category[]>([])
  const brands = ref<Brand[]>([])
  const units = ref<Unit[]>([])
  const taxes = ref<Tax[]>([])
  const loading = ref(false)
  const errorMessage = ref<string | null>(null)

  async function init(): Promise<void> {
    loading.value = true
    errorMessage.value = null
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const headers = { 'X-Tenant': tenant }
      const query = { per_page: PER_PAGE }

      const [catRes, brandRes, unitRes, taxRes] = await Promise.all([
        listCategories({ headers, query }),
        listBrands({ headers, query }),
        listUnits({ headers, query }),
        listTaxes({ headers, query }),
      ])

      const firstError =
        catRes.error ?? brandRes.error ?? unitRes.error ?? taxRes.error
      if (firstError !== undefined) {
        errorMessage.value = humanizeError(firstError, 'No se pudieron cargar las opciones del catalogo.')
        return
      }

      categories.value = catRes.data?.data ?? []
      brands.value = brandRes.data?.data ?? []
      units.value = unitRes.data?.data ?? []
      taxes.value = taxRes.data?.data ?? []
    } catch {
      errorMessage.value = 'Error inesperado al cargar las opciones del catalogo.'
    } finally {
      loading.value = false
    }
  }

  return { categories, brands, units, taxes, loading, errorMessage, init }
}
