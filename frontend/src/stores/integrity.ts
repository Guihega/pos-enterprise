import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { IntegrityService } from '@/sync/IntegrityService'
import { HeartbeatClient } from '@/sync/HeartbeatClient'
import type { DriftSeverity } from '@/sync/HeartbeatClient'

/**
 * Store de integridad y salud del cliente offline.
 *
 * Cubre dos chequeos del arranque/login definidos en el doc maestro:
 *  - checkIntegrity (35.4 paso 4, 42.3): detecta IndexedDB corrupta al
 *    arrancar. Si falla, isCorrupt=true y la UI (App.vue) muestra el modal
 *    de recuperacion (IntegrityRecoveryModal).
 *  - clock drift (42.5): al login mide el desfase del reloj contra el
 *    servidor. driftSeverity 'warning' (>5min) o 'blocked' (>30min) la
 *    consume la UI para advertir o bloquear ventas.
 *
 * Solo orquesta y expone estado. La UX de cada flag se define en la capa UI.
 */
export interface IntegrityStoreDeps {
  makeIntegrity?: () => Pick<IntegrityService, 'checkIntegrity'>
  makeHeartbeat?: (tenantSlug: string) => Pick<HeartbeatClient, 'pingWithDrift'>
}

export const useIntegrityStore = defineStore('integrity', () => {
  // ---- state ----
  /** True si checkIntegrity detecto corrupcion en IndexedDB (42.3). */
  const isCorrupt = ref<boolean>(false)
  /** Mensaje del error de integridad, null si todo bien. */
  const integrityError = ref<string | null>(null)
  /** True mientras corre el chequeo de integridad. */
  const checking = ref<boolean>(false)

  /** Desfase del reloj en milisegundos (cliente - servidor), null si no medido. */
  const driftMs = ref<number | null>(null)
  /** Severidad del drift segun 42.5: 'ok' | 'warning' | 'blocked'. */
  const driftSeverity = ref<DriftSeverity | null>(null)

  // ---- getters ----
  /** El reloj exige advertencia al cajero (drift > 5min). */
  const driftWarning = computed(() => driftSeverity.value === 'warning')
  /** El reloj bloquea ventas (drift > 30min). */
  const driftBlocked = computed(() => driftSeverity.value === 'blocked')

  // ---- actions ----
  /**
   * Corre el chequeo de integridad de IndexedDB (35.4 paso 4). Idempotente.
   * No lanza: cualquier fallo se refleja en isCorrupt/integrityError.
   */
  async function check(deps: IntegrityStoreDeps = {}): Promise<void> {
    checking.value = true
    try {
      const service = deps.makeIntegrity ? deps.makeIntegrity() : new IntegrityService()
      const result = await service.checkIntegrity()
      isCorrupt.value = !result.ok
      integrityError.value = result.ok ? null : (result.error ?? 'IndexedDB no responde.')
    } catch (err) {
      // Si el propio chequeo lanza, asumimos corrupcion (defensa 42.3).
      isCorrupt.value = true
      integrityError.value = err instanceof Error ? err.message : 'Error al verificar la base local.'
    } finally {
      checking.value = false
    }
  }

  /**
   * Mide el desfase del reloj contra el servidor al login (42.5).
   * No lanza: ante error de red deja driftSeverity en null (no se pudo medir).
   *
   * @param tenantSlug tenant para el header X-Tenant del heartbeat
   */
  async function measureClockDrift(tenantSlug: string, deps: IntegrityStoreDeps = {}): Promise<void> {
    try {
      const client = deps.makeHeartbeat
        ? deps.makeHeartbeat(tenantSlug)
        : new HeartbeatClient({ tenantSlug })
      const { driftMs: ms, severity } = await client.pingWithDrift()
      driftMs.value = ms
      driftSeverity.value = severity
    } catch {
      // No medible (red caida): no afirmamos nada sobre el reloj.
      driftMs.value = null
      driftSeverity.value = null
    }
  }

  /** Marca la corrupcion como resuelta tras una restauracion exitosa. */
  function clearCorrupt(): void {
    isCorrupt.value = false
    integrityError.value = null
  }

  return {
    // state
    isCorrupt,
    integrityError,
    checking,
    driftMs,
    driftSeverity,
    // getters
    driftWarning,
    driftBlocked,
    // actions
    check,
    measureClockDrift,
    clearCorrupt,
  }
})
