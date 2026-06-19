/**
 * BackgroundSync — scheduler del SyncEngine.
 *
 * Doc maestro sec. 35.4 paso 6 (listener de conectividad) y paso 8
 * (iniciar Sync Engine en background), sec. 38.5 (pull cada 5 min si
 * online, o tras push exitoso).
 *
 * Responsabilidad UNICA: decidir CUANDO ejecutar SyncEngine.syncOnce():
 *   - Al arrancar (start), si esta online: sync inmediato.
 *   - Periodicamente cada SYNC_INTERVAL_MS mientras este online.
 *   - Al recuperar conexion (evento 'online'): sync inmediato.
 *   - Al perder conexion (evento 'offline'): pausa el intervalo.
 *
 * NO drena la cola ni descarga cambios directamente (eso es PushQueue /
 * PullStream, orquestados por SyncEngine). NO maneja el cache de red HTTP
 * (eso es el Service Worker con Workbox, sec. 37 — capa distinta).
 *
 * Es framework-agnostico: recibe la fuente de conectividad y el scheduler
 * por inyeccion para poder testearse con fake timers sin tocar window.
 */

import type { SyncEngine, SyncResult } from '@/sync/SyncEngine'

/**
 * Sonda de conectividad real. HeartbeatClient lo implementa (ping()).
 * Se usa para detectar 'online pero servidor caido' cuando la cola de
 * sync esta vacia y syncOnce no genera trafico de red (estado degraded,
 * sec. 35.5). Opcional: si no se inyecta, degraded se deriva solo del
 * networkError del SyncResult.
 */
export interface HeartbeatProbe {
  ping(): Promise<unknown>
}

// ---------------------------------------------------------------------------
// Parametros (sec. 38.5)
// ---------------------------------------------------------------------------

/** Pull cada 5 min si online (sec. 38.5). */
export const SYNC_INTERVAL_MS = 5 * 60 * 1000

// ---------------------------------------------------------------------------
// Abstraccion de conectividad (inyectable para tests)
// ---------------------------------------------------------------------------

export interface ConnectivitySource {
  /** True si hay conexion de red en este momento. */
  isOnline(): boolean
  /** Registra un listener de cambio a online. Devuelve funcion de limpieza. */
  onOnline(handler: () => void): () => void
  /** Registra un listener de cambio a offline. Devuelve funcion de limpieza. */
  onOffline(handler: () => void): () => void
}

/**
 * Implementacion por defecto basada en navigator + window events.
 * Solo se usa en runtime; los tests inyectan una fuente fake.
 */
export function browserConnectivity(): ConnectivitySource {
  return {
    isOnline: () => navigator.onLine,
    onOnline: (handler) => {
      window.addEventListener('online', handler)
      return () => window.removeEventListener('online', handler)
    },
    onOffline: (handler) => {
      window.addEventListener('offline', handler)
      return () => window.removeEventListener('offline', handler)
    },
  }
}

// ---------------------------------------------------------------------------
// Abstraccion de scheduler (inyectable para fake timers)
// ---------------------------------------------------------------------------

export interface Scheduler {
  setInterval(handler: () => void, ms: number): number
  clearInterval(id: number): void
}

/** Scheduler por defecto basado en los timers globales. */
export const defaultScheduler: Scheduler = {
  setInterval: (handler, ms) => globalThis.setInterval(handler, ms) as unknown as number,
  clearInterval: (id) => globalThis.clearInterval(id),
}

// ---------------------------------------------------------------------------
// Eventos
// ---------------------------------------------------------------------------

export type BackgroundSyncEvent =
  | { type: 'bgsync.started' }
  | { type: 'bgsync.stopped' }
  | { type: 'bgsync.online' }
  | { type: 'bgsync.offline' }
  | { type: 'bgsync.tick'; result: SyncResult }
  | { type: 'bgsync.degraded' }
  | { type: 'bgsync.recovered' }
  | { type: 'bgsync.error'; error: string }

export type BackgroundSyncListener = (event: BackgroundSyncEvent) => void

// ---------------------------------------------------------------------------
// Opciones
// ---------------------------------------------------------------------------

export interface BackgroundSyncOptions {
  engine:        SyncEngine
  connectivity?: ConnectivitySource
  scheduler?:    Scheduler
  intervalMs?:   number
  onEvent?:      BackgroundSyncListener
  /** Sonda opcional para detectar degraded con cola vacia (sec. 35.5). */
  heartbeat?:    HeartbeatProbe
}

// ---------------------------------------------------------------------------
// BackgroundSync
// ---------------------------------------------------------------------------

export class BackgroundSync {
  private engine:       SyncEngine
  private connectivity: ConnectivitySource
  private scheduler:    Scheduler
  private intervalMs:   number
  private onEvent?:     BackgroundSyncListener
  private heartbeat?:   HeartbeatProbe

  private intervalId:   number | null = null
  private cleanupFns:   Array<() => void> = []
  private running       = false
  /** Evita ciclos solapados si uno tarda mas que el intervalo. */
  private syncing       = false
  /** True si el servidor no responde pese a navigator.onLine (35.5). */
  private degraded      = false

