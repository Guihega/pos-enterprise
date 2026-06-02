import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'

import CashCloseModal from '@/components/CashCloseModal.vue'

interface ModalProps {
  open: boolean
  loading?: boolean
  errorMessage?: string | null
}

function mountModal(props: Partial<ModalProps> = {}): VueWrapper {
  return mount(CashCloseModal, {
    props: { open: true, ...props },
  })
}

/** Input del monto contado (primer input dentro de un field). */
function amountInput(wrapper: VueWrapper) {
  return wrapper.find('.cash-modal__field input')
}

function notesInput(wrapper: VueWrapper) {
  return wrapper.find('.cash-modal__field textarea')
}

describe('CashCloseModal', () => {
  it('no renderiza nada si open=false', () => {
    const wrapper = mountModal({ open: false })
    expect(wrapper.find('.cash-modal').exists()).toBe(false)
  })

  it('renderiza el modal si open=true', () => {
    const wrapper = mountModal()
    expect(wrapper.find('.cash-modal').exists()).toBe(true)
  })

  it('confirm emite countedAmount y notas con parseo correcto', async () => {
    const wrapper = mountModal()
    await amountInput(wrapper).setValue('1234.56')
    await notesInput(wrapper).setValue('Cierre matutino')
    await wrapper.find('.cash-modal__submit').trigger('click')

    const emitted = wrapper.emitted('confirm')
    expect(emitted).toHaveLength(1)
    expect(emitted![0]).toEqual([1234.56, 'Cierre matutino'])
  })

  it('confirm normaliza coma decimal a punto', async () => {
    const wrapper = mountModal()
    await amountInput(wrapper).setValue('1500,75')
    await wrapper.find('.cash-modal__submit').trigger('click')

    const emitted = wrapper.emitted('confirm')
    expect(emitted![0]![0]).toBe(1500.75)
  })

  it('confirm con notas vacias emite null', async () => {
    const wrapper = mountModal()
    await amountInput(wrapper).setValue('500')
    await wrapper.find('.cash-modal__submit').trigger('click')

    const emitted = wrapper.emitted('confirm')
    expect(emitted![0]).toEqual([500, null])
  })

  it('monto 0 es valido (cierre en cero) y emite confirm', async () => {
    const wrapper = mountModal()
    await amountInput(wrapper).setValue('0')
    await wrapper.find('.cash-modal__submit').trigger('click')

    const emitted = wrapper.emitted('confirm')
    expect(emitted).toHaveLength(1)
    expect(emitted![0]![0]).toBe(0)
  })

  it('no emite confirm con monto vacio o invalido', async () => {
    const wrapper = mountModal()
    // vacio
    await wrapper.find('.cash-modal__submit').trigger('click')
    expect(wrapper.emitted('confirm')).toBeUndefined()
    // invalido
    await amountInput(wrapper).setValue('abc')
    await wrapper.find('.cash-modal__submit').trigger('click')
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('el boton submit se deshabilita con loading=true', () => {
    const wrapper = mountModal({ loading: true })
    expect(
      wrapper.find('.cash-modal__submit').attributes('disabled'),
    ).toBeDefined()
  })

  it('cancel emite close', async () => {
    const wrapper = mountModal()
    await wrapper.find('.cash-modal__cancel').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('cancel NO emite close si loading=true', async () => {
    const wrapper = mountModal({ loading: true })
    await wrapper.find('.cash-modal__cancel').trigger('click')
    expect(wrapper.emitted('close')).toBeUndefined()
  })

  it('muestra errorMessage cuando se provee', () => {
    const wrapper = mountModal({ errorMessage: 'Algo salio mal' })
    expect(wrapper.find('.cash-modal__error').text()).toContain('Algo salio mal')
  })

  it('reabrir el modal resetea el form', async () => {
    const wrapper = mountModal()
    await amountInput(wrapper).setValue('999')
    await wrapper.setProps({ open: false })
    await wrapper.setProps({ open: true })
    expect((amountInput(wrapper).element as HTMLInputElement).value).toBe('')
  })
})
