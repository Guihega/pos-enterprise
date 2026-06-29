import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PosCart from '@/components/PosCart.vue'
import { useCartStore } from '@/stores/cart'
import type { Product } from '@/lib/api/generated'

/**
 * useStock se mockea para no depender de Dexie/inventario. Por defecto
 * availableFor devuelve Infinity (sin limite de stock). Tests que
 * necesiten un tope lo ajustan via mockAvailableFor.
 */
const mockAvailableFor = vi.fn<(uuid: string) => number>(() => Infinity)
vi.mock('@/composables/useStock', () => ({
  useStock: () => ({
    availableFor: mockAvailableFor,
    isOutOfStock: vi.fn(() => false),
  }),
}))

/** Producto fake con solo los campos que toCartItem consume. */
function makeProduct(overrides: Record<string, unknown> = {}): Product {
  return {
    uuid: 'p1',
    name: 'Cafe',
    sku: 'CAF-001',
    unit: { symbol: 'u.' },
    pricing: { price: 25 },
    tax: { rate: 0.16, is_inclusive: false },
    flags: { allow_decimals: false },
    ...overrides,
  } as unknown as Product
}

function mountCart(): VueWrapper {
  return mount(PosCart, {
    global: { stubs: { PinSupervisorModal: true } },
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  mockAvailableFor.mockReturnValue(Infinity)
})

describe('PosCart', () => {
  it('muestra mensaje de carrito vacio sin items', () => {
    const wrapper = mountCart()
    expect(wrapper.find('.pos-cart__empty').exists()).toBe(true)
    expect(wrapper.find('.pos-cart__list').exists()).toBe(false)
  })

  it('no muestra el boton Vaciar con el carrito vacio', () => {
    const wrapper = mountCart()
    expect(wrapper.find('.pos-cart__clear').exists()).toBe(false)
  })

  it('renderiza una linea por item del carrito', async () => {
    const cart = useCartStore()
    cart.add(makeProduct({ uuid: 'p1', name: 'Cafe' }))
    cart.add(makeProduct({ uuid: 'p2', name: 'Pan' }))
    const wrapper = mountCart()
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('.pos-cart__item')).toHaveLength(2)
  })

  it('muestra el nombre del producto en la linea', async () => {
    const cart = useCartStore()
    cart.add(makeProduct({ name: 'Cafe Premium' }))
    const wrapper = mountCart()
    await wrapper.vm.$nextTick()
    expect(wrapper.find('.pos-cart__item-name').text()).toBe('Cafe Premium')
  })

  it('muestra subtotal e IVA en el footer cuando hay items', async () => {
    const cart = useCartStore()
    cart.add(makeProduct())
    const wrapper = mountCart()
    await wrapper.vm.$nextTick()
    expect(wrapper.find('.pos-cart__footer').exists()).toBe(true)
    expect(wrapper.text()).toContain('Subtotal')
    expect(wrapper.text()).toContain('IVA')
  })

  it('incrementa la cantidad en 1 para productos sin decimales', async () => {
    const cart = useCartStore()
    cart.add(makeProduct({ flags: { allow_decimals: false } }), 1)
    const wrapper = mountCart()
    await wrapper.vm.$nextTick()
    // segundo boton de qty es "+"
    await wrapper.find('.pos-cart__qty-btn:last-of-type').trigger('click')
    expect(cart.items[0]!.quantity).toBe(2)
  })

  it('incrementa en 0.5 para productos con decimales', async () => {
    const cart = useCartStore()
    cart.add(makeProduct({ flags: { allow_decimals: true } }), 1)
    const wrapper = mountCart()
    await wrapper.vm.$nextTick()
    await wrapper.find('.pos-cart__qty-btn:last-of-type').trigger('click')
    expect(cart.items[0]!.quantity).toBe(1.5)
  })

  it('decrementa la cantidad', async () => {
    const cart = useCartStore()
    cart.add(makeProduct(), 3)
    const wrapper = mountCart()
    await wrapper.vm.$nextTick()
    // primer boton de qty es "-"
    await wrapper.find('.pos-cart__qty-btn:first-of-type').trigger('click')
    expect(cart.items[0]!.quantity).toBe(2)
  })

  it('elimina la linea al pulsar la x', async () => {
    const cart = useCartStore()
    cart.add(makeProduct())
    const wrapper = mountCart()
    await wrapper.vm.$nextTick()
    await wrapper.find('.pos-cart__remove').trigger('click')
    expect(cart.items).toHaveLength(0)
  })

  it('clampa la cantidad al stock disponible al teclear', async () => {
    mockAvailableFor.mockReturnValue(5)
    const cart = useCartStore()
    cart.add(makeProduct({ flags: { allow_decimals: false } }), 1)
    const wrapper = mountCart()
    await wrapper.vm.$nextTick()
    const input = wrapper.find('.pos-cart__qty-input')
    await input.setValue('10')
    await input.trigger('input')
    expect(cart.items[0]!.quantity).toBe(5)
  })

  it('vaciar no hace nada si el carrito esta vacio (no abre pin)', async () => {
    const wrapper = mountCart()
    // sin items no existe el boton; el carrito sigue vacio
    expect(wrapper.find('.pos-cart__clear').exists()).toBe(false)
  })

  it('el boton Vaciar aparece cuando hay items', async () => {
    const cart = useCartStore()
    cart.add(makeProduct())
    const wrapper = mountCart()
    await wrapper.vm.$nextTick()
    expect(wrapper.find('.pos-cart__clear').exists()).toBe(true)
  })
})
