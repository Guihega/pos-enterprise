/**
 * FolioGenerator — genera folios locales a partir de rangos reservados en Dexie.
 *
 * ADR-0009: el servidor reserva rangos disjuntos por dispositivo/caja.
 * El cliente consume nextValue atomicamente sin necesitar red.
 */
import { db, SETTING_DEVICE_ID } from '@/db/schema'
import type { FolioRangeLocal } from '@/db/schema'
import { reserveFolioRange } from '@/lib/api/folio'

/** Serie de folios por defecto. El backend aun no expone serie por caja/sucursal (CashRegister no trae series); cuando lo haga, se cambia aqui. */
export const DEFAULT_SERIES = 'A'

/** Lee el device_id persistido en settings. Vacio si aun no se registro el dispositivo. */
export async function getDeviceId(): Promise<string> {
  const setting = await db.settings.get(SETTING_DEVICE_ID)
  return typeof setting?.value === 'string' ? setting.value : ''
}

export class FolioExhaustedError extends Error {
  constructor(cashRegisterUuid: string, series: string) {
    super(`Sin rango de folios activo para caja ${cashRegisterUuid} serie ${series}. Abre caja con conexion para reservar un rango.`)
    this.name = 'FolioExhaustedError'
  }
}

/** Folios que quedan antes de pedir reabastecimiento. */
const REFILL_THRESHOLD = 10

function rangeId(cashRegisterUuid: string, series: string, deviceId: string): string {
  return `${cashRegisterUuid}:${series}:${deviceId}`
}

function formatFolio(series: string, value: number): string {
  return `${series}${value.toString().padStart(6, '0')}`
}

/**
 * Devuelve el proximo folio disponible para la caja/serie dada.
 * Incrementa nextValue atomicamente en Dexie (sin red).
 * Lanza FolioExhaustedError si no hay rango activo.
 */
export async function nextFolio(cashRegisterUuid: string, series: string): Promise<string> {
  let folio: string | null = null

  await db.transaction('rw', db.folioRanges, async () => {
    const range = await db.folioRanges
      .where('cashRegisterUuid').equals(cashRegisterUuid)
      .filter(r => r.series === series && r.nextValue <= r.rangeEnd)
      .first()

    if (!range) {
      throw new FolioExhaustedError(cashRegisterUuid, series)
    }

    folio = formatFolio(range.series, range.nextValue)
    await db.folioRanges.update(range.id, { nextValue: range.nextValue + 1 })
  })

  if (!folio) throw new FolioExhaustedError(cashRegisterUuid, series)
  return folio
}

/**
 * Devuelve true si el rango activo esta por agotarse (remaining <= REFILL_THRESHOLD)
 * o si no hay rango activo.
 */
export async function needsRefill(cashRegisterUuid: string, series: string): Promise<boolean> {
  const range = await db.folioRanges
    .where('cashRegisterUuid').equals(cashRegisterUuid)
    .filter(r => r.series === series && r.nextValue <= r.rangeEnd)
    .first()

  if (!range) return true
  return (range.rangeEnd - range.nextValue + 1) <= REFILL_THRESHOLD
}

/**
 * Solicita al servidor un nuevo rango y lo persiste en Dexie.
 * Requiere conexion. Llamar desde apertura de caja o cuando needsRefill() === true.
 */
export async function refill(
  cashRegisterUuid: string,
  series: string,
  tenantSlug: string,
  deviceId: string,
): Promise<void> {
  const { rangeStart, rangeEnd } = await reserveFolioRange({
    tenantSlug,
    cashRegisterUuid,
    series,
    deviceId,
  })

  const entry: FolioRangeLocal = {
    id: rangeId(cashRegisterUuid, series, deviceId),
    cashRegisterUuid,
    series,
    deviceId,
    rangeStart,
    rangeEnd,
    nextValue: rangeStart,
    syncedAt: new Date().toISOString(),
  }

  await db.folioRanges.put(entry)
}
