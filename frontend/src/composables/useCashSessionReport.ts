import { ref } from 'vue'
import { getCashSessionReport } from '@/lib/api/generated'
import type { CashSessionReport } from '@/lib/api/generated'
import { humanizeError } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

/**
 * Corte de caja (X/Z) de una sesion puntual.
 *
 * Sin watch ni onMounted interno: se carga a demanda via load(sessionUuid),
 * llamado cuando el cajero abre el modal de Corte X (sesion abierta) o
 * Ver corte Z (sesion recien cerrada).
 */
export function useCashSessionReport() {
  const authStore = useAuthStore()

  const report = ref<CashSessionReport | null>(null)
  const loading = ref(false)
  const errorMessage = ref<string | null>(null)

  async function load(sessionUuid: string): Promise<void> {
    const tenant = authStore.tenant
    if (!tenant) {
      errorMessage.value = 'Sesion no inicializada.'
      return
    }

    loading.value = true
    errorMessage.value = null

    const { data, error } = await getCashSessionReport({
      headers: { 'X-Tenant': tenant },
      path: { session: sessionUuid },
    })

    if (error || !data) {
      errorMessage.value = humanizeError(error, 'No se pudo cargar el corte de caja.')
      report.value = null
    } else {
      report.value = data.data
    }

    loading.value = false
  }

  /** Limpia el state (al cerrar el modal). */
  function clear(): void {
    report.value = null
    errorMessage.value = null
  }

  return {
    report,
    loading,
    errorMessage,
    load,
    clear,
  }
}
