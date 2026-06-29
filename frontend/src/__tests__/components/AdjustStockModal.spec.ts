import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import AdjustStockModal from '@/components/AdjustStockModal.vue'
import type { Stock } from '@/lib/api/generated'

const store = vi.hoisted(() => {
  const { reactive } = require('vue')
  return reactive({
    adjusting: false,
    adjust: vi.fn(async () => ({ ok: true, movement: { uuid: 'mov-1' } })),
  })
})

vi.mock('@/stores/inventory', () => ({ useInventoryStore: () => store }))

function makeStock(onHand = 10): Stock {
  return {
    product: { uuid: 'p1', name: 'Cafe', sku: 'CAF-001' },
    warehouse: { uuid: 'wh-1', name: 'Principal' },
    quantity: { on_hand: onHand },
  } as unknown as Stock
}

function mountModal(stock: Stock = makeStock()): VueWrapper {
  return mount(AdjustStockModal, { props: { stock } })
}

beforeEach(() => {
  vi.clearAllMocks()
  store.adjusting = false
})

describe('AdjustStockModal', () => {
  it('muestra producto, almacen y existencia actual', () => {
    const wrapper = mountModal(makeStock(25))
    const txt = wrapper.text()
    expect(txt).toContain('Cafe')
    expect(txt).toContain('Principal')
    expect(txt).toContain('25')
  })

  it('submit deshabilitado sin delta ni motivo', () => {
    const wrapper = mountModal()
    expect(wrapper.find('.adj-modal__btn--save').attributes('disabled')).toBeDefined()
  })

  it('submit deshabilitado con delta pero motivo corto', async () => {
    const wrapper = mountModal()
    await wrapper.find('#a-delta').setValue('5')
    await wrapper.find('#a-reason').setValue('ab')
    expect(wrapper.find('.adj-modal__btn--save').attributes('disabled')).toBeDefined()
  })

  it('submit habilitado con delta distinto de cero y motivo valido', async () => {
    const wrapper = mountModal()
    await wrapper.find('#a-delta').setValue('5')
    await wrapper.find('#a-reason').setValue('Conteo fisico')
    expect(wrapper.find('.adj-modal__btn--save').attributes('disabled')).toBeUndefined()
  })

  it('calcula la existencia resultante', async () => {
    const wrapper = mountModal(makeStock(10))
    await wrapper.find('#a-delta').setValue('-3')
    expect(wrapper.find('.adj-modal__preview').text()).toContain('7')
  })

  it('llama adjust con el payload correcto', async () => {
    const wrapper = mountModal()
    await wrapper.find('#a-delta').setValue('5')
    await wrapper.find('#a-reason').setValue('Conteo fisico')
    await wrapper.find('.adj-modal__btn--save').trigger('click')
    expect(store.adjust).toHaveBeenCalledTimes(1)
    const payload = store.adjust.mock.calls[0]![0] as Record<string, unknown>
    expect(payload.product_uuid).toBe('p1')
    expect(payload.warehouse_uuid).toBe('wh-1')
    expect(payload.delta).toBe(5)
    expect(payload.reason).toBe('Conteo fisico')
  })

  it('emite adjusted cuando el ajuste es exitoso', async () => {
    const wrapper = mountModal()
    await wrapper.find('#a-delta').setValue('5')
    await wrapper.find('#a-reason').setValue('Conteo fisico')
    await wrapper.find('.adj-modal__btn--save').trigger('click')
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('adjusted')).toHaveLength(1)
  })

  it('muestra el error del store cuando el ajuste falla', async () => {
    store.adjust.mockResolvedValue({ ok: false, errorMessage: 'Stock insuficiente' } as never)
    const wrapper = mountModal()
    await wrapper.find('#a-delta').setValue('-50')
    await wrapper.find('#a-reason').setValue('Merma')
    await wrapper.find('.adj-modal__btn--save').trigger('click')
    await wrapper.vm.$nextTick()
    expect(wrapper.find('.adj-modal__error').text()).toContain('Stock insuficiente')
  })

  it('emite cancel al pulsar Cancelar', async () => {
    const wrapper = mountModal()
    await wrapper.find('.adj-modal__btn--cancel').trigger('click')
    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })
})
