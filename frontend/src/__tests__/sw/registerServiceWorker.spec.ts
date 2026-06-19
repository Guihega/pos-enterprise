/**
 * Tests del registro del Service Worker (doc maestro 37.2).
 *
 * Se inyecta un `register` fake para no depender del modulo virtual
 * virtual:pwa-register (solo existe en build). Verifica el cableado de
 * callbacks de ciclo de vida y el update manual-approve.
 */
import { describe, expect, it, vi } from 'vitest'
import { registerServiceWorker } from '@/sw/registerServiceWorker'

describe('registerServiceWorker', () => {
  it('llama al register con immediate=true', async () => {
    const applyUpdate = vi.fn()
    const register = vi.fn().mockReturnValue(applyUpdate)

    await registerServiceWorker({}, register)

    expect(register).toHaveBeenCalledOnce()
    expect(register.mock.calls[0]![0]).toMatchObject({ immediate: true })
  })

  it('conecta onNeedRefresh (37.2: nueva version disponible)', async () => {
    const onNeedRefresh = vi.fn()
    let capturedNeedRefresh: (() => void) | undefined
    const register = vi.fn().mockImplementation((opts) => {
      capturedNeedRefresh = opts.onNeedRefresh
      return vi.fn()
    })

    await registerServiceWorker({ onNeedRefresh }, register)
    capturedNeedRefresh!()

    expect(onNeedRefresh).toHaveBeenCalledOnce()
  })

  it('conecta onOfflineReady', async () => {
    const onOfflineReady = vi.fn()
    let captured: (() => void) | undefined
    const register = vi.fn().mockImplementation((opts) => {
      captured = opts.onOfflineReady
      return vi.fn()
    })

    await registerServiceWorker({ onOfflineReady }, register)
    captured!()

    expect(onOfflineReady).toHaveBeenCalledOnce()
  })

  it('conecta onError ante fallo de registro', async () => {
    const onError = vi.fn()
    let captured: ((e: unknown) => void) | undefined
    const register = vi.fn().mockImplementation((opts) => {
      captured = opts.onRegisterError
      return vi.fn()
    })

    await registerServiceWorker({ onError }, register)
    captured!(new Error('sw fail'))

    expect(onError).toHaveBeenCalledOnce()
  })

  it('devuelve la funcion applyUpdate del register (37.2 actualizar ahora)', async () => {
    const applyUpdate = vi.fn().mockResolvedValue(undefined)
    const register = vi.fn().mockReturnValue(applyUpdate)

    const result = await registerServiceWorker({}, register)
    await result(true)

    expect(result).toBe(applyUpdate)
    expect(applyUpdate).toHaveBeenCalledWith(true)
  })

  it('si register lanza, captura el error y devuelve un no-op', async () => {
    const onError = vi.fn()
    const register = vi.fn().mockImplementation(() => { throw new Error('boom') })

    const result = await registerServiceWorker({ onError }, register)

    expect(onError).toHaveBeenCalledOnce()
    // el no-op resuelve sin lanzar
    await expect(result()).resolves.toBeUndefined()
  })

  it('sin SW en el navegador y sin register inyectado, devuelve no-op', async () => {
    // jsdom no expone serviceWorker en navigator por defecto.
    const result = await registerServiceWorker({})
    await expect(result()).resolves.toBeUndefined()
  })
})
