import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import CashSessionReportModal from '@/components/CashSessionReportModal.vue'
import type { CashSessionReport } from '@/lib/api/generated'

/**
 * Fixture base de un reporte de corte. Cada test puede clonar y ajustar.
 * status 'closed' por defecto (CORTE Z con contado/diferencia).
 */
function makeReport(overrides: Partial<CashSessionReport> = {}): CashSessionReport {
  const base: CashSessionReport = {
    session: {
      uuid: 'sess-1',
      status: 'closed',
      opened_at: '2026-01-01T08:00:00Z',
      closed_at: '2026-01-01T16:00:00Z',
      opening: { amount: 1000, notes: null, by: { uuid: 'u1', name: 'Ana' } },
      closing: { amount: 1000, notes: null, by: { uuid: 'u2', name: 'Beto' } },
      register: { uuid: 'reg-1', code: 'C1', name: 'Caja Principal' },
    },
    sales: { count: 12, total_amount: 3450.5 },
    payments: [
      { method: 'cash', count: 8, amount: 2000 },
      { method: 'card_credit', count: 4, amount: 1450.5 },
    ],
    movements: [
      { type: 'sale_cash', count: 8, amount: 2000, delta_signed: 2000 },
      { type: 'cash_out', count: 1, amount: 200, delta_signed: -200 },
    ],
    cash: {
      opening_amount: 1000,
      cash_affecting_delta: 1800,
      expected_amount: 2800,
      counted_amount: 2750,
      difference: -50,
    },
  } as CashSessionReport
  return { ...base, ...overrides }
}

function mountModal(
  props: { report?: CashSessionReport; visible?: boolean } = {},
): VueWrapper {
  return mount(CashSessionReportModal, {
    props: { report: props.report ?? makeReport(), visible: props.visible ?? true },
    global: { stubs: { Teleport: true } },
  })
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('CashSessionReportModal', () => {
  it('renderiza el reporte cuando visible=true', () => {
    const wrapper = mountModal({ visible: true })
    expect(wrapper.find('.report').exists()).toBe(true)
    expect(wrapper.find('.report-modal.is-hidden').exists()).toBe(false)
  })

  it('aplica is-hidden cuando visible=false', () => {
    const wrapper = mountModal({ visible: false })
    expect(wrapper.find('.report-modal.is-hidden').exists()).toBe(true)
  })

  it('muestra el nombre y codigo de la caja', () => {
    const wrapper = mountModal()
    expect(wrapper.find('.report__shop').text()).toBe('Caja Principal')
    expect(wrapper.text()).toContain('C1')
  })

  it('muestra CORTE Z cuando la sesion esta cerrada', () => {
    const wrapper = mountModal({ report: makeReport() })
    expect(wrapper.find('.report__title').text()).toBe('CORTE Z')
  })

  it('muestra CORTE X cuando la sesion sigue abierta', () => {
    const report = makeReport()
    report.session.status = 'open'
    const wrapper = mountModal({ report })
    expect(wrapper.find('.report__title').text()).toBe('CORTE X')
  })

  it('traduce los metodos de pago a etiquetas legibles', () => {
    const wrapper = mountModal()
    const txt = wrapper.text()
    expect(txt).toContain('Efectivo')
    expect(txt).toContain('Tarjeta credito')
  })

  it('traduce los tipos de movimiento a etiquetas legibles', () => {
    const wrapper = mountModal()
    const txt = wrapper.text()
    expect(txt).toContain('Ventas en efectivo')
    expect(txt).toContain('Salidas de efectivo')
  })

  it('muestra el bloque Contado y Diferencia cuando counted_amount no es null', () => {
    const wrapper = mountModal()
    expect(wrapper.text()).toContain('Contado')
    expect(wrapper.text()).toContain('Diferencia')
  })

  it('oculta Contado y Diferencia cuando counted_amount es null (corte X)', () => {
    const report = makeReport()
    report.cash.counted_amount = null
    report.cash.difference = null
    const wrapper = mountModal({ report })
    expect(wrapper.text()).not.toContain('Contado')
    expect(wrapper.text()).not.toContain('Diferencia')
  })

  it('muestra mensaje cuando no hay pagos registrados', () => {
    const report = makeReport()
    report.payments = []
    const wrapper = mountModal({ report })
    expect(wrapper.text()).toContain('Sin pagos registrados')
  })

  it('muestra mensaje cuando no hay movimientos registrados', () => {
    const report = makeReport()
    report.movements = []
    const wrapper = mountModal({ report })
    expect(wrapper.text()).toContain('Sin movimientos registrados')
  })

  it('emite close al pulsar el boton Cerrar', async () => {
    const wrapper = mountModal()
    await wrapper.find('.btn-secondary').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('emite close al pulsar el overlay', async () => {
    const wrapper = mountModal()
    await wrapper.find('.report-modal__overlay').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('invoca window.print al pulsar Imprimir', async () => {
    const printSpy = vi.spyOn(window, 'print').mockImplementation(() => {})
    const wrapper = mountModal()
    await wrapper.find('.btn-primary').trigger('click')
    expect(printSpy).toHaveBeenCalledTimes(1)
    printSpy.mockRestore()
  })
})
