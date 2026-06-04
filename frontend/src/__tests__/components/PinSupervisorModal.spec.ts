import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PinSupervisorModal from '@/components/PinSupervisorModal.vue'
import * as sdk from '@/lib/api/generated/sdk.gen'
import * as errors from '@/lib/api/errors'

vi.mock('@/lib/api/generated/sdk.gen', () => ({
  pinVerify: vi.fn(),
}))

vi.mock('@/lib/api/errors', () => ({
  getTenantOrThrow: vi.fn((t) => t ?? 'demo'),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo' }),
}))

const mockPinVerify = vi.fn<typeof sdk.pinVerify>()

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  vi.mocked(sdk.pinVerify).mockImplementation(mockPinVerify)
})

function mountModal(open = true) {
  return mount(PinSupervisorModal, {
    props: { open },
    global: { stubs: { Teleport: true } },
  })
}

describe('PinSupervisorModal', () => {
  it('no renderiza nada si open es false', () => {
    const w = mountModal(false)
    expect(w.find('.pin-modal').exists()).toBe(false)
  })

  it('renderiza el modal si open es true', () => {
    const w = mountModal()
    expect(w.find('.pin-modal').exists()).toBe(true)
  })

  it('boton confirmar deshabilitado con menos de 4 digitos', async () => {
    const w = mountModal()
    await w.find('.pin-modal__input').setValue('123')
    const btn = w.find('.pin-modal__btn--confirm')
    expect((btn.element as HTMLButtonElement).disabled).toBe(true)
  })

  it('boton confirmar habilitado con 4 digitos', async () => {
    const w = mountModal()
    await w.find('.pin-modal__input').setValue('1234')
    const btn = w.find('.pin-modal__btn--confirm')
    expect((btn.element as HTMLButtonElement).disabled).toBe(false)
  })

  it('emite confirmed cuando el PIN es valido', async () => {
    mockPinVerify.mockResolvedValue({ data: { data: { valid: true } }, error: undefined } as never)
    const w = mountModal()
    await w.find('.pin-modal__input').setValue('5872')
    await w.find('.pin-modal__btn--confirm').trigger('click')
    await vi.runAllTimersAsync().catch(() => {})
    await new Promise((r) => setTimeout(r, 0))
    expect(w.emitted('confirmed')).toBeTruthy()
  })

  it('muestra error y no emite confirmed cuando el PIN es invalido', async () => {
    mockPinVerify.mockResolvedValue({
      data: undefined,
      error: { error: { code: 'PIN_INVALID', message: 'PIN invalido' } },
    } as never)
    const w = mountModal()
    await w.find('.pin-modal__input').setValue('0000')
    await w.find('.pin-modal__btn--confirm').trigger('click')
    await new Promise((r) => setTimeout(r, 0))
    expect(w.emitted('confirmed')).toBeFalsy()
    expect(w.find('.pin-modal__error').exists()).toBe(true)
  })

  it('emite cancelled al pulsar Cancelar', async () => {
    const w = mountModal()
    await w.find('.pin-modal__btn--cancel').trigger('click')
    expect(w.emitted('cancelled')).toBeTruthy()
  })

  it('resetea pin y error al abrirse de nuevo', async () => {
    mockPinVerify.mockResolvedValue({
      data: undefined,
      error: { error: { code: 'PIN_INVALID', message: 'fail' } },
    } as never)
    const w = mountModal()
    await w.find('.pin-modal__input').setValue('0000')
    await w.find('.pin-modal__btn--confirm').trigger('click')
    await new Promise((r) => setTimeout(r, 0))
    expect(w.find('.pin-modal__error').exists()).toBe(true)
    await w.setProps({ open: false })
    await w.setProps({ open: true })
    expect(w.find('.pin-modal__error').exists()).toBe(false)
  })
})
