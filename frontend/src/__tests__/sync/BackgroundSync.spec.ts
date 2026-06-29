/**
 * Tests de BackgroundSync (Fase 2, Iteracion 2).
 *
 * ConnectivitySource y Scheduler se inyectan con fakes controlables.
 * SyncEngine se mockea (solo se verifica que syncOnce se llama cuando toca).
 * Usa vi.useFakeTimers solo para el caso de intervalo; el resto controla
 * el tiempo via el scheduler fake.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  BackgroundSync,
  SYNC_INTERVAL_MS,
  type ConnectivitySource,
  type Scheduler,
} from '@/sync/BackgroundSync'
import type { SyncEngine, SyncResult } from '@/sync/SyncEngine'

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

const ZERO = { created: 0, updated: 0, deleted: 0, skipped: 0 }
const EMPTY_RESULT: SyncResult = {
  push: { sent: 0, succeeded: 0, conflicts: 0, failed: 0, networkError: false },
  pull: {
    products: { ...ZERO }, taxes: { ...ZERO }, customers: { ...ZERO },
    snapshotTimestamp: '', networkError: false,
  },
}

/** Fuente de conectividad controlable manualmente. */
class FakeConnectivity implements ConnectivitySource {
  online: boolean
  private onlineHandlers:  Array<() => void> = []
  private offlineHandlers: Array<() => void> = []

  constructor(online = true) { this.online = online }

  isOnline() { return this.online }

  onOnline(handler: () => void) {
    this.onlineHandlers.push(handler)
    return () => {
      this.onlineHandlers = this.onlineHandlers.filter((h) => h !== handler)
    }
  }
  onOffline(handler: () => void) {
    this.offlineHandlers.push(handler)
    return () => {
      this.offlineHandlers = this.offlineHandlers.filter((h) => h !== handler)
    }
  }

  /** Simula recuperacion de conexion. */
  goOnline() {
    this.online = true
    this.onlineHandlers.forEach((h) => h())
  }
  /** Simula perdida de conexion. */
  goOffline() {
    this.online = false
    this.offlineHandlers.forEach((h) => h())
  }

  countOnlineListeners()  { return this.onlineHandlers.length }
  countOfflineListeners() { return this.offlineHandlers.length }
}

/** Scheduler controlable: ejecuta el handler bajo demanda. */
class FakeScheduler implements Scheduler {
  private handlers = new Map<number, () => void>()
  private nextId = 1

  setInterval(handler: () => void, _ms: number) {
    const id = this.nextId++
    this.handlers.set(id, handler)
    return id
  }
  clearInterval(id: number) {
    this.handlers.delete(id)
  }

  /** Dispara manualmente todos los intervalos activos. */
  tickAll() {
    for (const h of this.handlers.values()) h()
  }
  activeCount() { return this.handlers.size }
}

function makeEngine(syncOnce = vi.fn().mockResolvedValue(EMPTY_RESULT)) {
  return { syncOnce } as unknown as SyncEngine & { syncOnce: ReturnType<typeof vi.fn> }
}

/** Espera a que se vacie la microtask queue (para los void this.tick()). */
async function flush() {
  await Promise.resolve()
  await Promise.resolve()
}

// ---------------------------------------------------------------------------
// start / stop
// ---------------------------------------------------------------------------

describe('BackgroundSync.start', () => {
  it('online al arrancar: sync inmediato y registra intervalo', async () => {
    const conn = new FakeConnectivity(true)
    const sched = new FakeScheduler()
    const engine = makeEngine()
    const bg = new BackgroundSync({ engine, connectivity: conn, scheduler: sched })

    bg.start()
    await flush()

    expect(engine.syncOnce).toHaveBeenCalledOnce()
    expect(sched.activeCount()).toBe(1)
    expect(bg.isRunning()).toBe(true)
  })

  it('offline al arrancar: NO sync inmediato ni intervalo', async () => {
    const conn = new FakeConnectivity(false)
    const sched = new FakeScheduler()
    const engine = makeEngine()
    const bg = new BackgroundSync({ engine, connectivity: conn, scheduler: sched })

    bg.start()
    await flush()

    expect(engine.syncOnce).not.toHaveBeenCalled()
    expect(sched.activeCount()).toBe(0)
  })

  it('registra listeners de conectividad al arrancar', () => {
    const conn = new FakeConnectivity(true)
    const bg = new BackgroundSync({ engine: makeEngine(), connectivity: conn, scheduler: new FakeScheduler() })

    bg.start()

    expect(conn.countOnlineListeners()).toBe(1)
    expect(conn.countOfflineListeners()).toBe(1)
  })

  it('es idempotente: doble start no duplica listeners ni intervalos', async () => {
    const conn = new FakeConnectivity(true)
    const sched = new FakeScheduler()
    const bg = new BackgroundSync({ engine: makeEngine(), connectivity: conn, scheduler: sched })

    bg.start()
    bg.start()
    await flush()

    expect(conn.countOnlineListeners()).toBe(1)
    expect(sched.activeCount()).toBe(1)
  })

  it('emite bgsync.started', () => {
    const events: string[] = []
    const bg = new BackgroundSync({
      engine: makeEngine(), connectivity: new FakeConnectivity(true),
      scheduler: new FakeScheduler(), onEvent: (e) => events.push(e.type),
    })
    bg.start()
    expect(events).toContain('bgsync.started')
  })
})

