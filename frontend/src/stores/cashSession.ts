/**
 * Store de sesion de caja activa.
 *
 * El state autoritativo vive en el backend: cada vez que entras al POS,
 * preguntamos al backend si tienes una sesion abierta. NO persistimos
 * en localStorage (un dispositivo distinto podria haber cerrado tu
 * sesion).
 *
 * Acciones:
 *  - loadCurrent(): consulta GET /cash/sessions?status=open. Guarda la
 *    primera que aparezca (deberia haber 1 max por usuario activo).
 *  - loadRegisters(): consulta GET /cash/registers para el modal de
 *    apertura.
 *  - open(registerUuid, amount, notes): POST /cash/sessions/open.
 *    Guarda la sesion devuelta. Maneja 409 SESSION_ALREADY_OPEN
 *    refrescando la actual.
 *  - clear(): limpia el state (al logout).
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  closeCashSession,
  listCashRegisters,
  listCashSessions,
  openCashSession,
} from '@/lib/api/generated'
import type { CashRegister, CashSession } from '@/lib/api/generated'
import { errorCode, getTenantOrThrow, humanizeError } from '@/lib/api/errors'
import { needsRefill, refill, DEFAULT_SERIES, getDeviceId } from '@/lib/FolioGenerator'
import { useAuthStore } from '@/stores/auth'

export interface CloseResult {
  ok: boolean
  /** La sesion cerrada con su arqueo (closing) cuando ok = true. */
  session?: CashSession
  /** True si el cierre fallo porque la sesion ya no estaba abierta. */
  sessionLost?: boolean
}

export const useCashSessionStore = defineStore('cashSession', () => {
  const authStore = useAuthStore()

  // ---- state ----
  const currentSession = ref<CashSession | null>(null)
  const registers = ref<CashRegister[]>([])
  const loading = ref(false)
  const errorMessage = ref<string | null>(null)

  // ---- getter ----
  const hasActiveSession = computed(() => currentSession.value !== null)

  // ---- helpers ----

  // ---- actions ----

  /**
   * Carga la sesion activa para este tenant. Si encuentra al menos
   * una con status=open, la guarda en currentSession. Si no hay,
   * deja currentSession en null.
   */
  async function loadCurrent(): Promise<void> {
    loading.value = true
    errorMessage.value = null

    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await listCashSessions({
        headers: { 'X-Tenant': tenant },
        query: { status: 'open', per_page: 1 },
      })

      if (error || !data) {
        errorMessage.value = humanizeError(error, 'No se pudo consultar la sesion de caja.')
        return
      }

      currentSession.value = data.data.length > 0 ? (data.data[0] ?? null) : null
    } finally {
      loading.value = false
    }
  }

  /**
   * Carga las cajas registradoras disponibles para el modal de apertura.
   * Solo trae las activas.
   */
  async function loadRegisters(): Promise<void> {
    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await listCashRegisters({
        headers: { 'X-Tenant': tenant },
        query: { active: true, per_page: 200 },
      })

      if (error || !data) {
        errorMessage.value = humanizeError(error, 'No se pudieron cargar las cajas.')
        return
      }

      registers.value = data.data
    } catch (e) {
      errorMessage.value = humanizeError(e, 'No se pudieron cargar las cajas.')
    }
  }

  /**
   * Abre una sesion de caja.
   *
   * - 201: guarda la sesion en state.
   * - 409 SESSION_ALREADY_OPEN: la caja ya esta abierta por alguien
   *   (incluso este mismo usuario). Refrescamos `currentSession` por
   *   las dudas y comunicamos al UI con un mensaje claro.
   * - Otros errores: errorMessage.
   *
   * Devuelve true si la sesion quedo abierta y disponible en el state.
   */
  async function open(
    registerUuid: string,
    openingAmount: number,
    openingNotes: string | null,
  ): Promise<boolean> {
    loading.value = true
    errorMessage.value = null

    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await openCashSession({
        headers: { 'X-Tenant': tenant },
        body: {
          cash_register_uuid: registerUuid,
          opening_amount: openingAmount,
          opening_notes: openingNotes,
        },
      })

      if (data && !error) {
        currentSession.value = data.data
        await ensureFolioRange()
        return true
      }

      const code = errorCode(error)
      if (code === 'SESSION_ALREADY_OPEN') {
        // Intentar refrescar por si la sesion abierta es la del usuario
        // y queremos engancharnos a ella. Importante: loadCurrent()
        // resetea errorMessage al inicio, asi que el mensaje se asigna
        // DESPUES para evitar que sea pisado.
        await loadCurrent()
        errorMessage.value = 'Esta caja ya tiene una sesion abierta.'
        return currentSession.value !== null
      }

      errorMessage.value = humanizeError(error, 'No se pudo abrir la caja.')
      return false
    } finally {
      loading.value = false
    }
  }

  /**
   * Cierra la sesion de caja activa.
   *
   * Cierre a ciegas: el cajero provee el monto contado; el backend
   * calcula el esperado y la diferencia. Devuelve la sesion cerrada
   * con su bloque `closing` para mostrar el arqueo.
   *
   * - 200: limpia currentSession (la caja quedo cerrada, debe
   *   reaparecer el modal de apertura) y devuelve la sesion cerrada.
   * - 409 SESSION_NOT_OPEN: la sesion ya no estaba abierta. Refresca
   *   currentSession y marca sessionLost.
   * - Otros errores: errorMessage.
   */
  async function close(
    sessionUuid: string,
    countedAmount: number,
    closingNotes: string | null,
  ): Promise<CloseResult> {
    loading.value = true
    errorMessage.value = null

    try {
      const tenant = getTenantOrThrow(authStore.tenant)
      const { data, error } = await closeCashSession({
        headers: { 'X-Tenant': tenant },
        path: { session: sessionUuid },
        body: {
          counted_amount: countedAmount,
          closing_notes: closingNotes,
        },
      })

      if (data && !error) {
        const closed = data.data
        currentSession.value = null
        return { ok: true, session: closed }
      }

      const code = errorCode(error)
      if (code === 'SESSION_NOT_OPEN') {
        await loadCurrent()
        errorMessage.value =
          'La sesion de caja ya no esta abierta. Recarga el POS.'
        return { ok: false, sessionLost: true }
      }

      errorMessage.value = humanizeError(error, 'No se pudo cerrar la caja.')
      return { ok: false }
    } finally {
      loading.value = false
    }
  }

  /** Limpia el state. Llamar al logout. */
  function clear(): void {
    currentSession.value = null
    registers.value = []
    errorMessage.value = null
  }

  /**
   * Reserva un rango de folios para la caja activa si hace falta.
   * Best-effort: si no hay conexion o el backend falla, NO rompe la
   * apertura de caja. La venta offline avisara con FolioExhaustedError
   * si finalmente no hay rango. Requiere conexion para reservar.
   */
  async function ensureFolioRange(): Promise<void> {
    const cashRegisterUuid = currentSession.value?.register?.uuid
    if (!cashRegisterUuid) return
    if (!navigator.onLine) return
    try {
      if (!(await needsRefill(cashRegisterUuid, DEFAULT_SERIES))) return
      const tenant = getTenantOrThrow(authStore.tenant)
      const deviceId = await getDeviceId()
      await refill(cashRegisterUuid, DEFAULT_SERIES, tenant, deviceId)
    } catch {
      // Silencioso: la apertura de caja no debe fallar por la reserva.
    }
  }

  return {
    // state
    currentSession,
    registers,
    loading,
    errorMessage,
    // getter
    hasActiveSession,
    // actions
    loadCurrent,
    loadRegisters,
    open,
    close,
    clear,
  }
})
