/**
 * CustomerRepository — escritura de clientes en IndexedDB.
 *
 * Fase 2 / Iteracion 2. Metodos de escritura granular requeridos por
 * PullStream (sec. 38.5). CustomerLocal tiene updatedAt para LWW.
 * El llamador (PullStream) decide si aplicar segun la comparacion LWW.
 */
import type { Customer } from '@/lib/api/generated'
import { db, type CustomerLocal } from '@/db/schema'

// ---------------------------------------------------------------------------
// Mapeo API -> local (aplanado de objetos anidados)
// ---------------------------------------------------------------------------

function toLocal(c: Customer): CustomerLocal {
  return {
    uuid:          c.uuid,
    code:          c.code,
    type:          c.type,
    name:          c.name,
    legalName:     c.legal_name,
    taxId:         c.tax.tax_id,
    email:         c.contact.email,
    phone:         c.contact.phone,
    mobile:        c.contact.mobile,
    addressLine:   c.address.line,
    city:          c.address.city,
    state:         c.address.state,
    postalCode:    c.address.postal_code,
    countryCode:   c.address.country_code,
    creditLimit:   c.credit.limit,
    creditBalance: c.credit.balance,
    isActive:      c.flags.is_active,
    isBlocked:     c.flags.is_blocked,
    blockedReason: c.flags.blocked_reason,
    notes:         c.notes,
    updatedAt:     c.updated_at,
  }
}

// ---------------------------------------------------------------------------
// API publica
// ---------------------------------------------------------------------------

/**
 * Upsert de multiples clientes en IndexedDB.
 * LWW: PullStream filtra previamente los que no deben aplicarse.
 */
export async function upsertMany(items: Customer[]): Promise<void> {
  if (items.length === 0) return
  await db.customers.bulkPut(items.map(toLocal))
}

/**
 * Elimina clientes por uuid.
 */
export async function deleteMany(uuids: string[]): Promise<void> {
  if (uuids.length === 0) return
  await db.customers.bulkDelete(uuids)
}
