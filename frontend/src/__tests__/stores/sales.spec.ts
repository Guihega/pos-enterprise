import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { useSalesStore } from '@/stores/sales'
import type { CreateSalePayment } from '@/lib/api/generated'

vi.mock('@/lib/api/generated', () => ({
  createSale: vi.fn<typeof apiCreateSale>(),
}))

import { createSale as apiCreateSale } from '@/lib/api/generated'

// --- Mocks db / SyncQueueRepository / sync store ---
const salesPut = vi.fn<() => Promise<void>>().mockResolvedValue(undefined)
const enqueueSpy = vi.fn<() => Promise<number>>().mockResolvedValue(1)
const refreshCountsSpy = vi.fn<() => Promise<void>>().mockResolvedValue(undefined)

vi.mock('@/db/schema', () => ({
  db: {
    sales: { put: (...args: unknown[]) => salesPut(...args) },
  },
}))

vi.mock('@/repositories/SyncQueueRepository', () => ({
  enqueue: (...args: unknown[]) => enqueueSpy(...args),
}))

vi.mock('@/stores/sync', () => ({
  useSyncStore: () => ({ refreshCounts: refreshCountsSpy }),
}))
// ---------------------------------------------------

const mockAuth = {
  tenant: 'demo' as string | null,
  user: {
    default_branch: {
      default_warehouse_uuid: 'wh-default' as string | null,
    },
  } as unknown,
}

const mockCart = {
  items: [] as Array<{ productUuid: string; quantity: number }>,
  isEmpty: true,
  subtotal: 0,
  taxTotal: 0,
  grandTotal: 0,
}

const loadCurrentSpy = vi.fn<() => Promise<void>>()

const mockCash = {
  currentSession: { uuid: 's-1', register: { uuid: 'reg-1' } } as
    | { uuid: string; register?: { uuid: string } }
    | null,
  loadCurrent: loadCurrentSpy,
}

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => mockAuth,
}))
vi.mock('@/stores/cart', () => ({
  useCartStore: () => mockCart,
}))
vi.mock('@/stores/cashSession', () => ({
  useCashSessionStore: () => mockCash,
}))

function resetScenario(): void {
  mockAuth.tenant = 'demo'
  mockAuth.user = {
    default_branch: { default_warehouse_uuid: 'wh-default' },
  }
  mockCart.items = [
    { productUuid: 'p-1', quantity: 2 },
    { productUuid: 'p-2', quantity: 1 },
  ]
  mockCart.isEmpty = false
  mockCart.subtotal = 86
  mockCart.taxTotal = 14
  mockCart.grandTotal = 100
  mockCash.currentSession = { uuid: 's-1', register: { uuid: 'reg-1' } }
  Object.defineProperty(navigator, 'onLine', { value: true, configurable: true })
}

function cashPayment(amount = 100): CreateSalePayment {
  return { method: 'cash', amount }
}

function saleOk(uuid = 'sale-1'): unknown {
  return {
    data: { data: { uuid, folio: 'A-001', totals: { grand_total: 100 } } },
    error: undefined,
  }
}

function saleError(code: string, message: string): unknown {
  return {
    data: undefined,
    error: { error: { code, message } },
  }
}

beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
  resetScenario()
})

