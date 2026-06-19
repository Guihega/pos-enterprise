/**
 * Tests del store sync (Fase 2, Iteracion 2).
 *
 * SyncEngine y BackgroundSync se mockean a nivel de modulo. El store se
 * prueba inyectando factories fake via deps en start(), salvo el caso
 * que verifica el camino por defecto (construye instancias reales mock).
 * Los repositorios de conteo se mockean para no tocar IndexedDB.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

// ---- mocks de modulos ----

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: mockTenant }),
}))

vi.mock('@/repositories/SyncQueueRepository', () => ({
  countByStatus: vi.fn(),
}))
vi.mock('@/repositories/ConflictRepository', () => ({
  countUnresolved: vi.fn(),
}))

import { useSyncStore } from '@/stores/sync'
import { countByStatus } from '@/repositories/SyncQueueRepository'
import { countUnresolved } from '@/repositories/ConflictRepository'
import type { BackgroundSync } from '@/sync/BackgroundSync'
import type { SyncEngine } from '@/sync/SyncEngine'

// Tenant mutable para simular sesion presente/ausente.
let mockTenant: string | null = 'demo'

// ---- fakes de engine/scheduler ----

function makeFakeBgSync() {
  return {
    start: vi.fn(),
    stop: vi.fn(),
    isRunning: vi.fn().mockReturnValue(true),
  } as unknown as BackgroundSync & {
    start: ReturnType<typeof vi.fn>
    stop: ReturnType<typeof vi.fn>
  }
}

const fakeEngine = {} as SyncEngine

function startWithFakes(store: ReturnType<typeof useSyncStore>) {
  const bg = makeFakeBgSync()
  let capturedOnEvent!: (e: any) => void
  store.start({
    makeEngine: () => fakeEngine,
    makeBgSync: (_engine, onEvent) => {
      capturedOnEvent = onEvent
      return bg
    },
  })
  return { bg, emit: (e: any) => capturedOnEvent(e) }
}

// ---- setup ----

beforeEach(() => {
  setActivePinia(createPinia())
  mockTenant = 'demo'
  vi.mocked(countByStatus).mockResolvedValue(0)
  vi.mocked(countUnresolved).mockResolvedValue(0)
})

// ---------------------------------------------------------------------------
// start / stop
// ---------------------------------------------------------------------------

describe('useSyncStore.start', () => {
  it('arranca el scheduler y marca isRunning', () => {
    const store = useSyncStore()
    const { bg } = startWithFakes(store)

    expect(bg.start).toHaveBeenCalledOnce()
    expect(store.isRunning).toBe(true)
  })

  it('no arranca si no hay tenant (sin sesion)', () => {
    mockTenant = null
    const store = useSyncStore()
    const { bg } = startWithFakes(store)

    expect(bg.start).not.toHaveBeenCalled()
    expect(store.isRunning).toBe(false)
  })

  it('es idempotente: segundo start no crea otra instancia', () => {
    const store = useSyncStore()
    const { bg } = startWithFakes(store)
    // segundo intento: como ya hay bgsync, ni siquiera invoca makeBgSync
    store.start({ makeEngine: () => fakeEngine, makeBgSync: () => makeFakeBgSync() })

    expect(bg.start).toHaveBeenCalledOnce()
  })

  it('refresca contadores al arrancar', async () => {
    vi.mocked(countByStatus).mockResolvedValue(3)
    vi.mocked(countUnresolved).mockResolvedValue(2)
    const store = useSyncStore()
    startWithFakes(store)
    await vi.waitFor(() => {
      expect(store.pendingCount).toBe(3)
      expect(store.conflictCount).toBe(2)
    })
  })
})

describe('useSyncStore.stop', () => {
  it('detiene el scheduler y limpia isRunning', () => {
    const store = useSyncStore()
    const { bg } = startWithFakes(store)
    store.stop()

    expect(bg.stop).toHaveBeenCalledOnce()
    expect(store.isRunning).toBe(false)
    expect(store.status).toBe('stopped')
  })

  it('es idempotente: stop sin start no falla', () => {
    const store = useSyncStore()
    expect(() => store.stop()).not.toThrow()
  })
})

// ---------------------------------------------------------------------------
// Traduccion de eventos a estado de UI
// ---------------------------------------------------------------------------

describe('useSyncStore eventos', () => {
  it('bgsync.started -> status idle', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.started' })
    expect(store.status).toBe('idle')
  })

  it('bgsync.offline -> status offline + isOnline false', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.offline' })
    expect(store.status).toBe('offline')
    expect(store.isOnline).toBe(false)
  })

  it('bgsync.online tras offline -> isOnline true + status idle', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.offline' })
    emit({ type: 'bgsync.online' })
    expect(store.isOnline).toBe(true)
    expect(store.status).toBe('idle')
  })

  it('bgsync.tick -> actualiza lastSyncAt, limpia error y refresca contadores', async () => {
    vi.mocked(countByStatus).mockResolvedValue(5)
    vi.mocked(countUnresolved).mockResolvedValue(1)
    const store = useSyncStore()
    const { emit } = startWithFakes(store)

    emit({ type: 'bgsync.tick', result: {} as any })

    await vi.waitFor(() => {
      expect(store.lastSyncAt).not.toBeNull()
      expect(store.pendingCount).toBe(5)
      expect(store.conflictCount).toBe(1)
    })
    expect(store.lastError).toBeNull()
  })

  it('bgsync.tick estando offline NO sobreescribe status offline', async () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.offline' })
    emit({ type: 'bgsync.tick', result: {} as any })
    await vi.waitFor(() => expect(store.lastSyncAt).not.toBeNull())
    expect(store.status).toBe('offline')
  })

  it('bgsync.error -> status error + lastError', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.error', error: 'boom' })
    expect(store.status).toBe('error')
    expect(store.lastError).toBe('boom')
  })
})

// ---------------------------------------------------------------------------
// degraded / recovered (sec. 35.5)
// ---------------------------------------------------------------------------

describe('useSyncStore degraded', () => {
  it('bgsync.degraded -> status degraded, isOnline intacto', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.degraded' })
    expect(store.status).toBe('degraded')
    expect(store.isDegraded).toBe(true)
    expect(store.isOnline).toBe(true) // navigator sigue online
  })

  it('bgsync.recovered tras degraded -> idle', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.degraded' })
    emit({ type: 'bgsync.recovered' })
    expect(store.status).toBe('idle')
    expect(store.isDegraded).toBe(false)
  })

  it('offline tiene prioridad sobre degraded', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.offline' })
    emit({ type: 'bgsync.degraded' })
    expect(store.status).toBe('offline')
  })

  it('tick estando degraded NO lo baja a idle', async () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.degraded' })
    emit({ type: 'bgsync.tick', result: {} as any })
    await vi.waitFor(() => expect(store.lastSyncAt).not.toBeNull())
    expect(store.status).toBe('degraded')
  })

  it('recovered sin degradacion previa no fuerza idle desde offline', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.offline' })
    emit({ type: 'bgsync.recovered' })
    expect(store.status).toBe('offline')
  })
})

// ---------------------------------------------------------------------------
// blocked / unblocked (sec. 35.5: tenant suspendido)
// ---------------------------------------------------------------------------

describe('useSyncStore blocked', () => {
  it('bgsync.blocked -> status blocked', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.blocked' })
    expect(store.status).toBe('blocked')
    expect(store.isBlocked).toBe(true)
  })

  it('bgsync.unblocked tras blocked -> idle', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.blocked' })
    emit({ type: 'bgsync.unblocked' })
    expect(store.status).toBe('idle')
    expect(store.isBlocked).toBe(false)
  })

  it('blocked tiene prioridad sobre degraded', () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.blocked' })
    emit({ type: 'bgsync.degraded' })
    expect(store.status).toBe('blocked')
  })

  it('tick estando blocked NO lo baja a idle', async () => {
    const store = useSyncStore()
    const { emit } = startWithFakes(store)
    emit({ type: 'bgsync.blocked' })
    emit({ type: 'bgsync.tick', result: {} as any })
    await vi.waitFor(() => expect(store.lastSyncAt).not.toBeNull())
    expect(store.status).toBe('blocked')
  })
})

// ---------------------------------------------------------------------------
// getters
// ---------------------------------------------------------------------------

describe('useSyncStore getters', () => {
  it('hasPending y hasConflicts reflejan los contadores', async () => {
    vi.mocked(countByStatus).mockResolvedValue(2)
    vi.mocked(countUnresolved).mockResolvedValue(0)
    const store = useSyncStore()
    await store.refreshCounts()
    expect(store.hasPending).toBe(true)
    expect(store.hasConflicts).toBe(false)
  })
})
