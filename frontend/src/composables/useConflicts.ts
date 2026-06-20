/**
 * Composable de la cola de conflictos para humanos (doc maestro 39.3).
 *
 * Encapsula la logica testeable:
 *  - cargar conflictos no resueltos (FIFO por detectedAt)
 *  - determinar si el usuario actual puede resolver (solo gerente o admin, 39.3)
 *  - resolver manualmente (use_client / use_server) marcando auditoria
 *
 * La UI (ConflictsView) solo refleja este estado. No define reglas nuevas.
 */
import { computed, ref } from 'vue'
import { getUnresolved, markResolved } from '@/repositories/ConflictRepository'
import { useAuthStore } from '@/stores/auth'
import { useSyncStore } from '@/stores/sync'
import type { ConflictLocal, ConflictResolutionKind } from '@/db/schema'

/**
 * Roles autorizados a resolver conflictos manualmente (39.3: "solo gerente
 * o admin"). Coinciden con las constantes del backend (Roles.php):
 * super_admin / admin / gerente.
 */
const RESOLVER_ROLES = ['super_admin', 'admin', 'gerente'] as const

export function useConflicts() {
  const auth = useAuthStore()
  const sync = useSyncStore()

  const items = ref<ConflictLocal[]>([])
  const loading = ref<boolean>(false)
  const errorMessage = ref<string | null>(null)
  /** uuid del conflicto que se esta resolviendo, o null. */
  const resolvingUuid = ref<string | null>(null)

  /**
   * True si el usuario en sesion tiene alguno de los roles autorizados.
   * Si no hay usuario, false (sin permiso).
   */
  const canResolve = computed<boolean>(() => {
    const roles = auth.user?.roles ?? []
    return roles.some((r) => (RESOLVER_ROLES as readonly string[]).includes(r))
  })

  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  /** Carga la cola de conflictos no resueltos desde IndexedDB. */
  async function load(): Promise<void> {
    loading.value = true
    errorMessage.value = null
    try {
      items.value = await getUnresolved()
    } catch (err) {
      errorMessage.value = err instanceof Error ? err.message : 'No se pudieron cargar los conflictos.'
    } finally {
      loading.value = false
    }
  }

  /**
   * Resuelve un conflicto manualmente con la decision indicada.
   * Bloquea si el usuario no tiene permiso (defensa en profundidad: la UI
   * tambien oculta los botones).
   *
   * @param uuid       conflicto a resolver
   * @param resolution 'use_client' (mantener mio) | 'use_server' (aceptar el otro)
   */
  async function resolveManual(
    uuid: string,
    resolution: Extract<ConflictResolutionKind, 'use_client' | 'use_server'>,
  ): Promise<boolean> {
    if (!canResolve.value) {
      errorMessage.value = 'No tienes permiso para resolver conflictos. Requiere gerente o administrador.'
      return false
    }
    resolvingUuid.value = uuid
    errorMessage.value = null
    try {
      // auto=false: resolucion humana auditada (39.3 "Auditado").
      await markResolved(uuid, resolution, false)
      // Quitar de la lista local sin recargar todo.
      items.value = items.value.filter((c) => c.uuid !== uuid)
      // Refrescar el contador global (banner / store).
      await sync.refreshCounts()
      return true
    } catch (err) {
      errorMessage.value = err instanceof Error ? err.message : 'No se pudo resolver el conflicto.'
      return false
    } finally {
      resolvingUuid.value = null
    }
  }

  return {
    // state
    items,
    loading,
    errorMessage,
    resolvingUuid,
    // getters
    canResolve,
    isEmpty,
    // actions
    load,
    resolveManual,
  }
}
