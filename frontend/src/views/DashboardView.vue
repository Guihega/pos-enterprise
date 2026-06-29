<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useSalesSummary } from '@/composables/useSalesSummary'
import { formatPrice } from '@/lib/format'

const { init, date, summary, loading, errorMessage } = useSalesSummary()

const PAYMENT_LABELS: Record<string, string> = {
  cash: 'Efectivo',
  card_debit: 'Tarjeta debito',
  card_credit: 'Tarjeta credito',
  transfer: 'Transferencia',
  check: 'Cheque',
  voucher: 'Vale',
  credit: 'Credito',
  other: 'Otro',
}

function paymentLabel(method: string): string {
  return PAYMENT_LABELS[method] ?? method
}

onMounted(() => {
  void init()
})
</script>

<template>
  <div class="dashboard">
    <RouterLink to="/" class="back-link">&larr; Punto de venta</RouterLink>
    <h1 class="title">Dashboard</h1>

    <div class="filters">
      <label class="field">
        <span class="field-label">FECHA</span>
        <input v-model="date" type="date" class="input" />
      </label>
    </div>

    <p v-if="errorMessage" class="banner banner-error">{{ errorMessage }}</p>

    <div v-if="loading && !summary" class="loading">Cargando resumen...</div>

    <template v-if="summary">
      <section class="cards">
        <div class="card">
          <span class="card-label">Total vendido</span>
          <span class="card-value">{{ formatPrice(summary.totals.gross_amount) }}</span>
        </div>
        <div class="card">
          <span class="card-label">Tickets</span>
          <span class="card-value">{{ summary.totals.sales_count }}</span>
        </div>
        <div class="card">
          <span class="card-label">Ticket promedio</span>
          <span class="card-value">{{ formatPrice(summary.totals.average_ticket) }}</span>
        </div>
        <div class="card">
          <span class="card-label">Impuestos</span>
          <span class="card-value">{{ formatPrice(summary.totals.tax_amount) }}</span>
        </div>
      </section>

      <section class="panel">
        <h2 class="panel-title">Pagos por metodo</h2>
        <p v-if="summary.payments.length === 0" class="empty">Sin pagos registrados este dia.</p>
        <table v-else class="table">
          <thead>
            <tr><th>Metodo</th><th class="num">Operaciones</th><th class="num">Monto</th></tr>
          </thead>
          <tbody>
            <tr v-for="p in summary.payments" :key="p.method">
              <td>{{ paymentLabel(p.method) }}</td>
              <td class="num">{{ p.count }}</td>
              <td class="num">{{ formatPrice(p.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="panel">
        <h2 class="panel-title">Top productos del dia</h2>
        <p v-if="summary.top_products.length === 0" class="empty">Sin productos vendidos este dia.</p>
        <table v-else class="table">
          <thead>
            <tr><th>Producto</th><th>SKU</th><th class="num">Cantidad</th><th class="num">Monto</th></tr>
          </thead>
          <tbody>
            <tr v-for="tp in summary.top_products" :key="tp.sku">
              <td>{{ tp.name }}</td>
              <td class="muted">{{ tp.sku }}</td>
              <td class="num">{{ tp.quantity }}</td>
              <td class="num">{{ formatPrice(tp.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>

<style scoped>
.dashboard {
  max-width: 1000px;
  margin: 0 auto;
  padding: var(--pos-space-lg);
}
.back-link {
  color: var(--pos-accent);
  text-decoration: none;
  font-size: 0.9rem;
}
.title {
  color: var(--color-heading);
  margin: var(--pos-space-sm) 0 var(--pos-space-lg);
}
.filters {
  margin-bottom: var(--pos-space-lg);
}
.field {
  display: inline-flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
}
.field-label {
  font-size: 0.75rem;
  color: var(--color-text);
  letter-spacing: 0.05em;
}
.input {
  padding: var(--pos-space-sm);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-sm);
  background: var(--color-background);
  color: var(--color-text);
}
.banner {
  padding: var(--pos-space-sm) var(--pos-space-md);
  border-radius: var(--pos-radius-sm);
  margin-bottom: var(--pos-space-md);
}
.banner-error {
  border: 1px solid var(--pos-danger);
  color: var(--pos-danger);
}
.loading {
  color: var(--color-text);
  padding: var(--pos-space-lg) 0;
}
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--pos-space-md);
  margin-bottom: var(--pos-space-xl);
}
.card {
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
  padding: var(--pos-space-lg);
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  box-shadow: var(--pos-shadow-card);
}
.card-label {
  font-size: 0.8rem;
  color: var(--color-text);
}
.card-value {
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--color-heading);
}
.panel {
  margin-bottom: var(--pos-space-xl);
}
.panel-title {
  font-size: 1.1rem;
  color: var(--color-heading);
  margin-bottom: var(--pos-space-md);
}
.empty {
  color: var(--color-text);
  padding: var(--pos-space-md) 0;
}
.table {
  width: 100%;
  border-collapse: collapse;
}
.table th,
.table td {
  padding: var(--pos-space-sm) var(--pos-space-md);
  text-align: left;
  border-bottom: 1px solid var(--color-border);
}
.table th {
  font-size: 0.75rem;
  letter-spacing: 0.05em;
  color: var(--color-text);
}
.table .num {
  text-align: right;
}
.table .muted {
  color: var(--color-text);
  font-size: 0.85rem;
}
</style>
