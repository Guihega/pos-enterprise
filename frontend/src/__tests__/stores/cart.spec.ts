import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'

import { useCartStore } from '@/stores/cart'
import type { Product } from '@/lib/api/generated'

/**
 * Mock del store de auth con tenant fijo. El cart store lee
 * authStore.tenant para decidir si persiste/restaura.
 */
let mockTenant: string | null = 'demo'

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get tenant() {
      return mockTenant
    },
  }),
}))

/** Construye un Product fake con valores razonables. */
function makeProduct(overrides: Partial<Product> = {}): Product {
  return {
    uuid: 'p-1',
    sku: 'SKU-1',
    name: 'Coca-Cola 600ml',
    pricing: {
      cost: 50,
      price: 100,
      has_discount: false,
    },
    flags: {
      track_inventory: true,
      is_sellable: true,
      is_purchasable: true,
      allow_decimals: false,
    },
    status: 'active',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    unit: {
      uuid: 'u-pza',
      code: 'PZA',
      name: 'Pieza',
      symbol: 'pza',
    },
    tax: {
      uuid: 't-iva16',
      code: 'IVA-16',
      name: 'IVA 16% inclusivo',
      rate: 0.16,
      is_inclusive: true,
    },
    ...overrides,
  } as Product
}

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  mockTenant = 'demo'
})

describe('cart store', () => {
  it('inicial: vacio, getters en 0/true', () => {
    const cart = useCartStore()

    expect(cart.items).toEqual([])
    expect(cart.lineCount).toBe(0)
    expect(cart.totalQuantity).toBe(0)
    expect(cart.subtotal).toBe(0)
    expect(cart.taxTotal).toBe(0)
    expect(cart.grandTotal).toBe(0)
    expect(cart.isEmpty).toBe(true)
  })

  it('add: crea nueva linea con snapshot del producto', () => {
    const cart = useCartStore()
    const product = makeProduct()

    cart.add(product)

    expect(cart.items).toHaveLength(1)
    expect(cart.items[0]).toEqual({
      productUuid: 'p-1',
      name: 'Coca-Cola 600ml',
      sku: 'SKU-1',
      unitSymbol: 'pza',
      unitPrice: 100,
      quantity: 1,
      taxRate: 0.16,
      taxIsInclusive: true,
      allowDecimals: false,
    })
  })

  it('add: si el producto ya esta, suma a la cantidad existente', () => {
    const cart = useCartStore()
    const product = makeProduct()

    cart.add(product)
    cart.add(product)
    cart.add(product, 2)

    expect(cart.items).toHaveLength(1)
    expect(cart.items[0]?.quantity).toBe(4)
  })

  it('setQuantity: con 0 o negativo elimina la linea', () => {
    const cart = useCartStore()
    cart.add(makeProduct())
    cart.add(makeProduct({ uuid: 'p-2', sku: 'SKU-2', name: 'Pan' }))

    cart.setQuantity('p-1', 0)

    expect(cart.items).toHaveLength(1)
    expect(cart.items[0]?.productUuid).toBe('p-2')
  })

  it('setQuantity: redondea segun allow_decimals del producto', () => {
    const cart = useCartStore()
    // Producto sin decimales: 2.7 -> 3
    cart.add(makeProduct())
    cart.setQuantity('p-1', 2.7)
    expect(cart.items[0]?.quantity).toBe(3)

    // Producto con decimales: 0.3456 -> 0.346 (3 decimales)
    cart.add(makeProduct({
      uuid: 'p-2',
      sku: 'SKU-2',
      name: 'Manzana',
      flags: {
        track_inventory: true,
        is_sellable: true,
        is_purchasable: true,
        allow_decimals: true,
      },
    }))
    cart.setQuantity('p-2', 0.3456)
    expect(cart.items.find((i) => i.productUuid === 'p-2')?.quantity).toBe(0.346)
  })

  it('remove y clear: comportamiento esperado', () => {
    const cart = useCartStore()
    cart.add(makeProduct())
    cart.add(makeProduct({ uuid: 'p-2', sku: 'SKU-2', name: 'Pan' }))

    cart.remove('p-1')
    expect(cart.items).toHaveLength(1)

    cart.clear()
    expect(cart.items).toHaveLength(0)
    expect(cart.isEmpty).toBe(true)
  })

  it('calculos IVA inclusivo: $100 con 16% -> base 86.21, iva 13.79', () => {
    const cart = useCartStore()
    cart.add(makeProduct()) // $100 IVA-16 inclusivo

    expect(cart.subtotal).toBe(86.21)
    expect(cart.taxTotal).toBe(13.79)
    expect(cart.grandTotal).toBe(100)
  })

  it('calculos IVA mixto: inclusivo + no-inclusivo se suman correctamente', () => {
    const cart = useCartStore()
    // Producto A: $100 con IVA 16% inclusivo
    cart.add(makeProduct())
    // Producto B: $100 con IVA 16% NO inclusivo (precio sin tax)
    cart.add(makeProduct({
      uuid: 'p-2',
      sku: 'SKU-2',
      name: 'Servicio',
      tax: {
        uuid: 't-iva16e',
        code: 'IVA-16-EXCL',
        name: 'IVA 16% exclusivo',
        rate: 0.16,
        is_inclusive: false,
      },
    }))

    // A: base 86.21, iva 13.79, total 100
    // B: base 100,   iva 16,    total 116
    // Subtotal = 186.21
    // IVA      = 29.79
    // Total    = 216
    expect(cart.subtotal).toBe(186.21)
    expect(cart.taxTotal).toBe(29.79)
    expect(cart.grandTotal).toBe(216)
  })

  it('hydrate: restaura items si el tenant persistido coincide', async () => {
    // Pre-poblar localStorage como si vinieramos de una sesion previa.
    const items = [
      {
        productUuid: 'p-1',
        name: 'Producto X',
        sku: 'SKU-X',
        unitSymbol: 'pza',
        unitPrice: 50,
        quantity: 2,
        taxRate: 0.16,
        taxIsInclusive: true,
        allowDecimals: false,
      },
    ]
    localStorage.setItem('pos:cart:items', JSON.stringify(items))
    localStorage.setItem('pos:cart:tenant', 'demo')

    const cart = useCartStore()
    cart.hydrate()
    await nextTick()

    expect(cart.items).toHaveLength(1)
    expect(cart.items[0]?.productUuid).toBe('p-1')
    expect(cart.items[0]?.quantity).toBe(2)
  })

  it('hydrate: descarta items si el tenant persistido NO coincide', async () => {
    localStorage.setItem('pos:cart:items', JSON.stringify([{ productUuid: 'p-1' }]))
    localStorage.setItem('pos:cart:tenant', 'otra-empresa')

    const cart = useCartStore()
    cart.hydrate()
    await nextTick()

    expect(cart.items).toHaveLength(0)
    expect(localStorage.getItem('pos:cart:items')).toBeNull()
    expect(localStorage.getItem('pos:cart:tenant')).toBeNull()
  })
})
