import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import CashOpenModal from '@/components/CashOpenModal.vue'
import {
  listCashRegisters as apiListCashRegisters,
  openCashSession as apiOpenCashSession,
} from '@/lib/api/generated'

vi.mock('@/lib/api/generated', () => ({
  listCashRegisters: vi.fn(),
  openCashSession: vi.fn(),
  listCashSessions: vi.fn(),
  closeCashSession: vi.fn(),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

const mockList = vi.mocked(apiListCashRegisters)
const mockOpen = vi.mocked(apiOpenCashSession)

function makeRegister(uuid: string, code: string): unknown {
  return {
    uuid,
    code,
    name: `Caja ${code}`,
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
  }
}

function listOk(registers: unknown[]): unknown {
  return { data: { data: registers }, error: undefined }
}

function openOk(): unknown {
  return {
    data: {
      data: {
        uuid: 'session-1',
        status: 'open',
        opening_amount: 0,
        cash_register_uuid: 'reg-1',
      },
    },
    error: undefined,
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  mockList.mockResolvedValue(listOk([makeRegister('reg-1', 'C1')]) as never)
  mockOpen.mockResolvedValue(openOk() as never)
})

async function mountModal(): Promise<VueWrapper> {
  const wrapper = mount(CashOpenModal)
  await flushPromises()
  return wrapper
}

function registerSelect(wrapper: VueWrapper) {
  return wrapper.find('#cash-register')
}

function amountInput(wrapper: VueWrapper) {
  return wrapper.find('#opening-amount')
}

function notesInput(wrapper: VueWrapper) {
  return wrapper.find('#opening-notes')
}

function submitBtn(wrapper: VueWrapper) {
  return wrapper.find('.cash-modal__submit')
}

describe('CashOpenModal', () => {
  it('renderiza el modal con sus campos', async () => {
    const wrapper = await mountModal()
    expect(wrapper.find('.cash-modal').exists()).toBe(true)
    expect(registerSelect(wrapper).exists()).toBe(true)
    expect(amountInput(wrapper).exists()).toBe(true)
    expect(notesInput(wrapper).exists()).toBe(true)
  })

  it('carga las cajas via loadRegisters al montar', async () => {
    await mountModal()
    expect(mockList).toHaveBeenCalledTimes(1)
  })

  it('auto-selecciona la caja cuando solo hay una', async () => {
    const wrapper = await mountModal()
    expect(submitBtn(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('deshabilita el select cuando solo hay una caja', async () => {
    const wrapper = await mountModal()
    expect(registerSelect(wrapper).attributes('disabled')).toBeDefined()
  })

  it('habilita el select cuando hay varias cajas', async () => {
    mockList.mockResolvedValue(
      listOk([makeRegister('reg-1', 'C1'), makeRegister('reg-2', 'C2')]) as never,
    )
    const wrapper = await mountModal()
    expect(registerSelect(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('renderiza una opcion por cada caja disponible', async () => {
    mockList.mockResolvedValue(
      listOk([makeRegister('reg-1', 'C1'), makeRegister('reg-2', 'C2')]) as never,
    )
    const wrapper = await mountModal()
    const options = registerSelect(wrapper).findAll('option:not([disabled])')
    expect(options).toHaveLength(2)
  })

  it('submit deshabilitado si hay varias cajas y ninguna seleccionada', async () => {
    mockList.mockResolvedValue(
      listOk([makeRegister('reg-1', 'C1'), makeRegister('reg-2', 'C2')]) as never,
    )
    const wrapper = await mountModal()
    expect(submitBtn(wrapper).attributes('disabled')).toBeDefined()
  })

  it('llama open() del store con caja, monto y notas al enviar', async () => {
    const wrapper = await mountModal()
    await amountInput(wrapper).setValue('500.50')
    await notesInput(wrapper).setValue('Turno matutino')
    await submitBtn(wrapper).trigger('click')
    await flushPromises()

    expect(mockOpen).toHaveBeenCalledTimes(1)
    const callArg = mockOpen.mock.calls[0]![0] as { body: Record<string, unknown> }
    expect(callArg.body.cash_register_uuid).toBe('reg-1')
    expect(callArg.body.opening_amount).toBe(500.5)
    expect(callArg.body.opening_notes).toBe('Turno matutino')
  })

  it('envia opening_notes null cuando las notas estan vacias', async () => {
    const wrapper = await mountModal()
    await amountInput(wrapper).setValue('100')
    await submitBtn(wrapper).trigger('click')
    await flushPromises()

    const callArg = mockOpen.mock.calls[0]![0] as { body: Record<string, unknown> }
    expect(callArg.body.opening_notes).toBeNull()
  })

  it('envia opening_notes null cuando las notas son solo espacios', async () => {
    const wrapper = await mountModal()
    await amountInput(wrapper).setValue('100')
    await notesInput(wrapper).setValue('   ')
    await submitBtn(wrapper).trigger('click')
    await flushPromises()

    const callArg = mockOpen.mock.calls[0]![0] as { body: Record<string, unknown> }
    expect(callArg.body.opening_notes).toBeNull()
  })

  it('no llama open() si no hay caja seleccionada', async () => {
    mockList.mockResolvedValue(
      listOk([makeRegister('reg-1', 'C1'), makeRegister('reg-2', 'C2')]) as never,
    )
    const wrapper = await mountModal()
    await submitBtn(wrapper).trigger('click')
    await flushPromises()
    expect(mockOpen).not.toHaveBeenCalled()
  })

  it('muestra el mensaje de error del store cuando loadRegisters falla', async () => {
    mockList.mockResolvedValue({ data: undefined, error: { message: 'boom' } } as never)
    const wrapper = await mountModal()
    expect(wrapper.find('.cash-modal__error').exists()).toBe(true)
  })
})
