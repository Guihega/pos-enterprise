import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { VueWrapper } from '@vue/test-utils'
import TicketModal from '@/components/TicketModal.vue'
import type { Sale, Branch } from '@/lib/api/generated'

function makeSale(overrides: Record<string, unknown> = {}): Sale {
  return {
    uuid: 's1',
    number: 'A-001',
    created_at: '2026-01-01T10:00:00Z',
    completed_at: '2026-01-01T10:01:00Z',
    cashier: { name: 'Ana' },
    items: [
      { uuid: 'i1', quantity: 2, product_name: 'Cafe', unit_price: 25, line_total: 50 },
    ],
    payments: [{ uuid: 'pay1', method: 'cash', amount: 60 }],
    totals: { subtotal: 50, discount: 0, tax: 8, total: 58, change: 2 },
    ...overrides,
  } as unknown as Sale
}

function makeBranch(): Branch {
  return { uuid: 'b1', name: 'Sucursal Centro', code: 'SUC-01' } as unknown as Branch
}

function mountModal(
  props: { sale?: Sale; branch?: Branch | null; visible?: boolean } = {},
): VueWrapper {
  return mount(TicketModal, {
    props: {
      sale: props.sale ?? makeSale(),
      branch: 'branch' in props ? props.branch : makeBranch(),
      visible: props.visible ?? true,
    },
    global: { stubs: { Teleport: true } },
  })
}

let printSpy: ReturnType<typeof vi.spyOn>

beforeEach(() => {
  vi.clearAllMocks()
  printSpy = vi.spyOn(window, 'print').mockImplementation(() => {})
  // El watch immediate usa requestAnimationFrame; lo ejecutamos sincrono.
  vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
    cb(0)
    return 0
  })
})

afterEach(() => {
  printSpy.mockRestore()
  vi.unstubAllGlobals()
})

describe('TicketModal', () => {
  it('renderiza el ticket cuando visible=true', () => {
    const wrapper = mountModal({ visible: true })
    expect(wrapper.find('.ticket').exists()).toBe(true)
    expect(wrapper.find('.ticket-modal.is-hidden').exists()).toBe(false)
  })

  it('aplica is-hidden cuando visible=false', () => {
    const wrapper = mountModal({ visible: false })
    expect(wrapper.find('.ticket-modal.is-hidden').exists()).toBe(true)
  })

  it('muestra el nombre de la sucursal o un fallback', () => {
    const wrapper = mountModal({ branch: makeBranch() })
    expect(wrapper.find('.ticket__shop').text()).toBe('Sucursal Centro')
  })

  it('usa fallback de sucursal cuando branch es null', () => {
    const wrapper = mountModal({ branch: null })
    expect(wrapper.find('.ticket__shop').text()).toBe('Punto de venta')
  })

  it('muestra el folio de la venta', () => {
    const wrapper = mountModal()
    expect(wrapper.text()).toContain('A-001')
  })

  it('renderiza una fila por item de la venta', () => {
    const wrapper = mountModal()
    expect(wrapper.findAll('.ticket__item')).toHaveLength(1)
    expect(wrapper.text()).toContain('Cafe')
  })

  it('traduce el metodo de pago y muestra el cambio', () => {
    const wrapper = mountModal()
    expect(wrapper.text()).toContain('Efectivo')
    expect(wrapper.text()).toContain('Cambio')
  })

  it('oculta el descuento cuando es cero', () => {
    const wrapper = mountModal()
    expect(wrapper.text()).not.toContain('Descuento')
  })

  it('muestra el descuento cuando es mayor a cero', () => {
    const sale = makeSale({ totals: { subtotal: 50, discount: 5, tax: 8, total: 53, change: 0 } })
    const wrapper = mountModal({ sale })
    expect(wrapper.text()).toContain('Descuento')
  })

  it('imprime automaticamente al montar (watch immediate)', async () => {
    mountModal()
    await nextTick()
    expect(printSpy).toHaveBeenCalled()
  })

  it('invoca window.print al pulsar Imprimir', async () => {
    const wrapper = mountModal()
    await nextTick()
    printSpy.mockClear()
    await wrapper.find('.btn-primary').trigger('click')
    expect(printSpy).toHaveBeenCalledTimes(1)
  })

  it('emite close al pulsar Cerrar', async () => {
    const wrapper = mountModal()
    await wrapper.find('.btn-secondary').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('emite close al pulsar el overlay', async () => {
    const wrapper = mountModal()
    await wrapper.find('.ticket-modal__overlay').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
  })
})
