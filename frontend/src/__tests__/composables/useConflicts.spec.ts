import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useConflicts } from '@/composables/useConflicts'
import type { ConflictLocal } from '@/db/schema'

/** Mock del repositorio de conflictos. */
vi.mock('@/repositories/ConflictRepository', () => ({
  getUnresolved: vi.fn(),
  markResolved: vi.fn(),
}))

/** Mock del store de sync (solo refreshCounts). */
const refreshCounts = vi.fn()
vi.mock('@/stores/sync', () => ({
  useSyncStore: () => ({ refreshCounts }),
}))

/** Mock del store de auth: el rol se controla por test via setRoles. */
let currentRoles: string[] = []
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return { roles: currentRoles }
    },
  }),
}))

import { getUnresolved as apiGetUnresolved, markResolved as apiMarkResolved } from '@/repositories/ConflictRepository'

function setRoles(roles: string[]): void {
  currentRoles = roles
}

function makeConflict(uuid: string, entityType = 'sale', reason = 'CASH_SESSION_CLOSED'): ConflictLocal {
  return {
    uuid,
    entityType: entityType as ConflictLocal['entityType'],
    entityUuid: `ent-${uuid}`,
    clientUuid: `cli-${uuid}`,
    reason: reason as ConflictLocal['reason'],
    clientPayload: {},
    serverData: {},
    resolution: null,
    auto: false,
    requireRole: 'manager',
    detectedAt: '2026-01-01T00:00:00Z',
    resolvedAt: null,
  }
}

describe('useConflicts', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    setRoles([])
  })

  it('load: poblea items con los conflictos no resueltos', async () => {
    vi.mocked(apiGetUnresolved).mockResolvedValue([makeConflict('a'), makeConflict('b')])
    const { load, items, loading, isEmpty } = useConflicts()
    await load()
    expect(items.value).toHaveLength(2)
    expect(loading.value).toBe(false)
    expect(isEmpty.value).toBe(false)
  })

  it('load: error poblea errorMessage y deja items vacios', async () => {
    vi.mocked(apiGetUnresolved).mockRejectedValue(new Error('Dexie caido'))
    const { load, items, errorMessage } = useConflicts()
    await load()
    expect(items.value).toHaveLength(0)
    expect(errorMessage.value).toBe('Dexie caido')
  })

  it('isEmpty: true cuando no hay conflictos y no esta cargando', async () => {
    vi.mocked(apiGetUnresolved).mockResolvedValue([])
    const { load, isEmpty } = useConflicts()
    await load()
    expect(isEmpty.value).toBe(true)
  })

  it('canResolve: true para gerente', () => {
    setRoles(['gerente'])
    const { canResolve } = useConflicts()
    expect(canResolve.value).toBe(true)
  })

  it('canResolve: true para admin y super_admin', () => {
    setRoles(['admin'])
    expect(useConflicts().canResolve.value).toBe(true)
    setRoles(['super_admin'])
    expect(useConflicts().canResolve.value).toBe(true)
  })

  it('canResolve: false para cajero', () => {
    setRoles(['cajero'])
    const { canResolve } = useConflicts()
    expect(canResolve.value).toBe(false)
  })

  it('canResolve: false sin usuario/roles', () => {
    setRoles([])
    expect(useConflicts().canResolve.value).toBe(false)
  })

  it('resolveManual: sin permiso no llama markResolved y poblea error', async () => {
    setRoles(['cajero'])
    vi.mocked(apiGetUnresolved).mockResolvedValue([makeConflict('a')])
    const { load, resolveManual, errorMessage } = useConflicts()
    await load()
    const ok = await resolveManual('a', 'use_client')
    expect(ok).toBe(false)
    expect(apiMarkResolved).not.toHaveBeenCalled()
    expect(errorMessage.value).toContain('permiso')
  })

  it('resolveManual: con permiso marca resuelto auto=false y quita de la lista', async () => {
    setRoles(['gerente'])
    vi.mocked(apiGetUnresolved).mockResolvedValue([makeConflict('a'), makeConflict('b')])
    vi.mocked(apiMarkResolved).mockResolvedValue(undefined)
    const { load, resolveManual, items } = useConflicts()
    await load()
    const ok = await resolveManual('a', 'use_server')
    expect(ok).toBe(true)
    expect(apiMarkResolved).toHaveBeenCalledWith('a', 'use_server', false)
    expect(items.value.map((c) => c.uuid)).toEqual(['b'])
    expect(refreshCounts).toHaveBeenCalledOnce()
  })

  it('resolveManual: error del repo poblea errorMessage y conserva el item', async () => {
    setRoles(['admin'])
    vi.mocked(apiGetUnresolved).mockResolvedValue([makeConflict('a')])
    vi.mocked(apiMarkResolved).mockRejectedValue(new Error('write fail'))
    const { load, resolveManual, items, errorMessage } = useConflicts()
    await load()
    const ok = await resolveManual('a', 'use_client')
    expect(ok).toBe(false)
    expect(errorMessage.value).toBe('write fail')
    expect(items.value).toHaveLength(1)
  })
})
