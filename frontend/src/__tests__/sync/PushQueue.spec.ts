import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { PushQueue } from '@/sync/PushQueue'
import type { PushEvent } from '@/sync/PushQueue'
import { enqueue } from '@/repositories/SyncQueueRepository'
import { db } from '@/db/schema'
import type { SyncQueueItem } from '@/db/schema'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const baseItem = (over: Partial<Parameters<typeof enqueue>[0]> = {}) => ({
  clientUuid:      'client-001',
  entityType:      'sale' as const,
  entityUuid:      'entity-001',
  operation:       'create' as const,
  payload:         { total: 100 },
  clientTimestamp: '2026-01-01T00:00:00Z',
  ...over,
})

function okResponse(clientUuids: string[]) {
  return {
    ok: true,
    json: async () => ({
      batch_uuid: 'srv-batch-uuid',
      results: clientUuids.map(uuid => ({
        client_uuid: uuid,
        status:      'success',
        data:        { server_uuid: `srv-${uuid}` },
      })),
    }),
  }
}

function mixedResponse(map: Record<string, 'success' | 'conflict' | 'error'>) {
  return {
    ok: true,
    json: async () => ({
      batch_uuid: 'srv-batch-uuid',
      results: Object.entries(map).map(([uuid, status]) => ({
        client_uuid: uuid,
        status,
        message: status === 'success' ? undefined : `${status} msg`,
      })),
    }),
  }
}

async function getById(id: number): Promise<SyncQueueItem | undefined> {
  return db.syncQueue.get(id)
}

beforeEach(async () => {
  await db.syncQueue.clear()
  vi.restoreAllMocks()
})

afterEach(() => {
  vi.restoreAllMocks()
})

// ---------------------------------------------------------------------------
// Drenaje basico
// ---------------------------------------------------------------------------

describe('PushQueue.drainOnce — drenaje basico', () => {
  it('retorna vacio si no hay items pendientes', async () => {
    const pq = new PushQueue({ tenantSlug: 'demo' })
    const result = await pq.drainOnce()
    expect(result.sent).toBe(0)
    expect(result.networkError).toBe(false)
  })

  it('envia un item pendiente y lo marca success', async () => {
    const id = await enqueue(baseItem())

    const fetchMock = vi.fn().mockResolvedValue(okResponse(['client-001']))
    vi.stubGlobal('fetch', fetchMock)

    const pq = new PushQueue({ tenantSlug: 'demo' })
    const result = await pq.drainOnce()

    expect(result.sent).toBe(1)
    expect(result.succeeded).toBe(1)
    expect((await getById(id))?.status).toBe('success')
  })

  it('envia X-Tenant y batch_uuid en el body', async () => {
    await enqueue(baseItem())
    const fetchMock = vi.fn().mockResolvedValue(okResponse(['client-001']))
    vi.stubGlobal('fetch', fetchMock)

    const pq = new PushQueue({ tenantSlug: 'mi-tenant' })
    await pq.drainOnce()

    expect(fetchMock).toHaveBeenCalledOnce()
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toContain('/api/v1/sync/batch')
    expect(init.headers['X-Tenant']).toBe('mi-tenant')
    const body = JSON.parse(init.body)
    expect(body.batch_uuid).toBeTruthy()
    expect(body.items[0].client_uuid).toBe('client-001')
  })
})

// ---------------------------------------------------------------------------
// Resultados mixtos (sec. 38.3)
// ---------------------------------------------------------------------------

describe('PushQueue.drainOnce — aplica resultados', () => {
  it('marca success, conflict y error por separado', async () => {
    const idOk = await enqueue(baseItem({ clientUuid: 'c-ok',   entityUuid: 'e-ok' }))
    const idCf = await enqueue(baseItem({ clientUuid: 'c-conf', entityUuid: 'e-conf' }))
    const idEr = await enqueue(baseItem({ clientUuid: 'c-err',  entityUuid: 'e-err' }))

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(mixedResponse({
      'c-ok':   'success',
      'c-conf': 'conflict',
      'c-err':  'error',
    })))

    const pq = new PushQueue({ tenantSlug: 'demo' })
    const result = await pq.drainOnce()

    expect(result.succeeded).toBe(1)
    expect(result.conflicts).toBe(1)
    expect(result.failed).toBe(1)
    expect((await getById(idOk))?.status).toBe('success')
    expect((await getById(idCf))?.status).toBe('conflict')
    expect((await getById(idEr))?.status).toBe('pending')
    expect((await getById(idEr))?.attempts).toBe(1)
  })

  it('item error reagenda con nextAttemptAt futuro (backoff)', async () => {
    const id = await enqueue(baseItem({ clientUuid: 'c-err' }))
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(mixedResponse({ 'c-err': 'error' })))

    const before = Date.now()
    const pq = new PushQueue({ tenantSlug: 'demo' })
    await pq.drainOnce()

    const item = await getById(id)
    expect(new Date(item!.nextAttemptAt).getTime()).toBeGreaterThan(before)
  })

  it('si el servidor no retorna resultado para un item, lo marca failed', async () => {
    const id = await enqueue(baseItem({ clientUuid: 'c-1' }))
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ batch_uuid: 'b', results: [] }),
    }))

    const pq = new PushQueue({ tenantSlug: 'demo' })
    const result = await pq.drainOnce()

    expect(result.failed).toBe(1)
    expect((await getById(id))?.attempts).toBe(1)
  })
})

