import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useCustomersStore } from '@/stores/customers'
import type { CustomerInput } from '@/lib/api/generated'

vi.mock('@/lib/api/generated', () => ({
  createCustomer: vi.fn<typeof apiCreateCustomer>(),
  updateCustomer: vi.fn<typeof apiUpdateCustomer>(),
  deleteCustomer: vi.fn<typeof apiDeleteCustomer>(),
}))

import {
  createCustomer as apiCreateCustomer,
  updateCustomer as apiUpdateCustomer,
  deleteCustomer as apiDeleteCustomer,
} from '@/lib/api/generated'

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

/** Cliente minimo (shape anidado) devuelto por create/update. */
function customerResource(uuid: string, name: string): unknown {
  return {
    uuid,
    code: null,
    type: 'individual',
    name,
    legal_name: null,
    tax: { tax_id: null, data: null },
    contact: { email: null, phone: null, mobile: null },
    address: { line: null, city: null, state: null, postal_code: null, country_code: null },
    credit: { limit: 0, balance: 0, available: 0 },
    flags: { is_active: true, is_blocked: false, blocked_reason: null },
    notes: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
  }
}

function okResource(uuid: string, name: string): unknown {
  return { data: { data: customerResource(uuid, name) }, error: undefined }
}

function apiError(code: string, message: string): unknown {
  return { data: undefined, error: { error: { code, message } } }
}

function input(name = 'Ana'): CustomerInput {
  return { type: 'individual', name }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

describe('customers store', () => {
  it('inicial: saving=false, deleting=false', () => {
    const store = useCustomersStore()
    expect(store.saving).toBe(false)
    expect(store.deleting).toBe(false)
  })

  it('create exito: ok=true, devuelve customer y arma headers+body', async () => {
    vi.mocked(apiCreateCustomer).mockResolvedValue(okResource('c-1', 'Ana') as never)
    const store = useCustomersStore()

    const result = await store.create(input('Ana'))

    expect(result.ok).toBe(true)
    expect(result.customer).toMatchObject({ uuid: 'c-1', name: 'Ana' })
    expect(apiCreateCustomer).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'demo' },
      body: { type: 'individual', name: 'Ana' },
    })
    expect(store.saving).toBe(false)
  })

  it('create error de validacion: ok=false, mensaje humanizado', async () => {
    vi.mocked(apiCreateCustomer).mockResolvedValue({
      data: undefined,
      error: {
        message: 'The given data was invalid.',
        errors: { email: ['El correo ya existe.'] },
      },
    } as never)
    const store = useCustomersStore()

    const result = await store.create(input('Ana'))

    expect(result.ok).toBe(false)
    expect(result.errorMessage).toBe('El correo ya existe.')
    expect(store.saving).toBe(false)
  })

  it('update exito: ok=true, pasa path uuid y body', async () => {
    vi.mocked(apiUpdateCustomer).mockResolvedValue(okResource('c-1', 'Ana Maria') as never)
    const store = useCustomersStore()

    const result = await store.update('c-1', input('Ana Maria'))

    expect(result.ok).toBe(true)
    expect(result.customer).toMatchObject({ uuid: 'c-1', name: 'Ana Maria' })
    expect(apiUpdateCustomer).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'demo' },
      path: { uuid: 'c-1' },
      body: { type: 'individual', name: 'Ana Maria' },
    })
  })

  it('remove exito: ok=true, pasa path uuid', async () => {
    vi.mocked(apiDeleteCustomer).mockResolvedValue({ error: undefined } as never)
    const store = useCustomersStore()

    const result = await store.remove('c-1')

    expect(result.ok).toBe(true)
    expect(apiDeleteCustomer).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'demo' },
      path: { uuid: 'c-1' },
    })
    expect(store.deleting).toBe(false)
  })

  it('remove con saldo deudor: 409 CUSTOMER_HAS_BALANCE -> ok=false, mensaje del backend', async () => {
    vi.mocked(apiDeleteCustomer).mockResolvedValue(
      apiError('CUSTOMER_HAS_BALANCE', 'No se puede borrar un cliente con saldo deudor.') as never,
    )
    const store = useCustomersStore()

    const result = await store.remove('c-1')

    expect(result.ok).toBe(false)
    expect(result.errorMessage).toBe('No se puede borrar un cliente con saldo deudor.')
    expect(store.deleting).toBe(false)
  })

  it('saving vuelve a false tras error inesperado (finally)', async () => {
    vi.mocked(apiCreateCustomer).mockRejectedValue(new Error('boom'))
    const store = useCustomersStore()

    const result = await store.create(input())

    expect(result.ok).toBe(false)
    expect(store.saving).toBe(false)
  })
})
