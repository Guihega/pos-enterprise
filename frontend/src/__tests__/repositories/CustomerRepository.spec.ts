/**
 * Tests de CustomerRepository (Fase 2, Iteracion 2).
 * Dexie opera sobre fake-indexeddb (setup.ts). Cada test empieza limpio.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { db } from '@/db/schema'
import { upsertMany, deleteMany } from '@/repositories/CustomerRepository'

function makeApiCustomer(uuid: string, name = `Cliente ${uuid}`, updatedAt = '2026-01-01T00:00:00Z') {
  return {
    uuid,
    code:       `C-${uuid}`,
    type:       'individual' as const,
    name,
    legal_name: null,
    tax:        { tax_id: null, data: null },
    contact:    { email: `${uuid}@test.com`, phone: null, mobile: null },
    address:    { line: null, city: null, state: null, postal_code: null, country_code: null },
    credit:     { limit: 0, balance: 0, available: 0 },
    flags:      { is_active: true, is_blocked: false, blocked_reason: null },
    notes:      null,
    updated_at: updatedAt,
    created_at: updatedAt,
  }
}

function makeLocalCustomer(uuid: string) {
  return {
    uuid,
    code:          `C-${uuid}`,
    type:          'individual' as const,
    name:          `Cliente ${uuid}`,
    legalName:     null,
    taxId:         null,
    email:         `${uuid}@test.com`,
    phone:         null,
    mobile:        null,
    addressLine:   null,
    city:          null,
    state:         null,
    postalCode:    null,
    countryCode:   null,
    creditLimit:   0,
    creditBalance: 0,
    isActive:      true,
    isBlocked:     false,
    blockedReason: null,
    notes:         null,
    updatedAt:     '2026-01-01T00:00:00Z',
  }
}

beforeEach(async () => { await db.customers.clear() })
afterEach(async  () => { await db.customers.clear() })

describe('upsertMany', () => {
  it('no falla con lista vacia', async () => {
    await expect(upsertMany([])).resolves.toBeUndefined()
  })

  it('crea clientes nuevos', async () => {
    await upsertMany([makeApiCustomer('c-1'), makeApiCustomer('c-2')])
    expect(await db.customers.count()).toBe(2)
  })

  it('actualiza cliente existente (semantica upsert)', async () => {
    await db.customers.put(makeLocalCustomer('c-1'))
    await upsertMany([makeApiCustomer('c-1', 'Nombre Nuevo')])
    const c = await db.customers.get('c-1')
    expect(c?.name).toBe('Nombre Nuevo')
  })

  it('mapea campos anidados correctamente', async () => {
    const api = {
      ...makeApiCustomer('c-1'),
      legal_name: 'ACME SA',
      tax:        { tax_id: 'RFC123', data: null },
      contact:    { email: 'a@b.com', phone: '555-1234', mobile: null },
      address:    { line: 'Calle 1', city: 'CDMX', state: 'CMX', postal_code: '06600', country_code: 'MX' },
      credit:     { limit: 1000, balance: 200, available: 800 },
      notes:      'VIP',
    }
    await upsertMany([api])
    const c = await db.customers.get('c-1')
    expect(c?.legalName).toBe('ACME SA')
    expect(c?.taxId).toBe('RFC123')
    expect(c?.email).toBe('a@b.com')
    expect(c?.city).toBe('CDMX')
    expect(c?.creditLimit).toBe(1000)
    expect(c?.notes).toBe('VIP')
  })
})

describe('deleteMany', () => {
  it('no falla con lista vacia', async () => {
    await expect(deleteMany([])).resolves.toBeUndefined()
  })

  it('elimina clientes por uuid', async () => {
    await db.customers.bulkPut([makeLocalCustomer('c-1'), makeLocalCustomer('c-2')])
    await deleteMany(['c-1'])
    expect(await db.customers.count()).toBe(1)
    expect(await db.customers.get('c-1')).toBeUndefined()
    expect(await db.customers.get('c-2')).toBeDefined()
  })

  it('ignora uuids inexistentes', async () => {
    await db.customers.put(makeLocalCustomer('c-1'))
    await deleteMany(['no-existe'])
    expect(await db.customers.count()).toBe(1)
  })
})
