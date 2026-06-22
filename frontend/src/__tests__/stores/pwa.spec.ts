import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { usePwaStore } from '@/stores/pwa'

describe('pwa store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('estado inicial: sin refresh ni offline, no updating', () => {
    const store = usePwaStore()
    expect(store.needRefresh).toBe(false)
    expect(store.offlineReady).toBe(false)
    expect(store.updating).toBe(false)
  })

  it('setNeedRefresh: activa la notificacion de nueva version (37.2)', () => {
    const store = usePwaStore()
    store.setNeedRefresh(true)
    expect(store.needRefresh).toBe(true)
  })

  it('setOfflineReady: marca la app lista offline (37.1)', () => {
    const store = usePwaStore()
    store.setOfflineReady(true)
    expect(store.offlineReady).toBe(true)
  })

  it('applyUpdate: invoca la funcion registrada con reload=true', async () => {
    const store = usePwaStore()
    const fn = vi.fn().mockResolvedValue(undefined)
    store.setApplyUpdate(fn)
    await store.applyUpdate()
    expect(fn).toHaveBeenCalledWith(true)
  })

  it('applyUpdate: no-op si no hay funcion registrada', async () => {
    const store = usePwaStore()
    // no lanza aunque no se haya registrado applyUpdate
    await expect(store.applyUpdate()).resolves.toBeUndefined()
  })

  it('applyUpdate: limpia updating tras completar', async () => {
    const store = usePwaStore()
    store.setApplyUpdate(vi.fn().mockResolvedValue(undefined))
    await store.applyUpdate()
    expect(store.updating).toBe(false)
  })

  it('dismiss: oculta la notificacion sin actualizar (37.2 puede ignorar)', () => {
    const store = usePwaStore()
    store.setNeedRefresh(true)
    store.dismiss()
    expect(store.needRefresh).toBe(false)
  })
})
