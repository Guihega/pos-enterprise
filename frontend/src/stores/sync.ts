/**
 * Store de sincronizacion del POS (doc maestro sec. 35.3, 35.4 pasos 6-9).
 *
 * Punto de arranque del motor de sync en background. Conecta el auth
 * store (tenant) con SyncEngine + BackgroundSync y expone estado
 * reactivo para la UI (badges de pendientes/conflictos, indicador
 * online/offline, ultimo sync).
 *
 * Ciclo de vida:
 *   - start(): tras login/hydrate exitoso. Crea engine + scheduler,
 *     suscribe eventos y arranca. Idempotente.
 *   - stop(): en logout/forceLogout. Detiene el scheduler y descarta
 *     las instancias.
 *
 * NO drena la cola ni descarga cambios directamente: delega todo en
 * BackgroundSync -> SyncEngine. Solo traduce eventos a estado de UI.
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { SyncEngine } from '@/sync/SyncEngine'
import { BackgroundSync, type BackgroundSyncEvent } from '@/sync/BackgroundSync'
import { HeartbeatClient } from '@/sync/HeartbeatClient'
import { SnapshotService, type SnapshotProgress } from '@/sync/SnapshotService'
import { countByStatus } from '@/repositories/SyncQueueRepository'
import { countUnresolved } from '@/repositories/ConflictRepository'

export type SyncUiStatus = 'stopped' | 'idle' | 'syncing' | 'offline' | 'degraded' | 'blocked' | 'error'

export const useSyncStore = defineStore('sync', () => {
  // ---- state ----
  const status        = ref<SyncUiStatus>('stopped')
  const isOnline      = ref<boolean>(true)
  const lastSyncAt    = ref<string | null>(null)
  const lastError     = ref<string | null>(null)
  const pendingCount  = ref<number>(0)
  const conflictCount = ref<number>(0)
  /** True mientras corre el snapshot inicial (38.6). */
  const snapshotInProgress = ref<boolean>(false)
  /** Ultimo progreso reportado por el snapshot, null si no aplica. */
  const snapshotProgress = ref<SnapshotProgress | null>(null)

  // Instancia activa del scheduler. null cuando esta detenido.
  let bgsync: BackgroundSync | null = null

  // ---- getters ----
  const isRunning = computed(() => bgsync !== null)
  const hasPending = computed(() => pendingCount.value > 0)
  const hasConflicts = computed(() => conflictCount.value > 0)
  const isDegraded = computed(() => status.value === 'degraded')
  const isBlocked = computed(() => status.value === 'blocked')

  // ---- helpers ----

  /** Refresca los contadores de UI desde IndexedDB. */
  async function refreshCounts(): Promise<void> {
    pendingCount.value  = await countByStatus('pending')
    conflictCount.value = await countUnresolved()
  }

  /** Traduce los eventos del scheduler a estado reactivo de UI. */
  async function handleEvent(event: BackgroundSyncEvent): Promise<void> {
    switch (event.type) {
      case 'bgsync.started':
        status.value = 'idle'
        break
      case 'bgsync.stopped':
        status.value = 'stopped'
        break
      case 'bgsync.online':
        isOnline.value = true
        if (status.value === 'offline') status.value = 'idle'
        break
      case 'bgsync.offline':
        isOnline.value = false
        status.value = 'offline'
        break
      case 'bgsync.degraded':
        // Online a nivel de red pero el servidor no responde (sec. 35.5).
        // No tocar isOnline: navigator sigue reportando conexion. blocked
        // tiene prioridad (suspension es mas grave que degradacion).
        if (status.value !== 'offline' && status.value !== 'blocked') {
          status.value = 'degraded'
        }
        break
      case 'bgsync.recovered':
        if (status.value === 'degraded') status.value = 'idle'
        break
      case 'bgsync.blocked':
        // Tenant suspendido (HTTP 402, sec. 35.5). Estado terminal: la UI
        // pasa a solo-lectura. Tiene prioridad sobre cualquier otro estado.
        status.value = 'blocked'
        break
      case 'bgsync.unblocked':
        if (status.value === 'blocked') status.value = 'idle'
        break
      case 'bgsync.tick':
        lastSyncAt.value = new Date().toISOString()
        lastError.value = null
        // tick limpio vuelve a idle salvo que estemos offline o degraded;
        // si el ciclo detecto degradacion, el evento bgsync.degraded llega
        // despues de este tick y deja el estado correcto.
        if (
          status.value !== 'offline' &&
          status.value !== 'degraded' &&
          status.value !== 'blocked'
        ) {
          status.value = 'idle'
        }
        await refreshCounts()
        break
      case 'bgsync.error':
        lastError.value = event.error
        status.value = 'error'
        break
    }
  }

  // ---- actions ----

  /**
   * Repuebla IndexedDB con el catalogo completo si hace falta (38.6,
   * 35.4 paso 5: nunca sincronizado, sin datos, o ultimo sync > 7 dias).
   * No bloquea el arranque: reporta progreso a estado reactivo para que
   * la UI muestre "Cargando catalogo: X/Y". Idempotente y no lanza: los
   * errores quedan en lastError.
   *
   * @param deps  Inyeccion opcional para tests (SnapshotService fake).
   */
  async function ensureSnapshot(deps?: {
    makeSnapshot?: (
      tenantSlug: string,
      onProgress: (p: SnapshotProgress) => void,
    ) => Pick<SnapshotService, 'needsSnapshot' | 'run'>
  }): Promise<void> {
    if (snapshotInProgress.value) return

    const auth = useAuthStore()
    const tenantSlug = auth.tenant
    if (!tenantSlug) return

    const onProgress = (p: SnapshotProgress) => { snapshotProgress.value = p }
    const service = deps?.makeSnapshot
      ? deps.makeSnapshot(tenantSlug, onProgress)
      : new SnapshotService({ tenantSlug, onProgress })

    try {
      if (!(await service.needsSnapshot())) return
      snapshotInProgress.value = true
      await service.run()
      await refreshCounts()
    } catch (err) {
      lastError.value = err instanceof Error ? err.message : 'snapshot error'
    } finally {
      snapshotInProgress.value = false
    }
  }

  /**
   * Arranca el motor de sync para el tenant activo. Idempotente: si ya
   * esta corriendo, no hace nada. Requiere sesion (tenant no nulo).
   *
   * @param deps  Inyeccion opcional para tests (engine/scheduler fakes).
   */
  function start(deps?: {
    makeEngine?:   (tenantSlug: string) => SyncEngine
    makeBgSync?:   (engine: SyncEngine, onEvent: (e: BackgroundSyncEvent) => void) => BackgroundSync
  }): void {
    if (bgsync !== null) return

    const auth = useAuthStore()
    const tenantSlug = auth.tenant
    if (!tenantSlug) return // sin sesion no hay nada que sincronizar

    const engine = deps?.makeEngine
      ? deps.makeEngine(tenantSlug)
      : new SyncEngine({ tenantSlug })

    const onEvent = (e: BackgroundSyncEvent) => { void handleEvent(e) }

    bgsync = deps?.makeBgSync
      ? deps.makeBgSync(engine, onEvent)
      : new BackgroundSync({
          engine,
          onEvent,
          // Sonda para detectar degraded cuando la cola esta vacia (35.5).
          heartbeat: new HeartbeatClient({ tenantSlug }),
        })

    bgsync.start()
    void refreshCounts()
  }

  /**
   * Detiene el motor de sync y descarta la instancia. Idempotente.
   * El estado de contadores se conserva para la UI hasta el proximo start.
   */
  function stop(): void {
    if (bgsync === null) return
    bgsync.stop()
    bgsync = null
    status.value = 'stopped'
  }

  return {
    // state
    status,
    isOnline,
    lastSyncAt,
    lastError,
    pendingCount,
    conflictCount,
    snapshotInProgress,
    snapshotProgress,
    // getters
    isRunning,
    hasPending,
    hasConflicts,
    isDegraded,
    isBlocked,
    // actions
    start,
    stop,
    ensureSnapshot,
    refreshCounts,
  }
})
