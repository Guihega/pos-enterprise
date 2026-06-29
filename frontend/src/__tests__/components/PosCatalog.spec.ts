import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'
import PosCatalog from '@/components/PosCatalog.vue'
import type { Product } from '@/lib/api/generated'

/**
 * Estado mutable que respalda el mock de useProducts. Cada test ajusta
 * estas refs ANTES de montar para simular los distintos estados del
 * catalogo (cargando, error, vacio, con resultados).
 */
const state = {
  init: vi.fn(async () => {}),
  searchTerm: ref(''),
  items: ref<Product[]>([]),
  loading: ref(false),
  loadingMore: ref(false),
  errorMessage: ref<string | null>(null),
  hasMore: ref(false),
  total: ref(0),
  loadMore: vi.fn(async () => {}),
  retry: vi.fn(async () => {}),
}

vi.mock('@/composables/useProducts', () => ({
  useProducts: () => state,
}))

const mockIsOutOfStock = vi.fn<(uuid: string, track: boolean) => boolean>(() => false)
const mockStockInit = vi.fn(async () => {})
vi.mock('@/composables/useStock', () => ({
  useStock: () => ({
    isOutOfStock: mockIsOutOfStock,
    init: mockStockInit,
    availableFor: vi.fn(() => Infinity),
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    tenant: 'demo',
    user: { default_branch: { default_warehouse_uuid: 'wh-1' } },
  }),
}))

function makeProduct(overrides: Record<string, unknown> = {}): Product {
  return {
    uuid: 'p1',
    name: 'Cafe',
    sku: 'CAF-001',
    pricing: { price: 25 },
    flags: { track_inventory: true },
    ...overrides,
  } as unknown as Product
}

function mountCatalog(): VueWrapper {
  return mount(PosCatalog)
}

beforeEach(() => {
  vi.clearAllMocks()
  // reset del estado a "vacio, sin cargar, sin error"
  state.searchTerm.value = ''
  state.items.value = []
  state.loading.value = false
  state.loadingMore.value = false
  state.errorMessage.value = null
  state.hasMore.value = false
  state.total.value = 0
  mockIsOutOfStock.mockReturnValue(false)
})

describe('PosCatalog', () => {
  it('llama init() al montar', () => {
    mountCatalog()
    expect(state.init).toHaveBeenCalledTimes(1)
  })

  it('inicializa el stock con tenant y warehouse del usuario', () => {
    mountCatalog()
    expect(mockStockInit).toHaveBeenCalledWith('demo', 'wh-1')
  })

  it('muestra el error y el boton Reintentar en estado de error', () => {
    state.errorMessage.value = 'Fallo la carga'
    const wrapper = mountCatalog()
    expect(wrapper.find('.pos-catalog__error').text()).toBe('Fallo la carga')
    expect(wrapper.find('.pos-catalog__retry').exists()).toBe(true)
  })

  it('retry() se invoca al pulsar Reintentar', async () => {
    state.errorMessage.value = 'Fallo'
    const wrapper = mountCatalog()
    await wrapper.find('.pos-catalog__retry').trigger('click')
    expect(state.retry).toHaveBeenCalledTimes(1)
  })

  it('muestra Cargando productos en la carga inicial', () => {
    state.loading.value = true
    state.items.value = []
    const wrapper = mountCatalog()
    expect(wrapper.text()).toContain('Cargando productos')
  })

  it('muestra mensaje de catalogo vacio sin busqueda', () => {
    const wrapper = mountCatalog()
    expect(wrapper.text()).toContain('No hay productos en el catalogo')
  })

  it('muestra mensaje de sin coincidencias cuando hay busqueda', () => {
    state.searchTerm.value = 'xyz'
    const wrapper = mountCatalog()
    expect(wrapper.text()).toContain('No hay productos que coincidan')
    expect(wrapper.text()).toContain('xyz')
  })

  it('renderiza un item por producto y el contador total', () => {
    state.items.value = [makeProduct({ uuid: 'p1' }), makeProduct({ uuid: 'p2' })]
    state.total.value = 2
    const wrapper = mountCatalog()
    expect(wrapper.findAll('.pos-catalog__item')).toHaveLength(2)
    expect(wrapper.find('.pos-catalog__count').text()).toContain('2')
  })

  it('emite productSelected al hacer click en un item disponible', async () => {
    const prod = makeProduct({ uuid: 'p1' })
    state.items.value = [prod]
    const wrapper = mountCatalog()
    await wrapper.find('.pos-catalog__item').trigger('click')
    const emitted = wrapper.emitted('productSelected')
    expect(emitted).toHaveLength(1)
    expect((emitted![0]![0] as Product).uuid).toBe('p1')
  })

  it('NO emite productSelected al hacer click en item sin stock', async () => {
    mockIsOutOfStock.mockReturnValue(true)
    state.items.value = [makeProduct({ uuid: 'p1' })]
    const wrapper = mountCatalog()
    await wrapper.find('.pos-catalog__item').trigger('click')
    expect(wrapper.emitted('productSelected')).toBeUndefined()
  })

  it('marca visualmente los items sin stock con badge', () => {
    mockIsOutOfStock.mockReturnValue(true)
    state.items.value = [makeProduct({ uuid: 'p1' })]
    const wrapper = mountCatalog()
    expect(wrapper.find('.pos-catalog__item--out-of-stock').exists()).toBe(true)
    expect(wrapper.find('.pos-catalog__item-badge').text()).toContain('Sin stock')
  })

  it('muestra Cargar mas cuando hasMore es true', () => {
    state.items.value = [makeProduct()]
    state.hasMore.value = true
    const wrapper = mountCatalog()
    expect(wrapper.find('.pos-catalog__load-more').exists()).toBe(true)
  })

  it('oculta Cargar mas cuando hasMore es false', () => {
    state.items.value = [makeProduct()]
    state.hasMore.value = false
    const wrapper = mountCatalog()
    expect(wrapper.find('.pos-catalog__load-more').exists()).toBe(false)
  })

  it('loadMore() se invoca al pulsar Cargar mas', async () => {
    state.items.value = [makeProduct()]
    state.hasMore.value = true
    const wrapper = mountCatalog()
    await wrapper.find('.pos-catalog__load-more').trigger('click')
    expect(state.loadMore).toHaveBeenCalledTimes(1)
  })

  it('deshabilita Cargar mas mientras loadingMore es true', () => {
    state.items.value = [makeProduct()]
    state.hasMore.value = true
    state.loadingMore.value = true
    const wrapper = mountCatalog()
    expect(wrapper.find('.pos-catalog__load-more').attributes('disabled')).toBeDefined()
  })
})
