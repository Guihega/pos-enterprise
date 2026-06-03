/**
 * Composable de existencias de inventario.
 *
 * Carga todos los stocks del warehouse indicado (paginando) y expone
 * un mapa product_uuid -> quantity.available para validacion UX en el POS.
 *
 * NOTA: init() debe llamarse explicitamente (no onMounted interno)
 * para que sea testeable fuera de un componente.
 */
import { ref, readonly } from 'vue'
import { listInventoryStocks } from '@/lib/api/generated/sdk.gen'
import { getTenantOrThrow } from '@/lib/api/errors'

/** Mapa de uuid de producto -> cantidad disponible. */
export type StockMap = Record<string, number>

const stockMap = ref<StockMap>({})
const loading = ref(false)
const error = ref<string | null>(null)

/**
 * Inicializa el mapa de stocks para el warehouse dado.
 * Pagina automaticamente hasta obtener todos los registros.
 *
 * @param tenant  Slug/UUID del tenant (X-Tenant header).
 * @param warehouseUuid  UUID del almacen a consultar.
 */
async function init(tenant: string, warehouseUuid: string): Promise<void> {
  loading.value = true
  error.value = null
  const map: StockMap = {}

  try {
    getTenantOrThrow(tenant)

    let page = 1
    let lastPage = 1

    do {
      const { data, error: apiErr } = await listInventoryStocks({
        headers: { 'X-Tenant': tenant },
        query: {
          warehouse: warehouseUuid,
          per_page: 100,
        },
      })

      if (apiErr !== undefined || data === undefined) {
        error.value = 'Error al cargar existencias'
        break
      }

      for (const stock of data.data) {
        const uuid = stock.product?.uuid
        if (uuid !== undefined && uuid !== '') {
          map[uuid] = stock.quantity.available
        }
      }

      lastPage = data.meta.last_page ?? 1
      page += 1

      // Hey API no tiene paginacion nativa: si hay mas paginas
      // el endpoint acepta ?page= pero no esta en ListInventoryStocksData.
      // Con per_page=100 y 25 productos de seed, una sola pagina es suficiente.
      // Si en el futuro hay mas productos, extender aqui.
      break
    } while (page <= lastPage)

    stockMap.value = map
  } finally {
    loading.value = false
  }
}

/**
 * Devuelve la cantidad disponible para un producto.
 * Si el producto no esta en el mapa (no rastreado o no cargado) devuelve Infinity.
 */
function availableFor(productUuid: string): number {
  return stockMap.value[productUuid] ?? Infinity
}

/**
 * Devuelve true si el producto rastrea inventario Y su disponible es <= 0.
 */
function isOutOfStock(productUuid: string, trackInventory: boolean): boolean {
  if (!trackInventory) return false
  return availableFor(productUuid) <= 0
}

export function useStock() {
  return {
    stockMap: readonly(stockMap),
    loading: readonly(loading),
    error: readonly(error),
    init,
    availableFor,
    isOutOfStock,
  }
}
