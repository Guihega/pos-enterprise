/**
 * Registro del Service Worker con update manual-approve (doc maestro 37.2).
 *
 * Carga dinamica de 'virtual:pwa-register' para no romper el entorno de tests
 * (el modulo virtual solo existe en build/dev con vite-plugin-pwa).
 * En tests se inyecta un `register` fake via el segundo parametro.
 */

export type ApplyUpdate = (reloadPage?: boolean) => Promise<void>

export interface SwCallbacks {
  onNeedRefresh?: () => void
  onOfflineReady?: () => void
  onError?: (error: unknown) => void
}

type RegisterFn = (opts: {
  immediate: boolean
  onNeedRefresh?: () => void
  onOfflineReady?: () => void
  onRegisterError?: (e: unknown) => void
}) => ApplyUpdate

const noop: ApplyUpdate = async () => undefined

/**
 * Registra el SW y conecta callbacks de ciclo de vida.
 * Devuelve la funcion applyUpdate para disparar la actualizacion manualmente.
 *
 * @param callbacks - onNeedRefresh / onOfflineReady / onError
 * @param register  - inyectable en tests; en produccion se carga desde virtual:pwa-register
 */
export async function registerServiceWorker(
  callbacks: SwCallbacks,
  register?: RegisterFn,
): Promise<ApplyUpdate> {
  if (!register) {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
      return noop
    }
    try {
      // @ts-expect-error modulo virtual generado por vite-plugin-pwa
      const mod = await import('virtual:pwa-register')
      register = mod.registerSW as RegisterFn
    } catch {
      return noop
    }
  }

  try {
    const applyUpdate = register({
      immediate: true,
      onNeedRefresh: callbacks.onNeedRefresh,
      onOfflineReady: callbacks.onOfflineReady,
      onRegisterError: callbacks.onError,
    })
    return applyUpdate
  } catch (err) {
    callbacks.onError?.(err)
    return noop
  }
}
