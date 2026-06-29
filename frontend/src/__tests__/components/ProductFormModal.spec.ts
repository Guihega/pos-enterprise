import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import ProductFormModal from '@/components/ProductFormModal.vue'
import type { Product } from '@/lib/api/generated'

const store = vi.hoisted(() => {
  const { reactive } = require('vue')
  return reactive({
    saving: false,
    create: vi.fn(async () => ({ ok: true, product: { uuid: 'new-1' } })),
    update: vi.fn(async () => ({ ok: true, product: { uuid: 'upd-1' } })),
  })
})

const opts = vi.hoisted(() => {
  const { ref: r } = require('vue')
  return {
    init: vi.fn(async () => {}),
    units: r([{ uuid: 'u1', name: 'Pieza', code: 'PZA' }]),
    taxes: r([{ uuid: 't1', name: 'IVA', rate_percent: 16 }]),
    categories: r([{ uuid: 'c1', name: 'Bebidas' }]),
    brands: r([{ uuid: 'b1', name: 'Marca' }]),
    errorMessage: r<string | null>(null),
  }
})

vi.mock('@/stores/products', () => ({ useProductsStore: () => store }))
vi.mock('@/composables/useCatalogOptions', () => ({ useCatalogOptions: () => opts }))

function makeProduct(): Product {
  return {
    uuid: 'p1',
    sku: 'SKU-1',
    name: 'Cafe',
    description: 'desc',
    pricing: { price: 25, cost: 10 },
    unit: { uuid: 'u1' },
    category: { uuid: 'c1' },
    brand: { uuid: 'b1' },
    tax: { uuid: 't1' },
    status: 'active',
  } as unknown as Product
}

async function mountModal(product: Product | null = null): Promise<VueWrapper> {
  const wrapper = mount(ProductFormModal, { props: { product } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  store.saving = false
  opts.errorMessage.value = null
})

describe('ProductFormModal', () => {
  it('titula Nuevo producto en modo creacion', async () => {
    const wrapper = await mountModal(null)
    expect(wrapper.find('.prod-modal__header h2').text()).toBe('Nuevo producto')
  })

  it('titula Editar producto cuando recibe un producto', async () => {
    const wrapper = await mountModal(makeProduct())
    expect(wrapper.find('.prod-modal__header h2').text()).toBe('Editar producto')
  })

  it('carga las opciones del catalogo al montar', async () => {
    await mountModal()
    expect(opts.init).toHaveBeenCalledTimes(1)
  })

  it('precarga el formulario en modo edicion', async () => {
    const wrapper = await mountModal(makeProduct())
    expect((wrapper.find('#p-sku').element as HTMLInputElement).value).toBe('SKU-1')
    expect((wrapper.find('#p-name').element as HTMLInputElement).value).toBe('Cafe')
    expect((wrapper.find('#p-price').element as HTMLInputElement).value).toBe('25')
  })

  it('submit deshabilitado si faltan campos requeridos', async () => {
    const wrapper = await mountModal(null)
    expect(wrapper.find('.prod-modal__btn--save').attributes('disabled')).toBeDefined()
  })

  it('submit habilitado con sku, nombre, unidad y precio', async () => {
    const wrapper = await mountModal(null)
    await wrapper.find('#p-sku').setValue('SKU-9')
    await wrapper.find('#p-name').setValue('Nuevo')
    await wrapper.find('#p-unit').setValue('u1')
    await wrapper.find('#p-price').setValue('15')
    expect(wrapper.find('.prod-modal__btn--save').attributes('disabled')).toBeUndefined()
  })

  it('llama create con el payload en modo creacion', async () => {
    const wrapper = await mountModal(null)
    await wrapper.find('#p-sku').setValue('SKU-9')
    await wrapper.find('#p-name').setValue('Nuevo')
    await wrapper.find('#p-unit').setValue('u1')
    await wrapper.find('#p-price').setValue('15')
    await wrapper.find('.prod-modal__btn--save').trigger('click')
    await flushPromises()
    expect(store.create).toHaveBeenCalledTimes(1)
    const payload = store.create.mock.calls[0]![0] as Record<string, unknown>
    expect(payload.sku).toBe('SKU-9')
    expect(payload.price).toBe(15)
  })

  it('llama update en modo edicion', async () => {
    const wrapper = await mountModal(makeProduct())
    await wrapper.find('.prod-modal__btn--save').trigger('click')
    await flushPromises()
    expect(store.update).toHaveBeenCalledTimes(1)
    expect(store.update.mock.calls[0]![0]).toBe('p1')
  })

  it('emite saved cuando el guardado es exitoso', async () => {
    const wrapper = await mountModal(makeProduct())
    await wrapper.find('.prod-modal__btn--save').trigger('click')
    await flushPromises()
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('muestra error y no emite saved si el precio es invalido', async () => {
    const wrapper = await mountModal(null)
    await wrapper.find('#p-sku').setValue('SKU-9')
    await wrapper.find('#p-name').setValue('Nuevo')
    await wrapper.find('#p-unit').setValue('u1')
    await wrapper.find('#p-price').setValue('-5')
    await wrapper.find('.prod-modal__btn--save').trigger('click')
    await flushPromises()
    expect(wrapper.find('.prod-modal__error').exists()).toBe(true)
    expect(store.create).not.toHaveBeenCalled()
  })

  it('muestra el error del store cuando el guardado falla', async () => {
    store.create.mockResolvedValue({ ok: false, errorMessage: 'SKU duplicado' } as never)
    const wrapper = await mountModal(null)
    await wrapper.find('#p-sku').setValue('SKU-9')
    await wrapper.find('#p-name').setValue('Nuevo')
    await wrapper.find('#p-unit').setValue('u1')
    await wrapper.find('#p-price').setValue('15')
    await wrapper.find('.prod-modal__btn--save').trigger('click')
    await flushPromises()
    expect(wrapper.find('.prod-modal__error').text()).toContain('SKU duplicado')
  })

  it('emite cancel al pulsar Cancelar', async () => {
    const wrapper = await mountModal(null)
    await wrapper.find('.prod-modal__btn--cancel').trigger('click')
    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })
})
