<script setup lang="ts">
import { onMounted } from 'vue'
import { useKardex } from '@/composables/useKardex'
import type { Stock, InventoryMovement } from '@/lib/api/generated'

const props = defineProps<{ stock: Stock }>()
const emit = defineEmits<{ close: [] }>()

const {
  init,
  items,
  loading,
  loadingMore,
  errorMessage,
  hasMore,
  total,
  loadMore,
  retry,
} = useKardex()

const productName = props.stock.product?.name ?? 'Producto'
const productSku = props.stock.product?.sku ?? ''

onMounted(() => {
  const productUuid = props.stock.product?.uuid
  const warehouseUuid = props.stock.warehouse?.uuid
  if (productUuid) {
    void init(productUuid, warehouseUuid)
  }
})

/** Etiqueta legible del tipo de movimiento. */
const TYPE_LABELS: Record<string, string> = {
  entry: 'Entrada',
  exit: 'Salida',
  adjustment: 'Ajuste',
  transfer_out: 'Transferencia (salida)',
  transfer_in: 'Transferencia (entrada)',
  return_customer: 'Devolucion cliente',
  return_supplier: 'Devolucion proveedor',
  production_in: 'Produccion (entrada)',
  production_out: 'Produccion (salida)',
  opening: 'Apertura',
}

function typeLabel(type: InventoryMovement['type']): string {
  return TYPE_LABELS[type] ?? type
}

/** Formatea la fecha ISO a algo legible local. */
function formatDate(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleString()
}
</script>

<template>
  <div class="kdx-modal__backdrop" @click.self="emit('close')">
    <div class="kdx-modal" role="dialog" aria-modal="true">
      <header class="kdx-modal__header">
        <div>
          <h2>Kardex</h2>
          <p class="kdx-modal__subtitle">
            {{ productName }}
            <span v-if="productSku" class="kdx-modal__sku">{{ productSku }}</span>
          </p>
        </div>
        <button type="button" class="kdx-modal__close" aria-label="Cerrar" @click="emit('close')">&times;</button>
      </header>

      <div class="kdx-modal__body">
        <div v-if="errorMessage" class="kdx-modal__state">
          <p class="kdx-modal__error">{{ errorMessage }}</p>
          <button type="button" @click="retry">Reintentar</button>
        </div>

        <div v-else-if="loading" class="kdx-modal__state">
          <p>Cargando historial...</p>
        </div>

        <div v-else-if="items.length === 0" class="kdx-modal__state">
          <p>Sin movimientos registrados para este producto.</p>
        </div>

        <template v-else>
          <p class="kdx-modal__count">{{ total }} movimiento(s)</p>
          <table class="kdx-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th class="kdx-table__num">Delta</th>
                <th class="kdx-table__num">Resultante</th>
                <th>Motivo</th>
                <th>Usuario</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in items" :key="m.uuid">
                <td class="kdx-table__date">{{ formatDate(m.movement_at) }}</td>
                <td>{{ typeLabel(m.type) }}</td>
                <td
                  class="kdx-table__num"
                  :class="m.quantity.delta >= 0 ? 'kdx-table__num--pos' : 'kdx-table__num--neg'"
                >
                  {{ m.quantity.delta >= 0 ? '+' : '' }}{{ m.quantity.delta }}
                </td>
                <td class="kdx-table__num">{{ m.quantity.after }}</td>
                <td class="kdx-table__reason">{{ m.reason ?? '—' }}</td>
                <td>{{ m.user?.name ?? '—' }}</td>
              </tr>
            </tbody>
          </table>

          <div v-if="hasMore" class="kdx-modal__more">
            <button type="button" :disabled="loadingMore" @click="loadMore">
              {{ loadingMore ? 'Cargando...' : 'Cargar mas' }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.kdx-modal__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  padding: var(--pos-space-md);
}
.kdx-modal {
  width: 100%;
  max-width: 760px;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-lg);
  box-shadow: var(--pos-shadow-card);
  display: flex;
  flex-direction: column;
  max-height: 92vh;
}
.kdx-modal__header {
  padding: var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.kdx-modal__header h2 {
  margin: 0;
  color: var(--color-heading);
  font-size: 1.2rem;
}
.kdx-modal__subtitle {
  margin: 0.25rem 0 0;
  font-size: 0.9rem;
  color: var(--color-text);
  opacity: 0.8;
}
.kdx-modal__sku {
  font-family: monospace;
  font-size: 0.75rem;
  opacity: 0.6;
  margin-left: 0.4rem;
}
.kdx-modal__close {
  background: transparent;
  border: none;
  color: var(--color-text);
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
  padding: 0 0.25rem;
}
.kdx-modal__body {
  padding: var(--pos-space-lg);
  overflow-y: auto;
}
.kdx-modal__state {
  padding: var(--pos-space-xl);
  text-align: center;
  color: var(--color-text);
  opacity: 0.8;
}
.kdx-modal__error {
  color: var(--pos-danger);
}
.kdx-modal__count {
  margin: 0 0 var(--pos-space-sm);
  font-size: 0.8rem;
  color: var(--color-text);
  opacity: 0.7;
}
.kdx-table {
  width: 100%;
  border-collapse: collapse;
}
.kdx-table th,
.kdx-table td {
  text-align: left;
  padding: 0.55rem 0.6rem;
  border-bottom: 1px solid var(--color-border);
  font-size: 0.85rem;
}
.kdx-table th {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text);
  opacity: 0.7;
}
.kdx-table__num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.kdx-table__num--pos {
  color: #2a8a3e;
}
.kdx-table__num--neg {
  color: var(--pos-danger);
}
.kdx-table__date {
  white-space: nowrap;
  opacity: 0.85;
}
.kdx-table__reason {
  max-width: 200px;
  opacity: 0.85;
}
.kdx-modal__more {
  text-align: center;
  padding: var(--pos-space-md);
}
.kdx-modal__more button {
  padding: 0.6rem 1.5rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-family: inherit;
  cursor: pointer;
}
</style>