describe('BackgroundSync.stop', () => {
  it('limpia intervalo y listeners', () => {
    const conn = new FakeConnectivity(true)
    const sched = new FakeScheduler()
    const bg = new BackgroundSync({ engine: makeEngine(), connectivity: conn, scheduler: sched })

    bg.start()
    bg.stop()

    expect(sched.activeCount()).toBe(0)
    expect(conn.countOnlineListeners()).toBe(0)
    expect(conn.countOfflineListeners()).toBe(0)
    expect(bg.isRunning()).toBe(false)
  })

  it('es idempotente: doble stop no falla', () => {
    const bg = new BackgroundSync({ engine: makeEngine(), connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler() })
    bg.start()
    bg.stop()
    expect(() => bg.stop()).not.toThrow()
  })

  it('emite bgsync.stopped', () => {
    const events: string[] = []
    const bg = new BackgroundSync({
      engine: makeEngine(), connectivity: new FakeConnectivity(true),
      scheduler: new FakeScheduler(), onEvent: (e) => events.push(e.type),
    })
    bg.start()
    bg.stop()
    expect(events).toContain('bgsync.stopped')
  })
})

// ---------------------------------------------------------------------------
// Intervalo
// ---------------------------------------------------------------------------

describe('BackgroundSync intervalo', () => {
  it('cada tick del scheduler dispara syncOnce', async () => {
    const conn = new FakeConnectivity(true)
    const sched = new FakeScheduler()
    const engine = makeEngine()
    const bg = new BackgroundSync({ engine, connectivity: conn, scheduler: sched })

    bg.start()
    await flush() // sync inmediato (1)
    sched.tickAll()
    await flush() // tick (2)
    sched.tickAll()
    await flush() // tick (3)

    expect(engine.syncOnce).toHaveBeenCalledTimes(3)
  })

  it('usa SYNC_INTERVAL_MS por defecto (5 min)', () => {
    expect(SYNC_INTERVAL_MS).toBe(5 * 60 * 1000)
  })

  it('respeta intervalMs personalizado pasandolo al scheduler', () => {
    const setIntervalSpy = vi.fn().mockReturnValue(1)
    const sched: Scheduler = { setInterval: setIntervalSpy, clearInterval: vi.fn() }
    const bg = new BackgroundSync({
      engine: makeEngine(), connectivity: new FakeConnectivity(true),
      scheduler: sched, intervalMs: 1234,
    })
    bg.start()
    expect(setIntervalSpy).toHaveBeenCalledWith(expect.any(Function), 1234)
  })
})

// ---------------------------------------------------------------------------
// Conectividad dinamica
// ---------------------------------------------------------------------------

