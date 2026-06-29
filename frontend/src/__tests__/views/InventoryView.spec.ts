import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'
import InventoryView from '@/views/InventoryView.vue'
import type { Stock, Warehouse } from '@/lib/api/generated'

// Composable useInventory -> refs (se desestructuran, acceso .value).
const init = vi.fn()
const loadMore = vi.fn()
const retry = vi.fn()
const warehouseUuid = ref('')
const lowStockOnly = ref(false)
const items = ref<Stock[]>([])
const loading = ref(false)
const loadingMore = ref(false)
const errorMessage = ref<string | null>(null)
const hasMore = ref(false)
const total = ref(0)

vi.mock('@/composables/useInventory', () => ({
  useInventory: () => ({
    init, warehouseUuid, lowStockOnly, items, loading, loadingMore,
    errorMessage, hasMore, total, loadMore, retry,
  }),
}))

// useWarehouses NO se desestructura: el template usa warehouses.items.value,
// por lo que items DEBE ser un ref en el mock.
const whInit = vi.fn(async () => {})
const whItems = ref<Warehouse[]>([])

vi.mock('@/composables/useWarehouses', () => ({
  useWarehouses: () => ({
    init: whInit,
    items: whItems,
    loading: ref(false),
    errorMessage: ref(null),
  }),
}))

function makeStock(over: Partial<Stock> = {}): Stock {
  return {
    product: { uuid: 'p1', sku: 'SKU-1', name: 'Cafe' },
    warehouse: { uuid: 'w1', code: 'ALM-1', name: 'Central' },
    quantity: { on_hand: 10, reserved: 2, available: 8 },
    thresholds: { is_low: false, is_overstock: false },
    average_cost: 5,
    last_movement_at: '2026-01-01T00:00:00Z',
    ...over,
  } as unknown as Stock
}

function resetState(): void {
  init.mockReset()
  loadMore.mockReset()
  retry.mockReset()
  whInit.mockReset()
  warehouseUuid.value = ''
  lowStockOnly.value = false
  items.value = []
  loading.value = false
  loadingMore.value = false
  errorMessage.value = null
  hasMore.value = false
  total.value = 0
  whItems.value = []
}

function mountView(): VueWrapper {
  return mount(InventoryView, {
    global: {
      stubs: {
        RouterLink: { template: '<a><slot /></a>' },
        AdjustStockModal: {
          template: '<div class="stub-adjust-modal"></div>',
          props: ['stock'],
          emits: ['adjusted', 'cancel'],
        },
        KardexModal: {
          template: '<div class="stub-kardex-modal"></div>',
          props: ['stock'],
          emits: ['close'],
        },
      },
    },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  resetState()
})

describe('InventoryView', () => {
  it('carga almacenes e inventario al montar', async () => {
    mountView()
    await flushPromises()
    expect(whInit).toHaveBeenCalledTimes(1)
    expect(init).toHaveBeenCalledTimes(1)
  })

  it('muestra el estado de carga', () => {
    loading.value = true
    const wrapper = mountView()
    expect(wrapper.text()).toContain('Cargando inventario')
    expect(wrapper.find('.inv-table').exists()).toBe(false)
  })

  it('muestra el estado vacio cuando no hay existencias', () => {
    items.value = []
    const wrapper = mountView()
    expect(wrapper.text()).toContain('No hay existencias')
  })

  it('muestra el error y permite reintentar', async () => {
    errorMessage.value = 'Fallo de red'
    const wrapper = mountView()
    expect(wrapper.find('.inv-view__error').text()).toBe('Fallo de red')
    await wrapper.find('.inv-view__state button').trigger('click')
    expect(retry).toHaveBeenCalledTimes(1)
  })

  it('renderiza una fila por existencia con cantidades', () => {
    items.value = [makeStock()]
    const wrapper = mountView()
    expect(wrapper.findAll('.inv-table tbody tr')).toHaveLength(1)
    const txt = wrapper.text()
    expect(txt).toContain('Cafe')
    expect(txt).toContain('Central')
    expect(txt).toContain('10')
    expect(txt).toContain('8')
  })

  it('usa el fallback de producto y almacen cuando faltan', () => {
    items.value = [makeStock({ product: undefined, warehouse: undefined })]
    const wrapper = mountView()
    const cells = wrapper.findAll('.inv-table tbody td')
    expect(cells[0].text()).toBe('—')
    expect(cells[1].text()).toBe('—')
  })

  it('muestra el badge de stock bajo', () => {
    items.value = [makeStock({ thresholds: { is_low: true, is_overstock: false } })]
    const wrapper = mountView()
    expect(wrapper.find('.inv-table__badge--low').exists()).toBe(true)
  })

  it('muestra el badge de sobre stock', () => {
    items.value = [makeStock({ thresholds: { is_low: false, is_overstock: true } })]
    const wrapper = mountView()
    expect(wrapper.find('.inv-table__badge--over').exists()).toBe(true)
  })

  it('muestra el badge OK cuando no hay alertas', () => {
    items.value = [makeStock({ thresholds: { is_low: false, is_overstock: false } })]
    const wrapper = mountView()
    expect(wrapper.find('.inv-table__badge--ok').exists()).toBe(true)
  })

  it('renderiza las opciones de almacen en el filtro', () => {
    whItems.value = [
      { uuid: 'w1', code: 'ALM-1', name: 'Central' } as unknown as Warehouse,
      { uuid: 'w2', code: 'ALM-2', name: 'Sucursal' } as unknown as Warehouse,
    ]
    const wrapper = mountView()
    // "Todos" + 2 almacenes = 3 opciones.
    expect(wrapper.findAll('#inv-wh option')).toHaveLength(3)
  })

  it('abre el modal de kardex al pulsar Kardex', async () => {
    items.value = [makeStock()]
    const wrapper = mountView()
    expect(wrapper.find('.stub-kardex-modal').exists()).toBe(false)
    await wrapper.find('.inv-table__btn').trigger('click')
    expect(wrapper.find('.stub-kardex-modal').exists()).toBe(true)
  })

  it('abre el modal de ajuste al pulsar Ajustar', async () => {
    items.value = [makeStock()]
    const wrapper = mountView()
    await wrapper.find('.inv-table__btn--accent').trigger('click')
    expect(wrapper.find('.stub-adjust-modal').exists()).toBe(true)
  })

  it('muestra el total de existencias en el subtitulo', () => {
    total.value = 12
    const wrapper = mountView()
    expect(wrapper.find('.inv-view__subtitle').text()).toContain('12')
  })

  it('muestra Cargar mas y pagina cuando hasMore', async () => {
    items.value = [makeStock()]
    hasMore.value = true
    const wrapper = mountView()
    await wrapper.find('.inv-view__more button').trigger('click')
    expect(loadMore).toHaveBeenCalledTimes(1)
  })
})
