import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import SyncStatusBanner from '@/components/SyncStatusBanner.vue'

// Store sync -> reactive() per patron. El componente deriva 'kind' a partir
// de estos flags/estado, asi que el mock expone los campos planos.
const sync = reactive({
  status: 'idle' as string,
  isOnline: true,
  pendingCount: 0,
  conflictCount: 0,
  hasPending: false,
  hasConflicts: false,
  isDegraded: false,
  isBlocked: false,
})

vi.mock('@/stores/sync', () => ({
  useSyncStore: () => sync,
}))

function reset(over: Partial<typeof sync> = {}): void {
  sync.status = 'idle'
  sync.isOnline = true
  sync.pendingCount = 0
  sync.conflictCount = 0
  sync.hasPending = false
  sync.hasConflicts = false
  sync.isDegraded = false
  sync.isBlocked = false
  Object.assign(sync, over)
}

function mountBanner(): VueWrapper {
  return mount(SyncStatusBanner, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

beforeEach(() => {
  reset()
})

describe('SyncStatusBanner', () => {
  it('permanece oculto en estado idle online sin pendientes ni conflictos', () => {
    const wrapper = mountBanner()
    expect(wrapper.find('.sync-banner').exists()).toBe(false)
  })

  it('muestra el banner offline cuando no hay conexion', () => {
    reset({ isOnline: false, status: 'offline' })
    const wrapper = mountBanner()
    expect(wrapper.find('.sync-banner--offline').exists()).toBe(true)
    expect(wrapper.text()).toContain('Sin conexion')
  })

  it('muestra el banner degraded cuando la sync tiene errores', () => {
    reset({ isDegraded: true, status: 'degraded' })
    const wrapper = mountBanner()
    expect(wrapper.find('.sync-banner--degraded').exists()).toBe(true)
  })

  it('muestra el banner blocked y prioriza sobre offline', () => {
    // isBlocked tiene la prioridad maxima aun si isOnline es false.
    reset({ isBlocked: true, status: 'blocked', isOnline: false })
    const wrapper = mountBanner()
    expect(wrapper.find('.sync-banner--blocked').exists()).toBe(true)
    expect(wrapper.text()).toContain('solo lectura')
  })

  it('muestra el banner syncing mientras sincroniza', () => {
    reset({ status: 'syncing' })
    const wrapper = mountBanner()
    expect(wrapper.find('.sync-banner--syncing').exists()).toBe(true)
  })

  it('muestra el banner pending cuando hay cambios sin sincronizar', () => {
    reset({ hasPending: true, pendingCount: 4 })
    const wrapper = mountBanner()
    expect(wrapper.find('.sync-banner--pending').exists()).toBe(true)
  })

  it('muestra el chip con el conteo de pendientes', () => {
    reset({ hasPending: true, pendingCount: 7 })
    const wrapper = mountBanner()
    expect(wrapper.find('.sync-banner__chip').text()).toContain('7')
  })

  it('muestra el enlace a conflictos cuando hay conflictos', () => {
    reset({ hasConflicts: true, conflictCount: 2, status: 'degraded', isDegraded: true })
    const wrapper = mountBanner()
    const link = wrapper.find('.sync-banner__chip--link')
    expect(link.exists()).toBe(true)
    expect(link.text()).toContain('2')
  })
})