describe('BackgroundSync conectividad', () => {
  it('al recuperar conexion: sync inmediato y arma intervalo', async () => {
    const conn = new FakeConnectivity(false)
    const sched = new FakeScheduler()
    const engine = makeEngine()
    const bg = new BackgroundSync({ engine, connectivity: conn, scheduler: sched })

    bg.start()
    await flush()
    expect(engine.syncOnce).not.toHaveBeenCalled()

    conn.goOnline()
    await flush()

    expect(engine.syncOnce).toHaveBeenCalledOnce()
    expect(sched.activeCount()).toBe(1)
  })

  it('al perder conexion: detiene el intervalo', async () => {
    const conn = new FakeConnectivity(true)
    const sched = new FakeScheduler()
    const bg = new BackgroundSync({ engine: makeEngine(), connectivity: conn, scheduler: sched })

    bg.start()
    await flush()
    expect(sched.activeCount()).toBe(1)

    conn.goOffline()
    expect(sched.activeCount()).toBe(0)
  })

  it('tick no llama syncOnce si quedo offline entre medias', async () => {
    const conn = new FakeConnectivity(true)
    const sched = new FakeScheduler()
    const engine = makeEngine()
    const bg = new BackgroundSync({ engine, connectivity: conn, scheduler: sched })

    bg.start()
    await flush() // sync inmediato (1)
    conn.online = false // se cae sin disparar evento
    sched.tickAll()
    await flush()

    expect(engine.syncOnce).toHaveBeenCalledOnce() // no aumento
  })

  it('emite bgsync.online y bgsync.offline', async () => {
    const events: string[] = []
    const conn = new FakeConnectivity(true)
    const bg = new BackgroundSync({
      engine: makeEngine(), connectivity: conn,
      scheduler: new FakeScheduler(), onEvent: (e) => events.push(e.type),
    })
    bg.start()
    conn.goOffline()
    conn.goOnline()
    await flush()
    expect(events).toContain('bgsync.offline')
    expect(events).toContain('bgsync.online')
  })
})

// ---------------------------------------------------------------------------
// tick: solapamiento y errores
// ---------------------------------------------------------------------------

describe('BackgroundSync tick', () => {
  it('no solapa: si un ciclo esta en curso, el siguiente tick se ignora', async () => {
    const conn = new FakeConnectivity(true)
    const sched = new FakeScheduler()
    let resolve!: () => void
    const syncOnce = vi.fn().mockImplementation(
      () => new Promise<SyncResult>((r) => { resolve = () => r(EMPTY_RESULT) }),
    )
    const bg = new BackgroundSync({ engine: makeEngine(syncOnce), connectivity: conn, scheduler: sched })

    bg.start()        // dispara tick 1 (queda pendiente)
    await flush()
    sched.tickAll()   // tick 2 mientras 1 sigue en curso
    await flush()

    expect(syncOnce).toHaveBeenCalledOnce()

    resolve()         // libera el ciclo 1
    await flush()
    sched.tickAll()   // ahora si corre
    await flush()
    expect(syncOnce).toHaveBeenCalledTimes(2)
  })

  it('emite bgsync.tick con el resultado en exito', async () => {
    const events: Array<{ type: string }> = []
    const bg = new BackgroundSync({
      engine: makeEngine(), connectivity: new FakeConnectivity(true),
      scheduler: new FakeScheduler(), onEvent: (e) => events.push(e),
    })
    bg.start()
    await flush()
    expect(events.some((e) => e.type === 'bgsync.tick')).toBe(true)
  })

  it('captura error de syncOnce y emite bgsync.error sin lanzar', async () => {
    const events: Array<{ type: string; error?: string }> = []
    const syncOnce = vi.fn().mockRejectedValue(new Error('boom'))
    const bg = new BackgroundSync({
      engine: makeEngine(syncOnce), connectivity: new FakeConnectivity(true),
      scheduler: new FakeScheduler(), onEvent: (e) => events.push(e as { type: string; error?: string }),
    })

    bg.start()
    await flush()

    const errEvent = events.find((e) => e.type === 'bgsync.error')
    expect(errEvent).toBeDefined()
    expect(errEvent?.error).toBe('boom')
    expect(bg.isRunning()).toBe(true) // sigue vivo tras el error
  })
})

// ---------------------------------------------------------------------------
// Degraded (sec. 35.5: online pero servidor caido)
// ---------------------------------------------------------------------------

/** Resultado con networkError en push (servidor inalcanzable con trafico). */
const PUSH_NET_ERROR: SyncResult = {
  push: { sent: 2, succeeded: 0, conflicts: 0, failed: 0, networkError: true },
  pull: {
    products: { ...ZERO }, taxes: { ...ZERO }, customers: { ...ZERO },
    snapshotTimestamp: '', networkError: false,
  },
}

