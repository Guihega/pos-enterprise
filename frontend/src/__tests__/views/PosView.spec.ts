import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import PosView from '@/views/PosView.vue'

/**
 * PosView es un orquestador: coordina stores y componentes hijos. Los
 * tests verifican SU logica de coordinacion (reaccionar a eventos de
 * los hijos, mostrar/ocultar modales y banners segun estado), no la
 * implementacion de los hijos ni de los stores, que se mockean.
 */
const mocks = vi.hoisted(() => {
  const { reactive, ref } = require('vue')
  return {
    cart: reactive({
      add: vi.fn(),
      clear: vi.fn(),
      isEmpty: false,
      grandTotal: 100,
      items: [],
    }),
    cash: reactive({
      loadCurrent: vi.fn(async () => {}),
      close: vi.fn(async () => ({ ok: true, session: null })),
      currentSession: { uuid: 'sess-1' },
      hasActiveSession: true,
      loading: false,
      errorMessage: null,
    }),
    sales: reactive({
      checkout: vi.fn(async () => ({ ok: true, sale: { uuid: 's1' } })),
      cancel: vi.fn(async () => ({ ok: true, sale: { uuid: 's1' } })),
      clearError: vi.fn(),
      clearLastSale: vi.fn(),
      submitting: false,
      cancelling: false,
      errorMessage: null,
      lastSale: null,
    }),
    report: {
      report: ref(null),
      load: vi.fn(async () => {}),
      clear: vi.fn(),
    },
  }
})

vi.mock('@/stores/cart', () => ({ useCartStore: () => mocks.cart }))
vi.mock('@/stores/cashSession', () => ({ useCashSessionStore: () => mocks.cash }))
vi.mock('@/stores/sales', () => ({ useSalesStore: () => mocks.sales }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ tenant: 'demo', user: { default_branch: null } }),
}))
vi.mock('@/composables/useCashSessionReport', () => ({
  useCashSessionReport: () => mocks.report,
}))

const STUBS = {
  PosHeader: true,
  PosCatalog: true,
  PosCart: true,
  PosCheckoutBar: true,
  CashOpenModal: true,
  CashCloseModal: true,
  PaymentModal: true,
  PinSupervisorModal: true,
  TicketModal: true,
  CashSessionReportModal: true,
}

function mountView(): VueWrapper {
  return mount(PosView, {
    global: {
      stubs: { ...STUBS, Teleport: true, Transition: false },
    },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.useRealTimers()
  mocks.cart.isEmpty = false
  mocks.cart.grandTotal = 100
  mocks.cash.currentSession = { uuid: 'sess-1' }
  mocks.cash.hasActiveSession = true
  mocks.cash.loading = false
  mocks.cash.errorMessage = null
  mocks.sales.submitting = false
  mocks.sales.cancelling = false
  mocks.sales.errorMessage = null
  mocks.sales.lastSale = null
  mocks.report.report.value = null
})

describe('PosView', () => {
  it('consulta la sesion de caja al montar', () => {
    mountView()
    expect(mocks.cash.loadCurrent).toHaveBeenCalledTimes(1)
  })

  it('muestra el modal de apertura cuando no hay sesion activa', () => {
    mocks.cash.hasActiveSession = false
    const wrapper = mountView()
    expect(wrapper.findComponent({ name: 'CashOpenModal' }).exists()).toBe(true)
  })

  it('oculta el modal de apertura cuando hay sesion activa', () => {
    mocks.cash.hasActiveSession = true
    const wrapper = mountView()
    expect(wrapper.findComponent({ name: 'CashOpenModal' }).exists()).toBe(false)
  })

  it('agrega producto al carrito al recibir productSelected del catalogo', () => {
    const wrapper = mountView()
    wrapper.findComponent({ name: 'PosCatalog' }).vm.$emit('product-selected', { uuid: 'p1' })
    expect(mocks.cart.add).toHaveBeenCalledWith({ uuid: 'p1' })
  })

  it('abre el modal de pago al pedir checkout con carrito no vacio', async () => {
    mocks.cart.isEmpty = false
    const wrapper = mountView()
    wrapper.findComponent({ name: 'PosCheckoutBar' }).vm.$emit('checkout')
    await wrapper.vm.$nextTick()
    expect(wrapper.findComponent({ name: 'PaymentModal' }).props('open')).toBe(true)
  })

  it('no abre el modal de pago si el carrito esta vacio', async () => {
    mocks.cart.isEmpty = true
    const wrapper = mountView()
    wrapper.findComponent({ name: 'PosCheckoutBar' }).vm.$emit('checkout')
    await wrapper.vm.$nextTick()
    expect(wrapper.findComponent({ name: 'PaymentModal' }).props('open')).toBe(false)
  })

  it('confirma la venta y vacia el carrito en exito', async () => {
    const wrapper = mountView()
    await wrapper.findComponent({ name: 'PaymentModal' }).vm.$emit('confirm', [{ method: 'cash', amount: 100 }])
    await wrapper.vm.$nextTick()
    expect(mocks.sales.checkout).toHaveBeenCalledTimes(1)
    expect(mocks.cart.clear).toHaveBeenCalledTimes(1)
  })

  it('abre el modal de cierre de caja al pedirlo desde el header', async () => {
    const wrapper = mountView()
    wrapper.findComponent({ name: 'PosHeader' }).vm.$emit('close-cash')
    await wrapper.vm.$nextTick()
    expect(wrapper.findComponent({ name: 'CashCloseModal' }).props('open')).toBe(true)
  })

  it('carga el corte X al pedirlo desde el header', async () => {
    const wrapper = mountView()
    wrapper.findComponent({ name: 'PosHeader' }).vm.$emit('corte-x')
    await wrapper.vm.$nextTick()
    expect(mocks.report.load).toHaveBeenCalledWith('sess-1')
  })

  it('cierra la caja al confirmar el arqueo', async () => {
    mocks.cash.close.mockResolvedValue({
      ok: true,
      session: { uuid: 'sess-1', closing: { difference: 0 } },
    } as never)
    const wrapper = mountView()
    await wrapper.findComponent({ name: 'CashCloseModal' }).vm.$emit('confirm', 1000, null)
    await wrapper.vm.$nextTick()
    expect(mocks.cash.close).toHaveBeenCalledWith('sess-1', 1000, null)
  })

  it('muestra el banner de venta exitosa cuando hay lastSale', () => {
    mocks.sales.lastSale = {
      uuid: 's1',
      number: 'A-001',
      totals: { total: 100, change: 0 },
    }
    const wrapper = mountView()
    expect(wrapper.find('.pos-success-banner').exists()).toBe(true)
    expect(wrapper.text()).toContain('A-001')
  })

  it('no muestra el banner de exito sin lastSale', () => {
    mocks.sales.lastSale = null
    const wrapper = mountView()
    expect(wrapper.find('.pos-success-banner').exists()).toBe(false)
  })

  it('inicia el flujo de anulacion con PIN desde el banner', async () => {
    mocks.sales.lastSale = {
      uuid: 's1',
      number: 'A-001',
      totals: { total: 100, change: 0 },
    }
    const wrapper = mountView()
    await wrapper.findAll('.pos-success-banner__cancel')[1]!.trigger('click')
    expect(wrapper.findComponent({ name: 'PinSupervisorModal' }).props('open')).toBe(true)
  })

  it('pasa el total del carrito al modal de pago', () => {
    mocks.cart.grandTotal = 250
    const wrapper = mountView()
    expect(wrapper.findComponent({ name: 'PaymentModal' }).props('total')).toBe(250)
  })
})
