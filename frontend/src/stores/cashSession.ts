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
  listCashRegisters,
  listCashSessions,
  openCashSession,
} from '@/lib/api/generated'
import type { CashRegister, CashSession } from '@/lib/api/generated'
import { useAuthStore } from '@/stores/auth'

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
  function getTenantOrThrow(): string {
    const t = authStore.tenant
    if (!t) {
      throw new Error('No hay tenant activo')
    }
    return t
  }

  function humanizeError(err: unknown, fallback: string): string {
    if (err && typeof err === 'object' && 'error' in err) {
      const errObj = (err as { error?: { message?: string } }).error
      if (errObj?.message) {
        return errObj.message
      }
    }
    return fallback
  }

  function errorCode(err: unknown): string | null {
    if (err && typeof err === 'object' && 'error' in err) {
      const errObj = (err as { error?: { code?: string } }).error
      if (errObj?.code) {
        return errObj.code
      }
    }
    return null
  }

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
      const tenant = getTenantOrThrow()
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
      const tenant = getTenantOrThrow()
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
      const tenant = getTenantOrThrow()
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

  /** Limpia el state. Llamar al logout. */
  function clear(): void {
    currentSession.value = null
    registers.value = []
    errorMessage.value = null
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
    clear,
  }
})