// ---------------------------------------------------------------------------
// Orden por entidad (sec. 38.4)
// ---------------------------------------------------------------------------

describe('PushQueue.drainOnce — orden por entidad (38.4)', () => {
  it('solo envia el primer item pendiente de cada entity_uuid', async () => {
    await enqueue(baseItem({ clientUuid: 'c-create', entityUuid: 'e-1', operation: 'create' }))
    await enqueue(baseItem({ clientUuid: 'c-delete', entityUuid: 'e-1', operation: 'delete' }))

    const fetchMock = vi.fn().mockResolvedValue(okResponse(['c-create']))
    vi.stubGlobal('fetch', fetchMock)

    const pq = new PushQueue({ tenantSlug: 'demo' })
    const result = await pq.drainOnce()

    expect(result.sent).toBe(1)
    const body = JSON.parse(fetchMock.mock.calls[0][1].body)
    expect(body.items).toHaveLength(1)
    expect(body.items[0].client_uuid).toBe('c-create')
  })

  it('entidades distintas si van juntas en el mismo batch', async () => {
    await enqueue(baseItem({ clientUuid: 'c-a', entityUuid: 'e-a' }))
    await enqueue(baseItem({ clientUuid: 'c-b', entityUuid: 'e-b' }))

    const fetchMock = vi.fn().mockResolvedValue(okResponse(['c-a', 'c-b']))
    vi.stubGlobal('fetch', fetchMock)

    const pq = new PushQueue({ tenantSlug: 'demo' })
    const result = await pq.drainOnce()

    expect(result.sent).toBe(2)
  })
})

// ---------------------------------------------------------------------------
// Errores de red (sec. 38.3)
// ---------------------------------------------------------------------------

describe('PushQueue.drainOnce — errores de red', () => {
  it('si fetch lanza, reagenda todos y marca networkError', async () => {
    const id = await enqueue(baseItem())
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network down')))

    const pq = new PushQueue({ tenantSlug: 'demo' })
    const result = await pq.drainOnce()

    expect(result.networkError).toBe(true)
    expect(result.sent).toBe(0)
    const item = await getById(id)
    expect(item?.status).toBe('pending')
    expect(item?.attempts).toBe(1)
  })

  it('si la respuesta no es ok, trata como error de red', async () => {
    await enqueue(baseItem())
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: false,
      status: 500,
      json: async () => ({ message: 'server error' }),
    }))

    const pq = new PushQueue({ tenantSlug: 'demo' })
    const result = await pq.drainOnce()

    expect(result.networkError).toBe(true)
  })
})

// ---------------------------------------------------------------------------
// Eventos
// ---------------------------------------------------------------------------

describe('PushQueue — eventos', () => {
  it('emite batch.start, item.success y batch.done', async () => {
    await enqueue(baseItem({ clientUuid: 'c-1' }))
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(okResponse(['c-1'])))

    const events: PushEvent[] = []
    const pq = new PushQueue({ tenantSlug: 'demo', onEvent: e => events.push(e) })
    await pq.drainOnce()

    const types = events.map(e => e.type)
    expect(types).toContain('sync.batch.start')
    expect(types).toContain('sync.item.success')
    expect(types).toContain('sync.batch.done')
  })

  it('emite sync.error en fallo de red', async () => {
    await enqueue(baseItem())
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('boom')))

    const events: PushEvent[] = []
    const pq = new PushQueue({ tenantSlug: 'demo', onEvent: e => events.push(e) })
    await pq.drainOnce()

    expect(events.some(e => e.type === 'sync.error')).toBe(true)
  })
})
