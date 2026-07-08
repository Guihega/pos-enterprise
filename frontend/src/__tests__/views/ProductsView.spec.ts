import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { ref, reactive } from 'vue'
import ProductsView from '@/views/ProductsView.vue'
import type { Product } from '@/lib/api/generated'

// Composable useProducts -> ref() (acceso .value en el template).
const init = vi.fn()
const loadMore = vi.fn()
const retry = vi.fn()
const searchTerm = ref('')
const items = ref<Product[]>([])
const loading = ref(false)
const loadingMore = ref(false)
const errorMessage = ref<string | null>(null)
const hasMore = ref(false)
const total = ref(0)

vi.mock('@/composables/useProducts', () => ({
  useProducts: () => ({
    init, searchTerm, items, loading, loadingMore,
    errorMessage, hasMore, total, loadMore, retry,
  }),
}))

// Store products -> reactive() (acceso sin .value).
const store = reactive({
  deleting: false,
  remove: vi.fn(async () => ({ ok: true })),
})

vi.mock('@/stores/products', () => ({
  useProductsStore: () => store,
}))

function makeProduct(over: Partial<Product> = {}): Product {
  return {
    uuid: 'p1',
    sku: 'SKU-1',
    name: 'Cafe',
    pricing: { cost: 10, price: 25, has_discount: false },
    flags: { track_inventory: true, is_sellable: true, is_purchasable: true, allow_decimals: false },
    status: 'active',
    ...over,
  } as unknown as Product
}

function resetState(): void {
  init.mockReset()
  loadMore.mockReset()
  retry.mockReset()
  searchTerm.value = ''
  items.value = []
  loading.value = false
  loadingMore.value = false
  errorMessage.value = null
  hasMore.value = false
  total.value = 0
  store.deleting = false
  store.remove = vi.fn(async () => ({ ok: true }))
}

function mountView(): VueWrapper {
  return mount(ProductsView, {
    global: {
      stubs: {
        RouterLink: { template: '<a><slot /></a>' },
        ProductFormModal: {
          template: '<div class="stub-product-modal"></div>',
          props: ['product'],
          emits: ['saved', 'cancel'],
        },
      },
    },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  resetState()
})

describe('ProductsView', () => {
  it('inicializa el listado al montar', () => {
    mountView()
    expect(init).toHaveBeenCalledTimes(1)
  })

  it('muestra el estado de carga', () => {
    loading.value = true
    const wrapper = mountView()
    expect(wrapper.text()).toContain('Cargando catalogo')
    expect(wrapper.find('.prod-table').exists()).toBe(false)
  })

  it('muestra el estado vacio cuando no hay productos', () => {
    items.value = []
    const wrapper = mountView()
    expect(wrapper.text()).toContain('No hay productos')
  })

  it('muestra el error y permite reintentar', async () => {
    errorMessage.value = 'Fallo de red'
    const wrapper = mountView()
    expect(wrapper.find('.prod-view__error').text()).toBe('Fallo de red')
    await wrapper.find('.prod-view__state button').trigger('click')
    expect(retry).toHaveBeenCalledTimes(1)
  })

  it('renderiza una fila por producto', () => {
    items.value = [makeProduct(), makeProduct({ uuid: 'p2', name: 'Te', sku: 'SKU-2' })]
    const wrapper = mountView()
    expect(wrapper.findAll('.prod-table tbody tr')).toHaveLength(2)
    expect(wrapper.text()).toContain('Cafe')
    expect(wrapper.text()).toContain('Te')
  })

  it('muestra el total de productos en el subtitulo', () => {
    total.value = 7
    const wrapper = mountView()
    expect(wrapper.find('.prod-view__subtitle').text()).toContain('7')
  })

  it('abre el modal al pulsar Nuevo producto', async () => {
    const wrapper = mountView()
    expect(wrapper.find('.stub-product-modal').exists()).toBe(false)
    await wrapper.find('.prod-view__new').trigger('click')
    expect(wrapper.find('.stub-product-modal').exists()).toBe(true)
  })

  it('abre el modal al pulsar Editar', async () => {
    items.value = [makeProduct()]
    const wrapper = mountView()
    await wrapper.find('.prod-table__btn').trigger('click')
    expect(wrapper.find('.stub-product-modal').exists()).toBe(true)
  })

  it('pide confirmacion antes de eliminar', async () => {
    items.value = [makeProduct()]
    const wrapper = mountView()
    const danger = wrapper.find('.prod-table__btn--danger')
    await danger.trigger('click')
    expect(wrapper.text()).toContain('Eliminar?')
  })

  it('elimina el producto al confirmar y muestra feedback', async () => {
    items.value = [makeProduct()]
    const wrapper = mountView()
    await wrapper.find('.prod-table__btn--danger').trigger('click')
    // Tras confirmar aparece el boton "Si"; lo localizamos por texto.
    const yes = wrapper.findAll('.prod-table__btn--danger').find((b) => b.text() === 'Si')
    expect(yes).toBeTruthy()
    await yes!.trigger('click')
    await flushPromises()
    expect(store.remove).toHaveBeenCalledWith('p1')
    expect(wrapper.find('.prod-view__feedback').text()).toContain('eliminado')
  })

  it('muestra el error de borrado cuando remove falla', async () => {
    store.remove = vi.fn(async () => ({ ok: false, errorMessage: 'Producto en uso' }))
    items.value = [makeProduct()]
    const wrapper = mountView()
    await wrapper.find('.prod-table__btn--danger').trigger('click')
    const yes = wrapper.findAll('.prod-table__btn--danger').find((b) => b.text() === 'Si')
    await yes!.trigger('click')
    await flushPromises()
    expect(wrapper.find('.prod-view__error').text()).toBe('Producto en uso')
  })

  it('muestra Cargar mas y pagina cuando hasMore', async () => {
    items.value = [makeProduct()]
    hasMore.value = true
    const wrapper = mountView()
    await wrapper.find('.prod-view__more button').trigger('click')
    expect(loadMore).toHaveBeenCalledTimes(1)
  })
})
