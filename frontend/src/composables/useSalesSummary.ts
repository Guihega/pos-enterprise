import { ref, watch } from 'vue'
import { getSalesSummary } from '@/lib/api/generated'
import type { SalesSummary } from '@/lib/api/generated'
import { humanizeError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

/**
 * Resumen de ventas de un dia. Alimenta el dashboard/reporte del dia.
 * Filtros reactivos: date (YYYY-MM-DD) y branchUuid. Al cambiar cualquiera,
 * recarga. Sin onMounted interno: exponer init() para testeabilidad.
 */
function todayIsoDate(): string {
  const now = new Date()
  const y = now.getFullYear()
  const m = String(now.getMonth() + 1).padStart(2, '0')
  const d = String(now.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

export function useSalesSummary() {
  const authStore = useAuthStore()

  const date = ref<string>(todayIsoDate())
  const branchUuid = ref<string>('')

  const summary = ref<SalesSummary | null>(null)
  const loading = ref(false)
  const errorMessage = ref<string | null>(null)

  async function load(): Promise<void> {
    const tenant = authStore.tenant
    if (!tenant) {
      errorMessage.value = 'Sesion no inicializada.'
      return
    }

    loading.value = true
    errorMessage.value = null

    const query: { date?: string; branch_uuid?: string } = { date: date.value }
    if (branchUuid.value !== '') {
      query.branch_uuid = branchUuid.value
    }

    const { data, error } = await getSalesSummary({
      headers: { 'X-Tenant': tenant },
      query,
    })

    if (error || !data) {
      errorMessage.value = humanizeError(error, 'No se pudo cargar el resumen de ventas.')
      summary.value = null
    } else {
      summary.value = data.data
    }

    loading.value = false
  }

  // Al cambiar fecha o sucursal, recargar.
  watch([date, branchUuid], () => {
    void load()
  })

  /**
   * Carga inicial. Llamar desde onMounted del componente. Separado para
   * que el composable sea testeable sin un componente Vue real.
   */
  async function init(): Promise<void> {
    await load()
  }

  return {
    init,
    load,
    date,
    branchUuid,
    summary,
    loading,
    errorMessage,
  }
}
