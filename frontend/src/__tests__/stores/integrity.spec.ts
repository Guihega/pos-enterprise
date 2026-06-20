import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useIntegrityStore } from '@/stores/integrity'

describe('integrity store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  // ---- checkIntegrity (35.4 paso 4, 42.3) ----

  it('check: ok deja isCorrupt false', async () => {
    const store = useIntegrityStore()
    await store.check({
      makeIntegrity: () => ({ checkIntegrity: vi.fn().mockResolvedValue({ ok: true }) }),
    })
    expect(store.isCorrupt).toBe(false)
    expect(store.integrityError).toBeNull()
    expect(store.checking).toBe(false)
  })

  it('check: ok=false marca isCorrupt y guarda el error', async () => {
    const store = useIntegrityStore()
    await store.check({
      makeIntegrity: () => ({ checkIntegrity: vi.fn().mockResolvedValue({ ok: false, error: 'IO error' }) }),
    })
    expect(store.isCorrupt).toBe(true)
    expect(store.integrityError).toBe('IO error')
  })

  it('check: si el chequeo lanza, asume corrupcion', async () => {
    const store = useIntegrityStore()
    await store.check({
      makeIntegrity: () => ({ checkIntegrity: vi.fn().mockRejectedValue(new Error('dexie throw')) }),
    })
    expect(store.isCorrupt).toBe(true)
    expect(store.integrityError).toBe('dexie throw')
  })

  it('clearCorrupt: limpia el flag tras restaurar', async () => {
    const store = useIntegrityStore()
    await store.check({
      makeIntegrity: () => ({ checkIntegrity: vi.fn().mockResolvedValue({ ok: false, error: 'x' }) }),
    })
    expect(store.isCorrupt).toBe(true)
    store.clearCorrupt()
    expect(store.isCorrupt).toBe(false)
    expect(store.integrityError).toBeNull()
  })

  // ---- clock drift (42.5) ----

  it('measureClockDrift: ok poblea driftMs y severity', async () => {
    const store = useIntegrityStore()
    await store.measureClockDrift('acme', {
      makeHeartbeat: () => ({
        pingWithDrift: vi.fn().mockResolvedValue({
          result: {}, driftMs: 12000, severity: 'warning',
        }),
      }),
    })
    expect(store.driftMs).toBe(12000)
    expect(store.driftSeverity).toBe('warning')
    expect(store.driftWarning).toBe(true)
    expect(store.driftBlocked).toBe(false)
  })

  it('measureClockDrift: severity blocked activa driftBlocked', async () => {
    const store = useIntegrityStore()
    await store.measureClockDrift('acme', {
      makeHeartbeat: () => ({
        pingWithDrift: vi.fn().mockResolvedValue({
          result: {}, driftMs: 2000000, severity: 'blocked',
        }),
      }),
    })
    expect(store.driftBlocked).toBe(true)
    expect(store.driftWarning).toBe(false)
  })

  it('measureClockDrift: error de red deja severity null (no medible)', async () => {
    const store = useIntegrityStore()
    await store.measureClockDrift('acme', {
      makeHeartbeat: () => ({
        pingWithDrift: vi.fn().mockRejectedValue(new Error('network')),
      }),
    })
    expect(store.driftMs).toBeNull()
    expect(store.driftSeverity).toBeNull()
    expect(store.driftWarning).toBe(false)
    expect(store.driftBlocked).toBe(false)
  })

  it('measureClockDrift: severity ok no advierte ni bloquea', async () => {
    const store = useIntegrityStore()
    await store.measureClockDrift('acme', {
      makeHeartbeat: () => ({
        pingWithDrift: vi.fn().mockResolvedValue({ result: {}, driftMs: 1000, severity: 'ok' }),
      }),
    })
    expect(store.driftSeverity).toBe('ok')
    expect(store.driftWarning).toBe(false)
    expect(store.driftBlocked).toBe(false)
  })
})