describe('BackgroundSync degraded', () => {
  it('push.networkError -> isDegraded true y emite bgsync.degraded', async () => {
    const events: string[] = []
    const engine = makeEngine(vi.fn().mockResolvedValue(PUSH_NET_ERROR))
    const bg = new BackgroundSync({
      engine, connectivity: new FakeConnectivity(true),
      scheduler: new FakeScheduler(), onEvent: (e) => events.push(e.type),
    })

    bg.start()
    await flush()

    expect(bg.isDegraded()).toBe(true)
    expect(events).toContain('bgsync.degraded')
  })

  it('pull.networkError -> isDegraded true', async () => {
    const pullErr: SyncResult = {
      push: { sent: 0, succeeded: 0, conflicts: 0, failed: 0, networkError: false },
      pull: {
        products: { ...ZERO }, taxes: { ...ZERO }, customers: { ...ZERO },
        snapshotTimestamp: '', networkError: true,
      },
    }
    const bg = new BackgroundSync({
      engine: makeEngine(vi.fn().mockResolvedValue(pullErr)),
      connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler(),
    })
    bg.start()
    await flush()
    expect(bg.isDegraded()).toBe(true)
  })

  it('tick limpio tras degraded -> recovered', async () => {
    const events: string[] = []
    const syncOnce = vi.fn()
      .mockResolvedValueOnce(PUSH_NET_ERROR) // primer tick: degrada
      .mockResolvedValue(EMPTY_RESULT)        // siguientes: sano
    const sched = new FakeScheduler()
    const bg = new BackgroundSync({
      engine: makeEngine(syncOnce), connectivity: new FakeConnectivity(true),
      scheduler: sched, onEvent: (e) => events.push(e.type),
    })

    bg.start()
    await flush()
    expect(bg.isDegraded()).toBe(true)

    sched.tickAll()
    await flush()

    expect(bg.isDegraded()).toBe(false)
    expect(events).toContain('bgsync.recovered')
  })

  it('no re-emite degraded si ya estaba degradado', async () => {
    const events: string[] = []
    const sched = new FakeScheduler()
    const bg = new BackgroundSync({
      engine: makeEngine(vi.fn().mockResolvedValue(PUSH_NET_ERROR)),
      connectivity: new FakeConnectivity(true), scheduler: sched,
      onEvent: (e) => events.push(e.type),
    })

    bg.start()
    await flush()
    sched.tickAll()
    await flush()

    const degradedCount = events.filter((e) => e === 'bgsync.degraded').length
    expect(degradedCount).toBe(1)
  })

  it('excepcion en syncOnce -> degraded', async () => {
    const bg = new BackgroundSync({
      engine: makeEngine(vi.fn().mockRejectedValue(new Error('boom'))),
      connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler(),
    })
    bg.start()
    await flush()
    expect(bg.isDegraded()).toBe(true)
  })

  it('pasar a offline limpia el estado degraded', async () => {
    const conn = new FakeConnectivity(true)
    const bg = new BackgroundSync({
      engine: makeEngine(vi.fn().mockResolvedValue(PUSH_NET_ERROR)),
      connectivity: conn, scheduler: new FakeScheduler(),
    })
    bg.start()
    await flush()
    expect(bg.isDegraded()).toBe(true)

    conn.goOffline()
    expect(bg.isDegraded()).toBe(false)
  })
})

