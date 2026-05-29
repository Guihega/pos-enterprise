import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'

import PaymentModal from '@/components/PaymentModal.vue'

type ModalProps = {
  total: number
  open: boolean
  errorMessage?: string | null
  submitting?: boolean
}

function mountModal(props: Partial<ModalProps> = {}): VueWrapper {
  return mount(PaymentModal, {
    props: {
      total: 100,
      open: true,
      ...props,
    },
  })
}

function fieldInputs(wrapper: VueWrapper) {
  return wrapper.findAll('.payment-modal__field input')
}

function buttonByText(wrapper: VueWrapper, text: string) {
  return wrapper.findAll('button').find((b) => b.text().trim() === text)
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('PaymentModal', () => {
  it('no renderiza nada si open=false', () => {
    const wrapper = mountModal({ open: false })
    expect(wrapper.find('.payment-modal').exists()).toBe(false)
  })

  it('renderiza con total y prellena "A pagar" con el restante', () => {
    const wrapper = mountModal({ total: 250 })

    expect(wrapper.find('.payment-modal__total').text()).toContain('250')

    const inputs = fieldInputs(wrapper)
    expect((inputs[0]!.element as HTMLInputElement).value).toBe('250')
  })

  it('selectMethod cambia el metodo activo y resetea el form', async () => {
    const wrapper = mountModal({ total: 100 })

    expect(fieldInputs(wrapper)).toHaveLength(2)

    await buttonByText(wrapper, 'Transferencia')!.trigger('click')

    const activeBtn = wrapper.find('.payment-modal__method-btn--active')
    expect(activeBtn.text()).toBe('Transferencia')
  })

  it('addDenomination suma al campo Recibido', async () => {
    const wrapper = mountModal({ total: 100 })

    const denom200 = wrapper
      .findAll('.payment-modal__denom-btn')
      .find((b) => b.text().includes('200'))
    await denom200!.trigger('click')

    const inputs = fieldInputs(wrapper)
    expect((inputs[1]!.element as HTMLInputElement).value).toBe('300')
  })

  it('setExact iguala Recibido al monto A pagar', async () => {
    const wrapper = mountModal({ total: 100 })
    const inputs = fieldInputs(wrapper)

    await inputs[1]!.setValue('500')
    expect((inputs[1]!.element as HTMLInputElement).value).toBe('500')

    await buttonByText(wrapper, 'Exacto')!.trigger('click')
    expect((fieldInputs(wrapper)[1]!.element as HTMLInputElement).value).toBe(
      '100',
    )
  })

  it('muestra el cambio cuando Recibido > A pagar', async () => {
    const wrapper = mountModal({ total: 100 })
    const inputs = fieldInputs(wrapper)

    await inputs[1]!.setValue('150')

    const change = wrapper.find('.payment-modal__change')
    expect(change.exists()).toBe(true)
    expect(change.text()).toContain('50')
  })

  it('no muestra cambio cuando Recibido <= A pagar', async () => {
    const wrapper = mountModal({ total: 100 })
    const inputs = fieldInputs(wrapper)

    await inputs[1]!.setValue('100')
    expect(wrapper.find('.payment-modal__change').exists()).toBe(false)

    await inputs[1]!.setValue('80')
    expect(wrapper.find('.payment-modal__change').exists()).toBe(false)
  })

  it('addPayment rechaza monto <= 0', async () => {
    const wrapper = mountModal({ total: 100 })
    const inputs = fieldInputs(wrapper)

    await inputs[0]!.setValue('0')
    await buttonByText(wrapper, 'Agregar pago')!.trigger('click')

    expect(wrapper.find('.payment-modal__error').text()).toContain(
      'mayor a cero',
    )
    expect(wrapper.findAll('.payment-modal__payment-item')).toHaveLength(0)
  })

  it('addPayment con last4 invalido (no 4 digitos) muestra error', async () => {
    const wrapper = mountModal({ total: 100 })

    await buttonByText(wrapper, 'Tarjeta credito')!.trigger('click')
    const inputs = fieldInputs(wrapper)
    await inputs[3]!.setValue('12')
    await buttonByText(wrapper, 'Agregar pago')!.trigger('click')

    expect(wrapper.find('.payment-modal__error').text()).toContain('4 numeros')
    expect(wrapper.findAll('.payment-modal__payment-item')).toHaveLength(0)
  })

  it('addPayment rechaza si el monto excede el total', async () => {
    const wrapper = mountModal({ total: 100 })
    const inputs = fieldInputs(wrapper)

    await inputs[0]!.setValue('150')
    await buttonByText(wrapper, 'Agregar pago')!.trigger('click')

    expect(wrapper.find('.payment-modal__error').text()).toContain('excede')
    expect(wrapper.findAll('.payment-modal__payment-item')).toHaveLength(0)
  })

  it('addPayment agrega un pago valido y refleja restante', async () => {
    const wrapper = mountModal({ total: 100 })
    const inputs = fieldInputs(wrapper)

    await inputs[0]!.setValue('100')
    await buttonByText(wrapper, 'Agregar pago')!.trigger('click')

    expect(wrapper.findAll('.payment-modal__payment-item')).toHaveLength(1)
    expect(wrapper.find('.payment-modal__remaining-zero').exists()).toBe(true)
  })

  it('removePayment elimina el pago de la lista', async () => {
    const wrapper = mountModal({ total: 100 })
    const inputs = fieldInputs(wrapper)

    await inputs[0]!.setValue('100')
    await buttonByText(wrapper, 'Agregar pago')!.trigger('click')
    expect(wrapper.findAll('.payment-modal__payment-item')).toHaveLength(1)

    await wrapper.find('.payment-modal__remove').trigger('click')
    expect(wrapper.findAll('.payment-modal__payment-item')).toHaveLength(0)
  })

  it('Confirmar deshabilitado si no esta cubierto el total', () => {
    const wrapper = mountModal({ total: 100 })

    const confirm = wrapper.find('.payment-modal__confirm')
    expect((confirm.element as HTMLButtonElement).disabled).toBe(true)
  })

  it('Confirmar habilitado y emite "confirm" con payments cuando cuadra', async () => {
    const wrapper = mountModal({ total: 100 })
    const inputs = fieldInputs(wrapper)

    await inputs[0]!.setValue('100')
    await buttonByText(wrapper, 'Agregar pago')!.trigger('click')

    const confirm = wrapper.find('.payment-modal__confirm')
    expect((confirm.element as HTMLButtonElement).disabled).toBe(false)

    await confirm.trigger('click')

    const emitted = wrapper.emitted('confirm')
    expect(emitted).toBeTruthy()
    const payload = emitted![0]![0] as Array<{ method: string; amount: number }>
    expect(payload).toEqual([{ method: 'cash', amount: 100 }])
  })

  it('emite "close" al pulsar Cancelar (si no submitting)', async () => {
    const wrapper = mountModal({ total: 100 })

    await buttonByText(wrapper, 'Cancelar')!.trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('no emite "close" si submitting=true', async () => {
    const wrapper = mountModal({ total: 100, submitting: true })

    await wrapper.find('.payment-modal__cancel').trigger('click')
    expect(wrapper.emitted('close')).toBeFalsy()
  })

  it('muestra errorMessage externo del parent', () => {
    const wrapper = mountModal({
      total: 100,
      errorMessage: 'El backend rechazo la venta.',
    })

    expect(wrapper.find('.payment-modal__error').text()).toContain(
      'El backend rechazo la venta.',
    )
  })
})
