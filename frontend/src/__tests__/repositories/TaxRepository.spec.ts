/**
 * Tests de TaxRepository (Fase 2, Iteracion 2).
 * Dexie opera sobre fake-indexeddb (setup.ts). Cada test empieza limpio.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { db } from '@/db/schema'
import { upsertMany, deleteMany } from '@/repositories/TaxRepository'

function makeTax(uuid: string, name = `Impuesto ${uuid}`) {
  return {
    uuid,
    code:        `TAX-${uuid}`,
    name,
    description: null,
    rate:        0.16,
    rate_percent: 16,
    type:        null,
    is_inclusive: true,
    is_active:   true,
    is_default:  false,
    created_at:  '2026-01-01T00:00:00Z',
    updated_at:  '2026-01-01T00:00:00Z',
  }
}

beforeEach(async () => { await db.taxes.clear() })
afterEach(async  () => { await db.taxes.clear() })

describe('upsertMany', () => {
  it('no falla con lista vacia', async () => {
    await expect(upsertMany([])).resolves.toBeUndefined()
  })

  it('crea impuestos nuevos', async () => {
    await upsertMany([makeTax('t-1'), makeTax('t-2')])
    expect(await db.taxes.count()).toBe(2)
  })

  it('actualiza impuesto existente (semantica upsert)', async () => {
    await db.taxes.put({ uuid: 't-1', code: 'OLD', name: 'Viejo', rate: 0.08, isInclusive: false })
    await upsertMany([makeTax('t-1', 'Nuevo')])
    const tax = await db.taxes.get('t-1')
    expect(tax?.name).toBe('Nuevo')
    expect(tax?.rate).toBe(0.16)
  })

  it('mapea is_inclusive -> isInclusive correctamente', async () => {
    await upsertMany([{ ...makeTax('t-1'), is_inclusive: false }])
    const tax = await db.taxes.get('t-1')
    expect(tax?.isInclusive).toBe(false)
  })
})

describe('deleteMany', () => {
  it('no falla con lista vacia', async () => {
    await expect(deleteMany([])).resolves.toBeUndefined()
  })

  it('elimina impuestos por uuid', async () => {
    await db.taxes.bulkPut([
      { uuid: 't-1', code: 'T1', name: 'A', rate: 0.16, isInclusive: true },
      { uuid: 't-2', code: 'T2', name: 'B', rate: 0.08, isInclusive: false },
    ])
    await deleteMany(['t-1'])
    expect(await db.taxes.count()).toBe(1)
    expect(await db.taxes.get('t-1')).toBeUndefined()
    expect(await db.taxes.get('t-2')).toBeDefined()
  })

  it('ignora uuids inexistentes', async () => {
    await db.taxes.put({ uuid: 't-1', code: 'T1', name: 'A', rate: 0.16, isInclusive: true })
    await deleteMany(['no-existe'])
    expect(await db.taxes.count()).toBe(1)
  })
})
