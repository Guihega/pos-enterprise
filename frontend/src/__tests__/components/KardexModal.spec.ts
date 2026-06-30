import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import type { VueWrapper } from '@vue/test-utils'
import KardexModal from '@/components/KardexModal.vue'
import type { Stock } from '@/lib/api/generated'

const kardex = {
  init: vi.fn(async () => {}),
  items: ref<unknown[]>([]),
  loading: ref(false),
  loadingMore: ref(false),
  errorMessage: ref<string | null>(null),
  hasMore: ref(false),
  total: ref(0),
  loadMore: vi.fn(async () => {}),
  retry: vi.fn(async () => {}),
}

vi.mock('@/composables/useKardex', () => ({ useKardex: () => kardex }))

function makeStock(): Stock {
  return {
    product: { uuid: 'p1', name: 'Cafe', sku: 'CAF-001' },
    warehouse: { uuid: 'wh-1', name: 'Principal' },
  } as unknown as Stock
}

function makeMovement(overrides: Record<string, unknown> = {}): unknown {
  return {
    uuid: 'm1',
    type: 'entry',
    movement_at: '2026-01-01T10:00:00Z',
    quantity: { delta: 5, after: 15 },
    reason: 'Compra',
    ...overrides,
  }
}

function mountModal(stock: Stock = makeStock()): VueWrapper {
  return mount(KardexModal, { props: { stock } })
}

beforeEach(() => {
  vi.clearAllMocks()
  kardex.items.value = []
  kardex.loading.value = false
  kardex.loadingMore.value = false
  kardex.errorMessage.value = null
  kardex.hasMore.value = false
  kardex.total.value = 0
})

describe('KardexModal', () => {
  it('inicializa el kardex del producto al montar', () => {
    mountModal()
    expect(kardex.init).toHaveBeenCalledWith('p1', 'wh-1')
  })

  it('muestra nombre y sku del producto', () => {
    const wrapper = mountModal()
    expect(wrapper.text()).toContain('Cafe')
    expect(wrapper.text()).toContain('CAF-001')
  })

  it('muestra estado de carga', () => {
    kardex.loading.value = true
    const wrapper = mountModal()
    expect(wrapper.text()).toContain('Cargando historial')
  })

  it('muestra mensaje cuando no hay movimientos', () => {
    const wrapper = mountModal()
    expect(wrapper.text()).toContain('Sin movimientos registrados')
  })

  it('muestra el error y el boton Reintentar', () => {
    kardex.errorMessage.value = 'Fallo'
    const wrapper = mountModal()
    expect(wrapper.find('.kdx-modal__error').text()).toBe('Fallo')
  })

  it('retry se invoca al pulsar Reintentar', async () => {
    kardex.errorMessage.value = 'Fallo'
    const wrapper = mountModal()
    await wrapper.find('.kdx-modal__state button').trigger('click')
    expect(kardex.retry).toHaveBeenCalledTimes(1)
  })

  it('renderiza una fila por movimiento y el total', () => {
    kardex.items.value = [makeMovement({ uuid: 'm1' }), makeMovement({ uuid: 'm2' })]
    kardex.total.value = 2
    const wrapper = mountModal()
    expect(wrapper.findAll('.kdx-table tbody tr')).toHaveLength(2)
    expect(wrapper.find('.kdx-modal__count').text()).toContain('2')
  })

  it('traduce el tipo de movimiento a etiqueta legible', () => {
    kardex.items.value = [makeMovement({ type: 'adjustment' })]
    kardex.total.value = 1
    const wrapper = mountModal()
    expect(wrapper.text()).toContain('Ajuste')
  })

  it('muestra Cargar mas cuando hasMore es true', () => {
    kardex.items.value = [makeMovement()]
    kardex.total.value = 1
    kardex.hasMore.value = true
    const wrapper = mountModal()
    expect(wrapper.find('.kdx-modal__more button').exists()).toBe(true)
  })

  it('loadMore se invoca al pulsar Cargar mas', async () => {
    kardex.items.value = [makeMovement()]
    kardex.total.value = 1
    kardex.hasMore.value = true
    const wrapper = mountModal()
    await wrapper.find('.kdx-modal__more button').trigger('click')
    expect(kardex.loadMore).toHaveBeenCalledTimes(1)
  })

  it('emite close al pulsar la X', async () => {
    const wrapper = mountModal()
    await wrapper.find('.kdx-modal__close').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })
})