describe('sales store', () => {
  it('inicial: submitting=false, sin error, sin ultima venta', () => {
    const store = useSalesStore()

    expect(store.submitting).toBe(false)
    expect(store.errorMessage).toBeNull()
    expect(store.lastSale).toBeNull()
  })

  it('precondicion: sin sesion activa -> ok=false, sessionLost=true, no llama SDK', async () => {
    mockCash.currentSession = null
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(result.sessionLost).toBe(true)
    expect(apiCreateSale).not.toHaveBeenCalled()
    expect(store.errorMessage).toBe('No hay sesion de caja abierta.')
  })

  it('precondicion: sin warehouse default -> ok=false, mensaje de almacen, no llama SDK', async () => {
    mockAuth.user = { default_branch: { default_warehouse_uuid: null } }
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(result.sessionLost).toBeUndefined()
    expect(apiCreateSale).not.toHaveBeenCalled()
    expect(store.errorMessage).toContain('almacen default')
  })

  it('precondicion: carrito vacio -> ok=false, no llama SDK', async () => {
    mockCart.isEmpty = true
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(apiCreateSale).not.toHaveBeenCalled()
    expect(store.errorMessage).toBe('El carrito esta vacio.')
  })

  it('precondicion: sin pagos -> ok=false, no llama SDK', async () => {
    const store = useSalesStore()

    const result = await store.checkout([])

    expect(result.ok).toBe(false)
    expect(apiCreateSale).not.toHaveBeenCalled()
    expect(store.errorMessage).toBe('Debes registrar al menos un pago.')
  })

  it('exito: ok=true, guarda lastSale y arma el body con shape correcto', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue(saleOk('sale-99') as never)
    const store = useSalesStore()
    const payments = [cashPayment(100)]

    const result = await store.checkout(payments)

    expect(result.ok).toBe(true)
    expect(result.sale).toMatchObject({ uuid: 'sale-99' })
    expect(store.lastSale).toMatchObject({ uuid: 'sale-99' })

    expect(apiCreateSale).toHaveBeenCalledTimes(1)
    expect(apiCreateSale).toHaveBeenCalledWith({
      headers: { 'X-Tenant': 'demo' },
      body: {
        cash_session_uuid: 's-1',
        warehouse_uuid: 'wh-default',
        items: [
          { product_uuid: 'p-1', quantity: 2 },
          { product_uuid: 'p-2', quantity: 1 },
        ],
        payments,
      },
    })
  })

  it('error SESSION_NOT_OPEN -> ok=false, sessionLost=true, refresca cashSession', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue(
      saleError('SESSION_NOT_OPEN', 'cerrada') as never,
    )
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(result.sessionLost).toBe(true)
    expect(loadCurrentSpy).toHaveBeenCalledTimes(1)
    expect(store.errorMessage).toContain('Recarga el POS')
  })

  it('error INSUFFICIENT_STOCK -> ok=false, mensaje humanizado del backend', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue(
      saleError('INSUFFICIENT_STOCK', 'No hay stock de Coca-Cola') as never,
    )
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(result.sessionLost).toBeUndefined()
    expect(store.errorMessage).toBe('No hay stock de Coca-Cola')
    expect(loadCurrentSpy).not.toHaveBeenCalled()
  })

  it('error PAYMENT_MISMATCH -> ok=false, mensaje humanizado', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue(
      saleError('PAYMENT_MISMATCH', 'Los pagos no cuadran') as never,
    )
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(store.errorMessage).toBe('Los pagos no cuadran')
  })

  it('error INSUFFICIENT_CREDIT -> ok=false, mensaje humanizado', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue(
      saleError('INSUFFICIENT_CREDIT', 'Credito insuficiente') as never,
    )
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(store.errorMessage).toBe('Credito insuficiente')
  })

  it('error 422 validacion Laravel -> extrae el primer mensaje de errors', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue({
      data: undefined,
      error: {
        message: 'The given data was invalid.',
        errors: {
          'items.0.quantity': ['La cantidad debe ser mayor a cero.'],
          warehouse_uuid: ['El almacen es invalido.'],
        },
      },
    } as never)
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(store.errorMessage).toBe('La cantidad debe ser mayor a cero.')
  })

  it('error generico (sin code conocido ni errors) -> mensaje fallback', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue({
      data: undefined,
      error: { error: { code: 'WHATEVER' } },
    } as never)
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(store.errorMessage).toBe('No se pudo registrar la venta.')
  })

  it('submitting vuelve a false tras exito y tras error (finally)', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue(saleOk() as never)
    const store = useSalesStore()

    await store.checkout([cashPayment()])
    expect(store.submitting).toBe(false)

    vi.mocked(apiCreateSale).mockResolvedValue(
      saleError('PAYMENT_MISMATCH', 'x') as never,
    )
    await store.checkout([cashPayment()])
    expect(store.submitting).toBe(false)
  })

  it('clearError, clearLastSale y clear limpian el state', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue(saleOk('sale-1') as never)
    const store = useSalesStore()

    await store.checkout([cashPayment()])
    expect(store.lastSale).not.toBeNull()

    store.clearLastSale()
    expect(store.lastSale).toBeNull()

    vi.mocked(apiCreateSale).mockResolvedValue(
      saleError('PAYMENT_MISMATCH', 'x') as never,
    )
    await store.checkout([cashPayment()])
    expect(store.errorMessage).not.toBeNull()

    store.clearError()
    expect(store.errorMessage).toBeNull()

    await store.checkout([cashPayment()])
    store.clear()
    expect(store.submitting).toBe(false)
    expect(store.errorMessage).toBeNull()
    expect(store.lastSale).toBeNull()
  })
})

