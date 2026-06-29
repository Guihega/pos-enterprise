/**
 * Cliente API para reserva de rangos de folios.
 */
export interface ReserveFolioRangeParams {
  tenantSlug: string
  cashRegisterUuid: string
  series: string
  deviceId: string
  authToken: string
  size?: number
}
export interface FolioRangeResponse {
  rangeStart: number
  rangeEnd: number
  series: string
  deviceId: string
}
export async function reserveFolioRange(params: ReserveFolioRangeParams): Promise<FolioRangeResponse> {
  const base = (import.meta.env.VITE_API_URL as string) ?? '/api/v1'
  const res = await fetch(`${base}/folio-ranges/reserve`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Tenant': params.tenantSlug,
      'Authorization': `Bearer ${params.authToken}`,
    },
    body: JSON.stringify({
      cash_register_uuid: params.cashRegisterUuid,
      series: params.series,
      device_id: params.deviceId,
      size: params.size ?? 50,
    }),
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error((body as { message?: string }).message ?? `HTTP ${res.status}`)
  }
  // Backend devuelve snake_case: range_start, range_end, device_id
  const raw = await res.json() as { range_start: number; range_end: number; series: string; device_id: string }
  return {
    rangeStart: raw.range_start,
    rangeEnd: raw.range_end,
    series: raw.series,
    deviceId: raw.device_id,
  }
}
