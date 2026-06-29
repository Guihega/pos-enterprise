/**
 * HeartbeatClient — sonda de conectividad/auth y reloj del servidor.
 *
 * Doc maestro sec. 35.4 paso 7 (heartbeat periodico) y sec. 42.5 (reloj
 * del dispositivo desincronizado).
 *
 * GET /api/v1/sync/heartbeat devuelve { server_time, tenant, user_uuid }.
 * Sirve para:
 *   - Confirmar que hay red Y el servidor responde (distingue 'online real'
 *     de 'navigator.onLine pero servidor caido' -> estado degraded, 35.5).
 *   - Detectar 401 (token revocado, sec. 42.6).
 *   - Medir el drift entre el reloj del cliente y el del servidor (42.5).
 *
 * NO hace scheduling (eso es BackgroundSync) ni decide que hacer con el
 * drift (eso lo decide el caller segun 42.5). Solo provee el dato.
 */

// ---------------------------------------------------------------------------
// Contrato con GET /api/v1/sync/heartbeat
// ---------------------------------------------------------------------------

interface HeartbeatResponseBody {
  server_time: string
  tenant:      string | null
  user_uuid:   string | null
}

export interface HeartbeatResult {
  /** ISO 8601 del reloj del servidor. */
  serverTime: string
  tenant:     string | null
  userUuid:   string | null
}

/** Error con el status HTTP para que el caller distinga 401 (revocado). */
export class HeartbeatError extends Error {
  constructor(
    message: string,
    /** status HTTP, o 0 si fue error de red. */
    public readonly status: number,
  ) {
    super(message)
    this.name = 'HeartbeatError'
  }
}

// ---------------------------------------------------------------------------
// Clasificacion de drift (sec. 42.5)
// ---------------------------------------------------------------------------

export type DriftSeverity = 'ok' | 'warning' | 'blocked'

/** Umbral de advertencia: 5 minutos (sec. 42.5). */
export const DRIFT_WARNING_MS = 5 * 60 * 1000
/** Umbral de bloqueo: 30 minutos (sec. 42.5). */
export const DRIFT_BLOCK_MS = 30 * 60 * 1000

/**
 * Diferencia (ms) entre el reloj del cliente y el del servidor.
 * Positivo => el cliente va adelantado respecto al servidor.
 */
export function computeClockDrift(serverTime: string, clientNowMs: number): number {
  return clientNowMs - new Date(serverTime).getTime()
}

/**
 * Clasifica el drift segun los umbrales de 42.5. Usa el valor absoluto:
 * adelantado o atrasado importan por igual.
 */
export function classifyDrift(driftMs: number): DriftSeverity {
  const abs = Math.abs(driftMs)
  if (abs >= DRIFT_BLOCK_MS) return 'blocked'
  if (abs >= DRIFT_WARNING_MS) return 'warning'
  return 'ok'
}

// ---------------------------------------------------------------------------
// HeartbeatClient
// ---------------------------------------------------------------------------

export interface HeartbeatClientOptions {
  /** Slug del tenant activo — se envia como X-Tenant. */
  tenantSlug: string
  /** URL base de la API (default: ''). Ya incluye /api/v1. */
  apiBase?:   string
  /** Token Bearer para autenticar el heartbeat (endpoint bajo auth:sanctum). */
  authToken?: string
  /** Senal de aborto para cancelar el fetch en curso. */
  signal?:    AbortSignal
}

export class HeartbeatClient {
  private tenantSlug: string
  private apiBase:    string
  private authToken:  string
  private signal?:    AbortSignal

  constructor(opts: HeartbeatClientOptions) {
    this.tenantSlug = opts.tenantSlug
    this.apiBase    = opts.apiBase ?? ''
    this.authToken  = opts.authToken ?? ''
    this.signal     = opts.signal
  }

  /**
   * Hace una llamada al heartbeat. Lanza HeartbeatError si falla la red
   * (status 0) o si el servidor responde con error (status >= 400, incluido
   * 401 por token revocado).
   */
  async ping(): Promise<HeartbeatResult> {
    let res: Response
    try {
      res = await fetch(`${this.apiBase}/sync/heartbeat`, {
        method:  'GET',
        headers: {
          'X-Tenant':      this.tenantSlug,
          'Authorization': `Bearer ${this.authToken}`,
        },
        signal:  this.signal ?? null,
      })
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'network error'
      throw new HeartbeatError(msg, 0)
    }

    if (!res.ok) {
      throw new HeartbeatError(`HTTP ${res.status}`, res.status)
    }

    const body = (await res.json()) as HeartbeatResponseBody
    return {
      serverTime: body.server_time,
      tenant:     body.tenant,
      userUuid:   body.user_uuid,
    }
  }

  /**
   * Hace ping y calcula el drift contra el reloj local en ese momento.
   * Devuelve el resultado del heartbeat + drift en ms + severidad (42.5).
   */
  async pingWithDrift(clientNowMs: number = Date.now()): Promise<{
    result:   HeartbeatResult
    driftMs:  number
    severity: DriftSeverity
  }> {
    const result = await this.ping()
    const driftMs = computeClockDrift(result.serverTime, clientNowMs)
    return { result, driftMs, severity: classifyDrift(driftMs) }
  }
}
