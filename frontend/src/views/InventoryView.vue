<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useInventory } from '@/composables/useInventory'
import { useWarehouses } from '@/composables/useWarehouses'
import AdjustStockModal from '@/components/AdjustStockModal.vue'
import KardexModal from '@/components/KardexModal.vue'
import type { Stock } from '@/lib/api/generated'

const {
  init,
  warehouseUuid,
  lowStockOnly,
  items,
  loading,
  loadingMore,
  errorMessage,
  hasMore,
  total,
  loadMore,
  retry,
} = useInventory()

const warehouses = useWarehouses()

// Modales: null = cerrado.
const adjustingStock = ref<Stock | null>(null)
const kardexStock = ref<Stock | null>(null)

// Banner de feedback tras un ajuste.
const feedback = ref<string | null>(null)

onMounted(async () => {
  await warehouses.init()
  void init()
})

function openAdjust(stock: Stock): void {
  adjustingStock.value = stock
}

function openKardex(stock: Stock): void {
  kardexStock.value = stock
}

function onAdjusted(): void {
  adjustingStock.value = null
  feedback.value = 'Ajuste aplicado correctamente.'
  void retry()
  setTimeout(() => { feedback.value = null }, 4000)
}

function productLabel(stock: Stock): string {
  return stock.product?.name ?? '—'
}

function warehouseLabel(stock: Stock): string {
  return stock.warehouse?.name ?? '—'
}
</script>

<template>
  <div class="inv-view">
    <header class="inv-view__header">
      <div>
        <RouterLink :to="{ name: 'pos' }" class="inv-view__back">&larr; Punto de venta</RouterLink>
        <h1 class="inv-view__title">Inventario</h1>
        <p class="inv-view__subtitle">{{ total }} existencia(s)</p>
      </div>
    </header>

    <div class="inv-view__filters">
      <div class="inv-view__filter">
        <label for="inv-wh">Almacen</label>
        <select id="inv-wh" v-model="warehouseUuid">
          <option value="">Todos</option>
          <option v-for="w in warehouses.items.value" :key="w.uuid" :value="w.uuid">
            {{ w.name }} ({{ w.code }})
          </option>
        </select>
      </div>
      <label class="inv-view__check">
        <input v-model="lowStockOnly" type="checkbox" />
        <span>Solo stock bajo</span>
      </label>
    </div>

    <p v-if="feedback" class="inv-view__feedback">{{ feedback }}</p>

    <div class="inv-view__body">
      <div v-if="errorMessage" class="inv-view__state">
        <p class="inv-view__error">{{ errorMessage }}</p>
        <button type="button" @click="retry">Reintentar</button>
      </div>

      <div v-else-if="loading" class="inv-view__state">
        <p>Cargando inventario...</p>
      </div>

      <div v-else-if="items.length === 0" class="inv-view__state">
        <p>No hay existencias para los filtros seleccionados.</p>
      </div>

      <table v-else class="inv-table">
        <thead>
          <tr>
            <th>Producto</th>
            <th>Almacen</th>
            <th class="inv-table__num">Existencia</th>
            <th class="inv-table__num">Disponible</th>
            <th>Estado</th>
            <th class="inv-table__actions-col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in items" :key="`${s.product?.uuid}-${s.warehouse?.uuid}`">
            <td>{{ productLabel(s) }}</td>
            <td>{{ warehouseLabel(s) }}</td>
            <td class="inv-table__num">{{ s.quantity.on_hand }}</td>
            <td class="inv-table__num">{{ s.quantity.available }}</td>
            <td>
              <span v-if="s.thresholds.is_low" class="inv-table__badge inv-table__badge--low">Stock bajo</span>
              <span v-else-if="s.thresholds.is_overstock" class="inv-table__badge inv-table__badge--over">Sobre stock</span>
              <span v-else class="inv-table__badge inv-table__badge--ok">OK</span>
            </td>
            <td class="inv-table__actions">
              <button type="button" class="inv-table__btn" @click="openKardex(s)">Kardex</button>
              <button type="button" class="inv-table__btn inv-table__btn--accent" @click="openAdjust(s)">Ajustar</button>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="hasMore" class="inv-view__more">
        <button type="button" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? 'Cargando...' : 'Cargar mas' }}
        </button>
      </div>
    </div>

    <AdjustStockModal
      v-if="adjustingStock"
      :stock="adjustingStock"
      @adjusted="onAdjusted"
      @cancel="adjustingStock = null"
    />
    <KardexModal
      v-if="kardexStock"
      :stock="kardexStock"
      @close="kardexStock = null"
    />
  </div>
