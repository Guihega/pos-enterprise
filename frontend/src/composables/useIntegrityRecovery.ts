/**
 * Composable de recuperacion ante IndexedDB corrupta (doc maestro 42.3).
 *
 * Orquesta las dos acciones del flujo de recuperacion:
 *  1. Exportar datos pendientes (sync_queue + sales pending) a JSON descargable,
 *     para enviar a soporte ANTES de borrar (42.3 paso 2).
 *  2. Restaurar: borrar IndexedDB y repoblar via snapshot inicial (42.3 paso 3).
 *
 * La logica de export/restore vive en IntegrityService; el repoblado en
 * SnapshotService. Este composable solo los coordina y expone estado para la UI.
 */
import { ref } from 'vue'
import { IntegrityService } from '@/sync/IntegrityService'
import { SnapshotService } from '@/sync/SnapshotService'
import { useAuthStore } from '@/stores/auth'
import type { SnapshotProgress } from '@/sync/SnapshotService'

/** Dependencias inyectables para tests (fakes de los servicios). */
export interface IntegrityRecoveryDeps {
  makeIntegrity?: () => Pick<IntegrityService, 'exportPending' | 'restore'>
  makeSnapshot?: (tenantSlug: string, onProgress: (p: SnapshotProgress) => void) => Pick<SnapshotService, 'run'>
  /** Inyectable para tests; en runtime dispara la descarga del navegador. */
  triggerDownload?: (filename: string, json: string) => void
}

/** Descarga por defecto: crea un Blob y un enlace temporal. */
function defaultDownload(filename: string, json: string): void {
  const blob = new Blob([json], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

export function useIntegrityRecovery(deps: IntegrityRecoveryDeps = {}) {
  const auth = useAuthStore()

  const exporting = ref<boolean>(false)
  const restoring = ref<boolean>(false)
  const errorMessage = ref<string | null>(null)
  const exported = ref<boolean>(false)
  const restored = ref<boolean>(false)
  /** Progreso del repoblado tras restaurar, null si no aplica. */
  const restoreProgress = ref<SnapshotProgress | null>(null)

  const triggerDownload = deps.triggerDownload ?? defaultDownload

  function integrity() {
    return deps.makeIntegrity ? deps.makeIntegrity() : new IntegrityService()
  }

  /**
   * Exporta los datos pendientes a un JSON descargable. No borra nada.
   * Devuelve true si se exporto correctamente.
   */
  async function exportPending(): Promise<boolean> {
    exporting.value = true
    errorMessage.value = null
    try {
      const data = await integrity().exportPending()
      const stamp = new Date().toISOString().replace(/[:.]/g, '-')
      triggerDownload(`pos-pendientes-${stamp}.json`, JSON.stringify(data, null, 2))
      exported.value = true
      return true
    } catch (err) {
      errorMessage.value = err instanceof Error ? err.message : 'No se pudieron exportar los datos.'
      return false
    } finally {
      exporting.value = false
    }
  }

  /**
   * Restaura: borra IndexedDB y repobla con snapshot inicial.
   * Requiere tenant en sesion para el repoblado. Devuelve true si completo.
   */
  async function restore(): Promise<boolean> {
    const tenantSlug = auth.tenant
    if (!tenantSlug) {
      errorMessage.value = 'No hay sesion activa para repoblar los datos.'
      return false
    }
    restoring.value = true
    errorMessage.value = null
    restoreProgress.value = null
    try {
      await integrity().restore()
      const onProgress = (p: SnapshotProgress) => { restoreProgress.value = p }
      const snapshot = deps.makeSnapshot
        ? deps.makeSnapshot(tenantSlug, onProgress)
        : new SnapshotService({ tenantSlug, onProgress })
      await snapshot.run()
      restored.value = true
      return true
    } catch (err) {
      errorMessage.value = err instanceof Error ? err.message : 'No se pudo restaurar la base local.'
      return false
    } finally {
      restoring.value = false
    }
  }

  return {
    // state
    exporting,
    restoring,
    errorMessage,
    exported,
    restored,
    restoreProgress,
    // actions
    exportPending,
    restore,
  }
}
