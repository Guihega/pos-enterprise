/**
 * Cliente API para reserva de rangos de folios.
 * Stub — implementacion completa cuando el backend tenga POST /api/v1/folio-ranges/reserve.
 */

export interface ReserveFolioRangeParams {
  tenantSlug: string
  cashRegisterUuid: string
  series: string
  deviceId: string
  size?: number
}

export interface FolioRangeResponse {
  rangeStart: number
  rangeEnd: number
  series: string
  deviceId: string
}

export async function reserveFolioRange(params: ReserveFolioRangeParams): Promise<FolioRangeResponse> {
  const res = await fetch(`/api/v1/folio-ranges/reserve`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Tenant': params.tenantSlug,
    },
    body: JSON.stringify({
      cash_register_uuid: params.cashRegisterUuid,
      series: params.series,
      device_id: params.deviceId,
      size: params.size ?? 50,
    }),
    credentials: 'include',
  })

  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error((body as { message?: string }).message ?? `HTTP ${res.status}`)
  }

  return res.json() as Promise<FolioRangeResponse>
}
