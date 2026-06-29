import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { ref } from 'vue'
import DashboardView from '@/views/DashboardView.vue'
import type { SalesSummary } from '@/lib/api/generated'

// Composable useSalesSummary -> refs (se desestructuran, acceso .value).
const init = vi.fn()
const date = ref('2026-01-01')
const summary = ref<SalesSummary | null>(null)
const loading = ref(false)
const errorMessage = ref<string | null>(null)

vi.mock('@/composables/useSalesSummary', () => ({
  useSalesSummary: () => ({ init, date, summary, loading, errorMessage }),
}))

function makeSummary(over: Partial<SalesSummary> = {}): SalesSummary {
  return {
    date: '2026-01-01',
    branch: null,
    totals: {
      sales_count: 12,
      gross_amount: 3400,
      subtotal_amount: 3000,
      discount_amount: 0,
      tax_amount: 400,
      average_ticket: 283.33,
    },
    payments: [
      { method: 'cash', count: 8, amount: 2000 },
      { method: 'card_credit', count: 4, amount: 1400 },
    ],
    top_products: [
      { product_uuid: 'p1', sku: 'SKU-1', name: 'Cafe', quantity: 20, amount: 500 },
    ],
    ...over,
  } as unknown as SalesSummary
}

function resetState(): void {
  init.mockReset()
  date.value = '2026-01-01'
  summary.value = null
  loading.value = false
  errorMessage.value = null
}

function mountView(): VueWrapper {
  return mount(DashboardView, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  resetState()
})

describe('DashboardView', () => {
  it('inicializa el resumen al montar', () => {
    mountView()
    expect(init).toHaveBeenCalledTimes(1)
  })

  it('muestra el estado de carga cuando no hay resumen aun', () => {
    loading.value = true
    summary.value = null
    const wrapper = mountView()
    expect(wrapper.text()).toContain('Cargando resumen')
  })

  it('no muestra carga si ya hay un resumen previo', () => {
    loading.value = true
    summary.value = makeSummary()
    const wrapper = mountView()
    expect(wrapper.find('.loading').exists()).toBe(false)
  })

  it('muestra el banner de error', () => {
    errorMessage.value = 'No se pudo cargar el resumen'
    const wrapper = mountView()
    expect(wrapper.find('.banner-error').text()).toBe('No se pudo cargar el resumen')
  })

  it('renderiza las tarjetas de totales', () => {
    summary.value = makeSummary()
    const wrapper = mountView()
    const cards = wrapper.findAll('.card')
    expect(cards).toHaveLength(4)
    const txt = wrapper.text()
    expect(txt).toContain('Total vendido')
    expect(txt).toContain('Tickets')
    expect(txt).toContain('12')
  })

  it('renderiza la tabla de pagos por metodo traducidos', () => {
    summary.value = makeSummary()
    const wrapper = mountView()
    const txt = wrapper.text()
    expect(txt).toContain('Efectivo')
    expect(txt).toContain('Tarjeta credito')
  })

  it('muestra el metodo crudo si no hay etiqueta conocida', () => {
    summary.value = makeSummary({
      payments: [{ method: 'crypto', count: 1, amount: 50 }],
    })
    const wrapper = mountView()
    expect(wrapper.text()).toContain('crypto')
  })

  it('muestra el vacio de pagos cuando no hay pagos', () => {
    summary.value = makeSummary({ payments: [] })
    const wrapper = mountView()
    expect(wrapper.text()).toContain('Sin pagos registrados')
  })

  it('renderiza los productos top del dia', () => {
    summary.value = makeSummary()
    const wrapper = mountView()
    expect(wrapper.text()).toContain('Cafe')
    expect(wrapper.text()).toContain('SKU-1')
  })

  it('muestra el vacio de productos cuando no hay ventas', () => {
    summary.value = makeSummary({ top_products: [] })
    const wrapper = mountView()
    expect(wrapper.text()).toContain('Sin productos vendidos')
  })

  it('no renderiza las secciones cuando no hay resumen', () => {
    summary.value = null
    const wrapper = mountView()
    expect(wrapper.find('.cards').exists()).toBe(false)
    expect(wrapper.find('.panel').exists()).toBe(false)
  })
})
