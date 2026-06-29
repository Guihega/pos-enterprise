import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import CustomerFormModal from '@/components/CustomerFormModal.vue'
import type { Customer } from '@/lib/api/generated'

const store = vi.hoisted(() => {
  const { reactive } = require('vue')
  return reactive({
    saving: false,
    create: vi.fn(async () => ({ ok: true, customer: { uuid: 'new-1' } })),
    update: vi.fn(async () => ({ ok: true, customer: { uuid: 'upd-1' } })),
  })
})

vi.mock('@/stores/customers', () => ({ useCustomersStore: () => store }))

function makeCustomer(): Customer {
  return {
    uuid: 'c1',
    code: 'CLI-1',
    type: 'individual',
    name: 'Juan Perez',
    legal_name: null,
    tax: { tax_id: 'XAXX010101000' },
    contact: { email: 'juan@ej.com', phone: null, mobile: null },
    address: { line: null, city: null, state: null, postal_code: null, country_code: null },
    credit: { limit: 5000 },
    flags: { is_active: true, is_blocked: false, blocked_reason: null },
    notes: null,
  } as unknown as Customer
}

async function mountModal(customer: Customer | null = null): Promise<VueWrapper> {
  const wrapper = mount(CustomerFormModal, { props: { customer } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  store.saving = false
})

describe('CustomerFormModal', () => {
  it('titula Nuevo en modo creacion', async () => {
    const wrapper = await mountModal(null)
    expect(wrapper.find('.cust-modal__header').text()).toContain('Nuevo')
  })

  it('titula Editar cuando recibe un cliente', async () => {
    const wrapper = await mountModal(makeCustomer())
    expect(wrapper.find('.cust-modal__header').text()).toContain('Editar')
  })

  it('precarga el formulario en modo edicion', async () => {
    const wrapper = await mountModal(makeCustomer())
    expect((wrapper.find('#c-name').element as HTMLInputElement).value).toBe('Juan Perez')
    expect((wrapper.find('#c-email').element as HTMLInputElement).value).toBe('juan@ej.com')
  })

  it('submit deshabilitado sin nombre', async () => {
    const wrapper = await mountModal(null)
    expect(wrapper.find('.cust-modal__btn--save').attributes('disabled')).toBeDefined()
  })

  it('submit habilitado con nombre', async () => {
    const wrapper = await mountModal(null)
    await wrapper.find('#c-name').setValue('Cliente Nuevo')
    expect(wrapper.find('.cust-modal__btn--save').attributes('disabled')).toBeUndefined()
  })

  it('llama create con el payload en modo creacion', async () => {
    const wrapper = await mountModal(null)
    await wrapper.find('#c-name').setValue('Cliente Nuevo')
    await wrapper.find('.cust-modal__btn--save').trigger('click')
    await flushPromises()
    expect(store.create).toHaveBeenCalledTimes(1)
    const payload = store.create.mock.calls[0]![0] as Record<string, unknown>
    expect(payload.name).toBe('Cliente Nuevo')
  })

  it('llama update en modo edicion', async () => {
    const wrapper = await mountModal(makeCustomer())
    await wrapper.find('.cust-modal__btn--save').trigger('click')
    await flushPromises()
    expect(store.update).toHaveBeenCalledTimes(1)
    expect(store.update.mock.calls[0]![0]).toBe('c1')
  })

  it('emite saved cuando el guardado es exitoso', async () => {
    const wrapper = await mountModal(makeCustomer())
    await wrapper.find('.cust-modal__btn--save').trigger('click')
    await flushPromises()
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('muestra error y no guarda si el limite de credito es invalido', async () => {
    const wrapper = await mountModal(null)
    await wrapper.find('#c-name').setValue('Cliente')
    await wrapper.find('#c-credit').setValue('-100')
    await wrapper.find('.cust-modal__btn--save').trigger('click')
    await flushPromises()
    expect(wrapper.find('.cust-modal__error').exists()).toBe(true)
    expect(store.create).not.toHaveBeenCalled()
  })

  it('muestra el error del store cuando el guardado falla', async () => {
    store.create.mockResolvedValue({ ok: false, errorMessage: 'Codigo duplicado' } as never)
    const wrapper = await mountModal(null)
    await wrapper.find('#c-name').setValue('Cliente')
    await wrapper.find('.cust-modal__btn--save').trigger('click')
    await flushPromises()
    expect(wrapper.find('.cust-modal__error').text()).toContain('Codigo duplicado')
  })

  it('emite cancel al pulsar Cancelar', async () => {
    const wrapper = await mountModal(null)
    await wrapper.find('.cust-modal__btn--cancel').trigger('click')
    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })
})
