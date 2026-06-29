import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { reactive } from 'vue'
import PosCheckoutBar from '@/components/PosCheckoutBar.vue'

// El componente consume un STORE de Pinia (useCartStore). Per patron del
// proyecto, los stores se mockean con reactive() para que el template
// acceda a las props sin .value (auto-desenvuelve como un store real).
const cartStore = reactive({
  lineCount: 0,
  grandTotal: 0,
  isEmpty: true,
})

vi.mock('@/stores/cart', () => ({
  useCartStore: () => cartStore,
}))

function resetStore(over: Partial<typeof cartStore> = {}): void {
  cartStore.lineCount = 0
  cartStore.grandTotal = 0
  cartStore.isEmpty = true
  Object.assign(cartStore, over)
}

function mountBar(): VueWrapper {
  return mount(PosCheckoutBar)
}

beforeEach(() => {
  resetStore()
})

describe('PosCheckoutBar', () => {
  it('muestra el conteo de items del carrito', () => {
    resetStore({ lineCount: 3 })
    const wrapper = mountBar()
    expect(wrapper.find('.pos-checkout__count').text()).toContain('3')
  })

  it('muestra el total formateado del carrito', () => {
    resetStore({ grandTotal: 58, lineCount: 1, isEmpty: false })
    const wrapper = mountBar()
    // No verificamos el string exacto del formato (locale), solo que el
    // monto del total se refleja en el resumen.
    expect(wrapper.find('.pos-checkout__total').text()).toContain('58')
  })

  it('oculta el badge cuando el carrito esta vacio', () => {
    resetStore({ lineCount: 0 })
    const wrapper = mountBar()
    expect(wrapper.find('.pos-checkout__cart-badge').exists()).toBe(false)
  })

  it('muestra el badge con el conteo cuando hay items', () => {
    resetStore({ lineCount: 2, isEmpty: false })
    const wrapper = mountBar()
    const badge = wrapper.find('.pos-checkout__cart-badge')
    expect(badge.exists()).toBe(true)
    expect(badge.text()).toBe('2')
  })

  it('deshabilita el boton Cobrar cuando el carrito esta vacio', () => {
    resetStore({ isEmpty: true })
    const wrapper = mountBar()
    expect(wrapper.find('.pos-checkout__btn').attributes('disabled')).toBeDefined()
  })

  it('habilita el boton Cobrar cuando hay items', () => {
    resetStore({ isEmpty: false, lineCount: 1 })
    const wrapper = mountBar()
    expect(wrapper.find('.pos-checkout__btn').attributes('disabled')).toBeUndefined()
  })

  it('emite checkout al pulsar Cobrar', async () => {
    resetStore({ isEmpty: false, lineCount: 1 })
    const wrapper = mountBar()
    await wrapper.find('.pos-checkout__btn').trigger('click')
    expect(wrapper.emitted('checkout')).toHaveLength(1)
  })

  it('emite open-cart al pulsar el boton del carrito', async () => {
    const wrapper = mountBar()
    await wrapper.find('.pos-checkout__cart-btn').trigger('click')
    expect(wrapper.emitted('open-cart')).toHaveLength(1)
  })
})
