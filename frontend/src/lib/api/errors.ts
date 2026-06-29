/**
 * Helpers compartidos para llamadas al SDK Hey API.
 *
 * Toda llamada al SDK devuelve `{ data, error }`. Cuando hay error,
 * el shape sigue el ErrorEnvelope del backend:
 *
 *   { error: { code, message, details, request_id, timestamp } }
 *
 * Estos helpers extraen `code` y `message` defensivamente (sin
 * confiar en el shape exacto) y convierten el error en algo
 * presentable para el UI.
 */

/**
 * Devuelve el tenant activo o lanza si no hay. Llamar al inicio de
 * cada accion del store: si no hay tenant, algo se rompio en el
 * bootstrap o el usuario quedo en limbo.
 */
export function getTenantOrThrow(tenant: string | null): string {
  if (!tenant) {
    throw new Error('No hay tenant activo')
  }
  return tenant
}

/**
 * Extrae el codigo de error del ErrorEnvelope (ej. 'PAYMENT_MISMATCH',
 * 'SESSION_NOT_OPEN'). Devuelve null para 401, 422 (validacion
 * Laravel nativa), errores de red, o cualquier shape inesperado.
 */
export function errorCode(err: unknown): string | null {
  if (err && typeof err === 'object' && 'error' in err) {
    const errObj = (err as { error?: { code?: string } }).error
    if (errObj?.code) {
      return errObj.code
    }
  }
  return null
}

/**
 * Convierte el error del SDK en un mensaje legible. Prioriza
 * `error.message` del backend; si no esta, devuelve `fallback`.
 *
 * NO inspecciona `error.details`: si una accion necesita detalles
 * (ej. ACCOUNT_LOCKED.seconds_remaining), debe parsearlos por su
 * cuenta y construir el mensaje.
 */
export function humanizeError(err: unknown, fallback: string): string {
  if (err && typeof err === 'object' && 'error' in err) {
    const errObj = (err as { error?: { message?: string } }).error
    if (errObj?.message) {
      return errObj.message
    }
  }
  return fallback
}

/**
 * Como humanizeError, pero ademas inspecciona el shape de validacion 422
 * de Laravel ({ message, errors: { campo: [msg] } }) y devuelve el primer
 * mensaje de error de campo. Orden de prioridad:
 *   1. error.errors -> primer mensaje del primer campo.
 *   2. error.error.message (ErrorEnvelope).
 *   3. fallback.
 * Util en formularios CRUD donde el backend valida campos unique
 * (ej. code, tax_id, email) y el usuario necesita saber cual fallo.
 */
export function humanizeValidationError(err: unknown, fallback: string): string {
  if (
    err &&
    typeof err === 'object' &&
    'errors' in err &&
    typeof (err as { errors?: unknown }).errors === 'object' &&
    (err as { errors?: unknown }).errors !== null
  ) {
    const validationErrors = (err as { errors: Record<string, string[]> }).errors
    const firstKey = Object.keys(validationErrors)[0]
    const firstList = firstKey ? validationErrors[firstKey] : undefined
    if (firstList && firstList[0]) {
      return firstList[0]
    }
  }
  return humanizeError(err, fallback)
}
