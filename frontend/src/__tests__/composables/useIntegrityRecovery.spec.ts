import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useIntegrityRecovery } from '@/composables/useIntegrityRecovery'
import type { IntegrityRecoveryDeps } from '@/composables/useIntegrityRecovery'

/** Mock del store de auth: tenant controlado por test. */
let currentTenant: string | null = 'acme'
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get tenant() {
      return currentTenant
    },
  }),
}))

/**
 * Fakes inyectables. exportPending devuelve un export minimo; restore y run
 * son spies. triggerDownload captura lo descargado sin tocar el DOM.
 */
function makeDeps(overrides: Partial<{
  exportPending: ReturnType<typeof vi.fn>
  restore: ReturnType<typeof vi.fn>
  run: ReturnType<typeof vi.fn>
  download: ReturnType<typeof vi.fn>
}> = {}) {
  const exportPending = overrides.exportPending ?? vi.fn().mockResolvedValue({
    version: 1, exportedAt: '2026-01-01T00:00:00Z', syncQueue: [], sales: [],
  })
  const restore = overrides.restore ?? vi.fn().mockResolvedValue({ clearedTables: 5 })
  const run = overrides.run ?? vi.fn().mockResolvedValue(undefined)
  const download = overrides.download ?? vi.fn()

  const deps: IntegrityRecoveryDeps = {
    makeIntegrity: () => ({ exportPending, restore }),
    makeSnapshot: () => ({ run }),
    triggerDownload: download,
  }
  return { deps, exportPending, restore, run, download }
}

describe('useIntegrityRecovery', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    currentTenant = 'acme'
  })

  it('exportPending: dispara descarga con JSON y marca exported', async () => {
    const { deps, exportPending, download } = makeDeps()
    const recovery = useIntegrityRecovery(deps)
    const ok = await recovery.exportPending()
    expect(ok).toBe(true)
    expect(exportPending).toHaveBeenCalledOnce()
    expect(download).toHaveBeenCalledOnce()
    // segundo argumento es el JSON serializado
    const json = download.mock.calls[0]![1] as string
    expect(JSON.parse(json)).toMatchObject({ version: 1 })
    expect(recovery.exported.value).toBe(true)
    expect(recovery.exporting.value).toBe(false)
  })

  it('exportPending: error poblea errorMessage y no marca exported', async () => {
    const { deps } = makeDeps({ exportPending: vi.fn().mockRejectedValue(new Error('export boom')) })
    const recovery = useIntegrityRecovery(deps)
    const ok = await recovery.exportPending()
    expect(ok).toBe(false)
    expect(recovery.errorMessage.value).toBe('export boom')
    expect(recovery.exported.value).toBe(false)
  })

  it('restore: borra y repobla via snapshot, marca restored', async () => {
    const { deps, restore, run } = makeDeps()
    const recovery = useIntegrityRecovery(deps)
    const ok = await recovery.restore()
    expect(ok).toBe(true)
    expect(restore).toHaveBeenCalledOnce()
    expect(run).toHaveBeenCalledOnce()
    expect(recovery.restored.value).toBe(true)
    expect(recovery.restoring.value).toBe(false)
  })

  it('restore: sin tenant no procede y poblea errorMessage', async () => {
    currentTenant = null
    const { deps, restore, run } = makeDeps()
    const recovery = useIntegrityRecovery(deps)
    const ok = await recovery.restore()
    expect(ok).toBe(false)
    expect(restore).not.toHaveBeenCalled()
    expect(run).not.toHaveBeenCalled()
    expect(recovery.errorMessage.value).toContain('sesion')
  })

  it('restore: error del repoblado poblea errorMessage y no marca restored', async () => {
    const { deps } = makeDeps({ run: vi.fn().mockRejectedValue(new Error('snapshot fail')) })
    const recovery = useIntegrityRecovery(deps)
    const ok = await recovery.restore()
    expect(ok).toBe(false)
    expect(recovery.errorMessage.value).toBe('snapshot fail')
    expect(recovery.restored.value).toBe(false)
  })

  it('restore: reporta progreso del snapshot via restoreProgress', async () => {
    // run que emite un progreso a traves del onProgress inyectado
    const makeDepsWithProgress = (): IntegrityRecoveryDeps => ({
      makeIntegrity: () => ({
        exportPending: vi.fn(),
        restore: vi.fn().mockResolvedValue({ clearedTables: 5 }),
      }),
      makeSnapshot: (_tenant, onProgress) => ({
        run: vi.fn().mockImplementation(async () => {
          onProgress({ entity: 'products', phase: 'done', count: 42 })
        }),
      }),
      triggerDownload: vi.fn(),
    })
    const recovery = useIntegrityRecovery(makeDepsWithProgress())
    await recovery.restore()
    expect(recovery.restoreProgress.value).toMatchObject({ entity: 'products', count: 42 })
  })
})