describe('BackgroundSync sonda heartbeat (cola vacia)', () => {
  it('cola vacia + heartbeat OK -> no degraded', async () => {
    const ping = vi.fn().mockResolvedValue({ server_time: '2026-06-18T12:00:00Z' })
    const bg = new BackgroundSync({
      engine: makeEngine(), // EMPTY_RESULT: sent 0
      connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler(),
      heartbeat: { ping },
    })
    bg.start()
    await flush()
    expect(ping).toHaveBeenCalledOnce()
    expect(bg.isDegraded()).toBe(false)
  })

  it('cola vacia + heartbeat falla -> degraded', async () => {
    const ping = vi.fn().mockRejectedValue(new Error('servidor caido'))
    const bg = new BackgroundSync({
      engine: makeEngine(),
      connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler(),
      heartbeat: { ping },
    })
    bg.start()
    await flush()
    expect(ping).toHaveBeenCalledOnce()
    expect(bg.isDegraded()).toBe(true)
  })

  it('con trafico (push.sent > 0) NO usa la sonda heartbeat', async () => {
    const ping = vi.fn().mockResolvedValue({})
    const withTraffic: SyncResult = {
      push: { sent: 3, succeeded: 3, conflicts: 0, failed: 0, networkError: false },
      pull: {
        products: { ...ZERO }, taxes: { ...ZERO }, customers: { ...ZERO },
        snapshotTimestamp: '', networkError: false,
      },
    }
    const bg = new BackgroundSync({
      engine: makeEngine(vi.fn().mockResolvedValue(withTraffic)),
      connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler(),
      heartbeat: { ping },
    })
    bg.start()
    await flush()
    expect(ping).not.toHaveBeenCalled()
    expect(bg.isDegraded()).toBe(false)
  })

  it('sin sonda inyectada y cola vacia -> no degraded (no rompe)', async () => {
    const bg = new BackgroundSync({
      engine: makeEngine(),
      connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler(),
    })
    bg.start()
    await flush()
    expect(bg.isDegraded()).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// Blocked (sec. 35.5: tenant suspendido, HTTP 402)
// ---------------------------------------------------------------------------

/** Error con status, imita HeartbeatError sin importarlo. */
function statusError(status: number) {
  return Object.assign(new Error(`HTTP ${status}`), { status })
}

describe('BackgroundSync blocked', () => {
  it('sonda heartbeat con 402 -> isBlocked true y emite bgsync.blocked', async () => {
    const events: string[] = []
    const ping = vi.fn().mockRejectedValue(statusError(402))
    const bg = new BackgroundSync({
      engine: makeEngine(), // cola vacia
      connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler(),
      heartbeat: { ping }, onEvent: (e) => events.push(e.type),
    })

    bg.start()
    await flush()

    expect(bg.isBlocked()).toBe(true)
    expect(bg.isDegraded()).toBe(false) // blocked no es degraded
    expect(events).toContain('bgsync.blocked')
  })

  it('sonda con error no-402 -> degraded, NO blocked', async () => {
    const bg = new BackgroundSync({
      engine: makeEngine(),
      connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler(),
      heartbeat: { ping: vi.fn().mockRejectedValue(statusError(500)) },
    })
    bg.start()
    await flush()
    expect(bg.isBlocked()).toBe(false)
    expect(bg.isDegraded()).toBe(true)
  })

  it('sonda con error de red (status 0) -> degraded, NO blocked', async () => {
    const bg = new BackgroundSync({
      engine: makeEngine(),
      connectivity: new FakeConnectivity(true), scheduler: new FakeScheduler(),
      heartbeat: { ping: vi.fn().mockRejectedValue(statusError(0)) },
    })
    bg.start()
    await flush()
    expect(bg.isBlocked()).toBe(false)
    expect(bg.isDegraded()).toBe(true)
  })

  it('ping OK tras blocked -> unblocked', async () => {
    const events: string[] = []
    const ping = vi.fn()
      .mockRejectedValueOnce(statusError(402)) // primer tick: bloquea
      .mockResolvedValue({ server_time: '2026-06-18T12:00:00Z' }) // luego OK
    const sched = new FakeScheduler()
    const bg = new BackgroundSync({
      engine: makeEngine(),
      connectivity: new FakeConnectivity(true), scheduler: sched,
      heartbeat: { ping }, onEvent: (e) => events.push(e.type),
    })

    bg.start()
    await flush()
    expect(bg.isBlocked()).toBe(true)

    sched.tickAll()
    await flush()

    expect(bg.isBlocked()).toBe(false)
    expect(events).toContain('bgsync.unblocked')
  })

  it('no re-emite blocked si ya estaba bloqueado', async () => {
    const events: string[] = []
    const sched = new FakeScheduler()
    const bg = new BackgroundSync({
      engine: makeEngine(),
      connectivity: new FakeConnectivity(true), scheduler: sched,
      heartbeat: { ping: vi.fn().mockRejectedValue(statusError(402)) },
      onEvent: (e) => events.push(e.type),
    })

    bg.start()
    await flush()
    sched.tickAll()
    await flush()

    expect(events.filter((e) => e === 'bgsync.blocked').length).toBe(1)
  })

  it('pasar a offline limpia el estado blocked', async () => {
    const conn = new FakeConnectivity(true)
    const bg = new BackgroundSync({
      engine: makeEngine(),
      connectivity: conn, scheduler: new FakeScheduler(),
      heartbeat: { ping: vi.fn().mockRejectedValue(statusError(402)) },
    })
    bg.start()
    await flush()
    expect(bg.isBlocked()).toBe(true)

    conn.goOffline()
    expect(bg.isBlocked()).toBe(false)
  })
})

afterEach(() => {
  vi.restoreAllMocks()
})