  constructor(opts: BackgroundSyncOptions) {
    this.engine       = opts.engine
    this.connectivity = opts.connectivity ?? browserConnectivity()
    this.scheduler    = opts.scheduler ?? defaultScheduler
    this.intervalMs   = opts.intervalMs ?? SYNC_INTERVAL_MS
    this.onEvent      = opts.onEvent
    this.heartbeat    = opts.heartbeat
  }

  /**
   * Arranca el scheduler (idempotente). Registra listeners de conectividad,
   * y si esta online: lanza un sync inmediato y arma el intervalo.
   */
  start(): void {
    if (this.running) return
    this.running = true

    this.cleanupFns.push(this.connectivity.onOnline(() => this.handleOnline()))
    this.cleanupFns.push(this.connectivity.onOffline(() => this.handleOffline()))

    this.emit({ type: 'bgsync.started' })

    if (this.connectivity.isOnline()) {
      this.startInterval()
      void this.tick()
    }
  }

  /**
   * Detiene el scheduler (idempotente). Limpia intervalo y listeners.
   * Un ciclo en curso termina solo; no se cancela a la mitad.
   */
  stop(): void {
    if (!this.running) return
    this.running = false

    this.stopInterval()
    for (const fn of this.cleanupFns) fn()
    this.cleanupFns = []

    this.emit({ type: 'bgsync.stopped' })
  }

  /** True si el scheduler esta activo. */
  isRunning(): boolean {
    return this.running
  }

  /** True si el servidor no responde pese a estar online (35.5). */
  isDegraded(): boolean {
    return this.degraded
  }

  // -------------------------------------------------------------------------
  // Conectividad
  // -------------------------------------------------------------------------

  private handleOnline(): void {
    if (!this.running) return
    this.emit({ type: 'bgsync.online' })
    this.startInterval()
    void this.tick() // sync inmediato al recuperar conexion
  }

  private handleOffline(): void {
    if (!this.running) return
    this.emit({ type: 'bgsync.offline' })
    this.stopInterval() // pausa el polling; SyncEngine no se llama sin red
    // offline es un estado distinto de degraded: al perder la red dejamos
    // de considerar 'servidor caido' (no hay forma de saberlo sin red).
    this.degraded = false
  }

  // -------------------------------------------------------------------------
  // Intervalo
  // -------------------------------------------------------------------------

  private startInterval(): void {
    if (this.intervalId !== null) return
    this.intervalId = this.scheduler.setInterval(() => {
      void this.tick()
    }, this.intervalMs)
  }

  private stopInterval(): void {
    if (this.intervalId === null) return
    this.scheduler.clearInterval(this.intervalId)
    this.intervalId = null
  }

  // -------------------------------------------------------------------------
  // Ciclo de sync
  // -------------------------------------------------------------------------

  /**
   * Ejecuta un ciclo de SyncEngine.syncOnce(), evitando solapamiento.
   * No lanza: los errores se emiten como evento bgsync.error.
   */
  private async tick(): Promise<void> {
    if (this.syncing) return
    if (!this.connectivity.isOnline()) return

    this.syncing = true
    try {
      const result = await this.engine.syncOnce()
      this.emit({ type: 'bgsync.tick', result })
      await this.evaluateHealth(result)
    } catch (err) {
      const error = err instanceof Error ? err.message : 'sync error'
      this.emit({ type: 'bgsync.error', error })
      // Una excepcion en syncOnce tambien es senal de servidor inalcanzable.
      this.markDegraded(true)
    } finally {
      this.syncing = false
    }
  }

  // -------------------------------------------------------------------------
  // Salud de conexion (sec. 35.5: degraded)
  // -------------------------------------------------------------------------

  /**
   * Decide si la conexion esta degradada a partir del resultado del sync.
   *   - Si push o pull reportaron networkError => degraded (servidor caido).
   *   - Si el ciclo no genero trafico (cola vacia, sin cambios) y hay sonda
   *     heartbeat inyectada => hace un ping para confirmar conectividad real.
   *   - En otro caso => sano (recovered si venia degradado).
   */
  private async evaluateHealth(result: SyncResult): Promise<void> {
    if (result.push.networkError || result.pull.networkError) {
      this.markDegraded(true)
      return
    }

    const noTraffic = result.push.sent === 0
    if (noTraffic && this.heartbeat) {
      try {
        await this.heartbeat.ping()
        this.markDegraded(false)
      } catch {
        this.markDegraded(true)
      }
      return
    }

    this.markDegraded(false)
  }

  /** Actualiza el estado degraded y emite la transicion si cambio. */
  private markDegraded(value: boolean): void {
    if (value === this.degraded) return
    this.degraded = value
    this.emit({ type: value ? 'bgsync.degraded' : 'bgsync.recovered' })
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  private emit(event: BackgroundSyncEvent): void {
    this.onEvent?.(event)
  }
}
