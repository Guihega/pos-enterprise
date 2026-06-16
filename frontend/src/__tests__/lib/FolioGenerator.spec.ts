import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextFolio, needsRefill, refill, FolioExhaustedError } from '@/lib/FolioGenerator'
import { db } from '@/db/schema'

vi.mock('@/lib/api/folio', () => ({
  reserveFolioRange: vi.fn<typeof import('@/lib/api/folio').reserveFolioRange>(),
}))

import { reserveFolioRange } from '@/lib/api/folio'

const CR = 'cr-uuid-001'
const SERIES = 'A'
const DEVICE = 'device-uuid-001'

async function seedRange(rangeStart: number, rangeEnd: number, nextValue: number) {
  await db.folioRanges.put({
    id: `${CR}:${SERIES}:${DEVICE}`,
    cashRegisterUuid: CR,
    series: SERIES,
    deviceId: DEVICE,
    rangeStart,
    rangeEnd,
    nextValue,
    syncedAt: '2026-01-01T00:00:00Z',
  })
}

beforeEach(async () => {
  await db.folioRanges.clear()
  vi.clearAllMocks()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('nextFolio', () => {
  it('devuelve folio formateado y avanza nextValue', async () => {
    await seedRange(1, 50, 1)
    const folio = await nextFolio(CR, SERIES)
    expect(folio).toBe('A000001')
    const range = await db.folioRanges.get(`${CR}:${SERIES}:${DEVICE}`)
    expect(range?.nextValue).toBe(2)
  })

  it('folios consecutivos no se repiten', async () => {
    await seedRange(1, 50, 1)
    const f1 = await nextFolio(CR, SERIES)
    const f2 = await nextFolio(CR, SERIES)
    const f3 = await nextFolio(CR, SERIES)
    expect(f1).toBe('A000001')
    expect(f2).toBe('A000002')
    expect(f3).toBe('A000003')
  })

  it('usa el ultimo folio del rango sin lanzar error', async () => {
    await seedRange(49, 50, 50)
    const folio = await nextFolio(CR, SERIES)
    expect(folio).toBe('A000050')
  })

  it('lanza FolioExhaustedError si no hay rango', async () => {
    await expect(nextFolio(CR, SERIES)).rejects.toBeInstanceOf(FolioExhaustedError)
  })

  it('lanza FolioExhaustedError si rango agotado (nextValue > rangeEnd)', async () => {
    await seedRange(1, 50, 51)
    await expect(nextFolio(CR, SERIES)).rejects.toBeInstanceOf(FolioExhaustedError)
  })
})

describe('needsRefill', () => {
  it('retorna true si no hay rango', async () => {
    expect(await needsRefill(CR, SERIES)).toBe(true)
  })

  it('retorna true si remaining <= 10', async () => {
    await seedRange(1, 50, 41)  // remaining = 50-41+1 = 10
    expect(await needsRefill(CR, SERIES)).toBe(true)
  })

  it('retorna false si remaining > 10', async () => {
    await seedRange(1, 50, 40)  // remaining = 50-40+1 = 11
    expect(await needsRefill(CR, SERIES)).toBe(false)
  })

  it('retorna true si rango agotado', async () => {
    await seedRange(1, 50, 51)
    expect(await needsRefill(CR, SERIES)).toBe(true)
  })
})

describe('refill', () => {
  it('persiste el rango recibido del servidor en Dexie', async () => {
    vi.mocked(reserveFolioRange).mockResolvedValue({
      rangeStart: 101,
      rangeEnd: 150,
      series: SERIES,
      deviceId: DEVICE,
    })

    await refill(CR, SERIES, 'acme', DEVICE)

    const range = await db.folioRanges.get(`${CR}:${SERIES}:${DEVICE}`)
    expect(range?.rangeStart).toBe(101)
    expect(range?.rangeEnd).toBe(150)
    expect(range?.nextValue).toBe(101)
  })

  it('sobreescribe rango existente con put', async () => {
    await seedRange(1, 50, 30)
    vi.mocked(reserveFolioRange).mockResolvedValue({
      rangeStart: 201,
      rangeEnd: 250,
      series: SERIES,
      deviceId: DEVICE,
    })

    await refill(CR, SERIES, 'acme', DEVICE)

    const range = await db.folioRanges.get(`${CR}:${SERIES}:${DEVICE}`)
    expect(range?.rangeStart).toBe(201)
    expect(range?.nextValue).toBe(201)
  })

  it('lanza si el servidor falla', async () => {
    vi.mocked(reserveFolioRange).mockRejectedValue(new Error('red caida'))
    await expect(refill(CR, SERIES, 'acme', DEVICE)).rejects.toThrow('red caida')
  })
})