</template>

<style scoped>
.inv-view {
  max-width: 1000px;
  margin: 0 auto;
  padding: var(--pos-space-lg);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.inv-view__back {
  display: inline-block;
  margin-bottom: 0.4rem;
  font-size: 0.85rem;
  color: var(--pos-accent);
  text-decoration: none;
}
.inv-view__back:hover { text-decoration: underline; }
.inv-view__title {
  margin: 0;
  color: var(--color-heading);
  font-size: 1.5rem;
}
.inv-view__subtitle {
  margin: 0.25rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text);
  opacity: 0.7;
}
.inv-view__filters {
  display: flex;
  gap: var(--pos-space-lg);
  align-items: flex-end;
  flex-wrap: wrap;
}
.inv-view__filter {
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
  min-width: 220px;
}
.inv-view__filter label {
  font-size: 0.7rem;
  color: var(--color-text);
  opacity: 0.75;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.inv-view__filter select {
  padding: 0.6rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 0.95rem;
  font-family: inherit;
}
.inv-view__filter select:focus {
  outline: 2px solid var(--pos-accent);
  outline-offset: -1px;
}
.inv-view__check {
  display: flex;
  align-items: center;
  gap: var(--pos-space-xs);
  font-size: 0.9rem;
  color: var(--color-text);
  cursor: pointer;
  padding-bottom: 0.6rem;
}
.inv-view__feedback {
  margin: 0;
  padding: var(--pos-space-sm) var(--pos-space-md);
  border-radius: var(--pos-radius-md);
  background: rgba(42, 138, 62, 0.12);
  color: #2a8a3e;
  font-size: 0.9rem;
}
.inv-view__state {
  padding: var(--pos-space-xl);
  text-align: center;
  color: var(--color-text);
  opacity: 0.8;
}
.inv-view__error {
  color: var(--pos-danger);
}
.inv-table {
  width: 100%;
  border-collapse: collapse;
}
.inv-table th,
.inv-table td {
  text-align: left;
  padding: 0.7rem 0.75rem;
  border-bottom: 1px solid var(--color-border);
  font-size: 0.9rem;
}
.inv-table th {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text);
  opacity: 0.7;
}
.inv-table__num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.inv-table__badge {
  font-size: 0.75rem;
  padding: 0.15rem 0.5rem;
  border-radius: var(--pos-radius-sm);
}
.inv-table__badge--ok { background: rgba(42, 138, 62, 0.15); color: #2a8a3e; }
.inv-table__badge--low { background: rgba(184, 38, 38, 0.15); color: var(--pos-danger); }
.inv-table__badge--over { background: rgba(184, 134, 11, 0.15); color: #b8860b; }
.inv-table__actions-col {
  text-align: right;
}
.inv-table__actions {
  text-align: right;
  white-space: nowrap;
}
.inv-table__btn {
  padding: 0.35rem 0.75rem;
  margin-left: 0.4rem;
  border-radius: var(--pos-radius-sm);
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text);
  font-size: 0.8rem;
  font-family: inherit;
  cursor: pointer;
}
.inv-table__btn:hover:not(:disabled) {
  border-color: var(--color-border-hover);
}
.inv-table__btn--accent {
  color: var(--pos-accent);
  border-color: var(--pos-accent);
}
.inv-view__more {
  text-align: center;
  padding: var(--pos-space-md);
}
.inv-view__more button {
  padding: 0.6rem 1.5rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-family: inherit;
  cursor: pointer;
}
</style>
