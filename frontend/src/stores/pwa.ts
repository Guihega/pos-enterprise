import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { ApplyUpdate } from '@/sw/registerServiceWorker'

/**
 * Store del ciclo de vida del Service Worker / PWA (doc maestro 37.2).
 *
 * Estrategia immediate-notify + manual-approve:
 *  - needRefresh=true cuando hay una version nueva descargada (notificar).
 *  - applyUpdate() activa el SW nuevo y recarga, SOLO cuando el usuario lo
 *    aprueba con el boton "Actualizar ahora". Nunca se activa solo (37.2:
 *    evita interrumpir a media venta).
 *  - offlineReady=true cuando la app quedo cacheada y lista para uso offline.
 *
 * main.ts conecta los callbacks de registerServiceWorker a este store.
 */
export const usePwaStore = defineStore('pwa', () => {
  /** Hay una version nueva del SW lista para activarse (37.2). */
  const needRefresh = ref<boolean>(false)
  /** La app quedo lista para funcionar offline (37.1). */
  const offlineReady = ref<boolean>(false)
  /** True mientras se aplica la actualizacion (evita doble click). */
  const updating = ref<boolean>(false)

  /** Funcion que activa el SW nuevo y recarga. La inyecta el registro. */
  let applyUpdateFn: ApplyUpdate | null = null

  /** Llamado por el SW cuando hay nueva version disponible. */
  function setNeedRefresh(value: boolean): void {
    needRefresh.value = value
  }

  /** Llamado por el SW cuando la app quedo lista offline. */
  function setOfflineReady(value: boolean): void {
    offlineReady.value = value
  }

  /** Registra la funcion de actualizacion devuelta por registerServiceWorker. */
  function setApplyUpdate(fn: ApplyUpdate): void {
    applyUpdateFn = fn
  }

  /**
   * Aplica la actualizacion: activa el SW nuevo y recarga la pagina (37.2).
   * Solo se invoca desde el boton "Actualizar ahora". No-op si no hay
   * funcion registrada.
   */
  async function applyUpdate(): Promise<void> {
    if (!applyUpdateFn) return
    updating.value = true
    try {
      await applyUpdateFn(true)
    } finally {
      // La recarga normalmente reemplaza la pagina; el reset es por si no.
      updating.value = false
    }
  }

  /** El usuario descarta la notificacion sin actualizar (37.2: puede ignorar). */
  function dismiss(): void {
    needRefresh.value = false
  }

  return {
    // state
    needRefresh,
    offlineReady,
    updating,
    // actions
    setNeedRefresh,
    setOfflineReady,
    setApplyUpdate,
    applyUpdate,
    dismiss,
  }
})
