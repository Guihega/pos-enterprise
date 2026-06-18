/**
 * Tests de HeartbeatClient (Fase 2, Iteracion 2).
 *
 * jsdom no trae fetch: se stubea con vi.stubGlobal. Las funciones puras
 * de drift se prueban sin red.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HeartbeatClient,
  HeartbeatError,
  computeClockDrift,
  classifyDrift,
  DRIFT_WARNING_MS,
  DRIFT_BLOCK_MS,
} from '@/sync/HeartbeatClient'

const fetchMock = vi.fn()
vi.stubGlobal('fetch', fetchMock)

function heartbeatResponse(body: Record<string, unknown>, ok = true, status = 200) {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  }
}

function makeClient(opts: Record<string, unknown> = {}) {
  return new HeartbeatClient({ tenantSlug: 'demo', ...opts })
}

beforeEach(() => { vi.clearAllMocks() })
afterEach(() => { vi.clearAllMocks() })

// ---------------------------------------------------------------------------
// Funciones puras de drift (sec. 42.5)
// ---------------------------------------------------------------------------

describe('computeClockDrift', () => {
  it('cliente adelantado da drift positivo', () => {
    const server = '2026-06-18T12:00:00Z'
    const clientNow = new Date('2026-06-18T12:10:00Z').getTime()
    expect(computeClockDrift(server, clientNow)).toBe(10 * 60 * 1000)
  })

  it('cliente atrasado da drift negativo', () => {
    const server = '2026-06-18T12:00:00Z'
    const clientNow = new Date('2026-06-18T11:50:00Z').getTime()
    expect(computeClockDrift(server, clientNow)).toBe(-10 * 60 * 1000)
  })

  it('relojes iguales da drift cero', () => {
    const server = '2026-06-18T12:00:00Z'
    const clientNow = new Date('2026-06-18T12:00:00Z').getTime()
    expect(computeClockDrift(server, clientNow)).toBe(0)
  })
})

describe('classifyDrift', () => {
  it('drift bajo el umbral de warning es ok', () => {
    expect(classifyDrift(DRIFT_WARNING_MS - 1)).toBe('ok')
  })

  it('drift en el umbral de warning es warning', () => {
    expect(classifyDrift(DRIFT_WARNING_MS)).toBe('warning')
  })

  it('drift en el umbral de bloqueo es blocked', () => {
    expect(classifyDrift(DRIFT_BLOCK_MS)).toBe('blocked')
  })

  it('clasifica por valor absoluto (atrasado tambien cuenta)', () => {
    expect(classifyDrift(-(DRIFT_BLOCK_MS + 1000))).toBe('blocked')
    expect(classifyDrift(-(DRIFT_WARNING_MS + 1000))).toBe('warning')
  })

  it('umbrales: 5 min warning, 30 min blocked', () => {
    expect(DRIFT_WARNING_MS).toBe(5 * 60 * 1000)
    expect(DRIFT_BLOCK_MS).toBe(30 * 60 * 1000)
  })
})

// ---------------------------------------------------------------------------
// ping
// ---------------------------------------------------------------------------

describe('HeartbeatClient.ping', () => {
  it('GET a /api/v1/sync/heartbeat con X-Tenant', async () => {
    fetchMock.mockResolvedValueOnce(
      heartbeatResponse({ server_time: '2026-06-18T12:00:00Z', tenant: 'demo', user_uuid: 'u-1' }),
    )

    const result = await makeClient().ping()

    expect(fetchMock).toHaveBeenCalledOnce()
    const [url, opts] = fetchMock.mock.calls[0]!
    expect(url).toContain('/api/v1/sync/heartbeat')
    expect((opts as RequestInit).headers).toMatchObject({ 'X-Tenant': 'demo' })
    expect(result.serverTime).toBe('2026-06-18T12:00:00Z')
    expect(result.tenant).toBe('demo')
    expect(result.userUuid).toBe('u-1')
  })

  it('usa apiBase cuando se proporciona', async () => {
    fetchMock.mockResolvedValueOnce(
      heartbeatResponse({ server_time: '2026-06-18T12:00:00Z', tenant: 'demo', user_uuid: 'u-1' }),
    )
    await makeClient({ apiBase: 'https://api.test' }).ping()
    expect(fetchMock.mock.calls[0]![0]).toContain('https://api.test/api/v1/sync/heartbeat')
  })

  it('lanza HeartbeatError status 0 en error de red', async () => {
    fetchMock.mockRejectedValueOnce(new Error('Network down'))
    await expect(makeClient().ping()).rejects.toMatchObject({
      name: 'HeartbeatError',
      status: 0,
    })
  })

  it('lanza HeartbeatError con status 401 (token revocado)', async () => {
    fetchMock.mockResolvedValueOnce(heartbeatResponse({}, false, 401))
    await expect(makeClient().ping()).rejects.toMatchObject({
      name: 'HeartbeatError',
      status: 401,
    })
  })

  it('lanza HeartbeatError con status 402 (tenant suspendido)', async () => {
    fetchMock.mockResolvedValueOnce(heartbeatResponse({}, false, 402))
    const err = await makeClient().ping().catch((e) => e)
    expect(err).toBeInstanceOf(HeartbeatError)
    expect(err.status).toBe(402)
  })
})

// ---------------------------------------------------------------------------
// pingWithDrift
// ---------------------------------------------------------------------------

describe('HeartbeatClient.pingWithDrift', () => {
  it('calcula drift y severidad ok cuando relojes alineados', async () => {
    fetchMock.mockResolvedValueOnce(
      heartbeatResponse({ server_time: '2026-06-18T12:00:00Z', tenant: 'demo', user_uuid: 'u-1' }),
    )
    const clientNow = new Date('2026-06-18T12:00:30Z').getTime() // 30s
    const { driftMs, severity } = await makeClient().pingWithDrift(clientNow)
    expect(driftMs).toBe(30 * 1000)
    expect(severity).toBe('ok')
  })

  it('severidad warning con 10 min de drift', async () => {
    fetchMock.mockResolvedValueOnce(
      heartbeatResponse({ server_time: '2026-06-18T12:00:00Z', tenant: 'demo', user_uuid: 'u-1' }),
    )
    const clientNow = new Date('2026-06-18T12:10:00Z').getTime()
    const { severity } = await makeClient().pingWithDrift(clientNow)
    expect(severity).toBe('warning')
  })

  it('severidad blocked con 40 min de drift', async () => {
    fetchMock.mockResolvedValueOnce(
      heartbeatResponse({ server_time: '2026-06-18T12:00:00Z', tenant: 'demo', user_uuid: 'u-1' }),
    )
    const clientNow = new Date('2026-06-18T12:40:00Z').getTime()
    const { severity } = await makeClient().pingWithDrift(clientNow)
    expect(severity).toBe('blocked')
  })

  it('propaga HeartbeatError si el ping falla', async () => {
    fetchMock.mockResolvedValueOnce(heartbeatResponse({}, false, 401))
    await expect(makeClient().pingWithDrift()).rejects.toBeInstanceOf(HeartbeatError)
  })
})