describe('checkout offline (9a, RN-150)', () => {
  it('navigator.onLine=false: NO llama createSale, persiste SaleLocal, encola y refresca conteos', async () => {
    Object.defineProperty(navigator, 'onLine', { value: false, configurable: true })
    const store = useSalesStore()
    const payments = [cashPayment(100)]

    const result = await store.checkout(payments)

    expect(apiCreateSale).not.toHaveBeenCalled()
    expect(salesPut).toHaveBeenCalledTimes(1)
    const sale = salesPut.mock.calls[0][0] as Record<string, unknown>
    expect(sale.createdOffline).toBe(true)
    expect(sale.syncStatus).toBe('pending')
    expect(sale.status).toBe('completed')

    expect(enqueueSpy).toHaveBeenCalledTimes(1)
    const eq = enqueueSpy.mock.calls[0][0] as Record<string, unknown>
    expect(eq.entityType).toBe('sale')
    expect(eq.operation).toBe('create')
    expect(eq.payload).toMatchObject({
      cash_session_uuid: 's-1',
      warehouse_uuid: 'wh-default',
    })

    expect(refreshCountsSpy).toHaveBeenCalledTimes(1)
    expect(result.ok).toBe(true)
    expect(result.offline).toBe(true)
  })

  it('clientUuid de enqueue coincide con uuid de la SaleLocal', async () => {
    Object.defineProperty(navigator, 'onLine', { value: false, configurable: true })
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(true)
    const sale = salesPut.mock.calls[0][0] as Record<string, unknown>
    const eq = enqueueSpy.mock.calls[0][0] as Record<string, unknown>
    expect(eq.clientUuid).toBe(sale.uuid)
    expect(eq.entityUuid).toBe(sale.uuid)
  })

  it('fallo de red durante POST online degrada a offline (ok=true, offline=true)', async () => {
    vi.mocked(apiCreateSale).mockRejectedValue(new TypeError('Failed to fetch'))
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(true)
    expect(result.offline).toBe(true)
    expect(salesPut).toHaveBeenCalledTimes(1)
    expect(enqueueSpy).toHaveBeenCalledTimes(1)
  })

  it('si db.sales.put lanza -> ok=false y errorMessage poblado', async () => {
    Object.defineProperty(navigator, 'onLine', { value: false, configurable: true })
    salesPut.mockRejectedValueOnce(new Error('IDB error'))
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(false)
    expect(store.errorMessage).not.toBeNull()
  })

  it('navigator.onLine=true y createSale ok: NO encola (ruta online intacta)', async () => {
    vi.mocked(apiCreateSale).mockResolvedValue(saleOk('sale-online') as never)
    const store = useSalesStore()

    const result = await store.checkout([cashPayment()])

    expect(result.ok).toBe(true)
    expect(result.offline).toBeUndefined()
    expect(enqueueSpy).not.toHaveBeenCalled()
    expect(salesPut).not.toHaveBeenCalled()
  })
})
