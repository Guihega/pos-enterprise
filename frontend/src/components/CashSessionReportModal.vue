<script setup lang="ts">
import type { CashSessionReport } from '@/lib/api/generated'
import { formatPrice } from '@/lib/format'

defineProps<{
  report: CashSessionReport
  visible?: boolean
}>()

defineEmits<{
  (e: 'close'): void
}>()

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

const MOVEMENT_LABELS: Record<string, string> = {
  sale_cash: 'Ventas en efectivo',
  sale_other: 'Ventas otros medios',
  refund_cash: 'Devoluciones en efectivo',
  cash_in: 'Entradas de efectivo',
  cash_out: 'Salidas de efectivo',
  tip: 'Propinas',
  adjustment: 'Ajustes',
}

function paymentLabel(method: string): string {
  return PAYMENT_LABELS[method] ?? method
}

function movementLabel(type: string): string {
  return MOVEMENT_LABELS[type] ?? type
}

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleString('es-MX')
}

function printReport(): void {
  window.print()
}
</script>

<template>
  <Teleport to="body">
    <div class="report-modal__overlay" :class="{ 'is-hidden': !visible }" @click="$emit('close')" />
    <div class="report-modal" :class="{ 'is-hidden': !visible }" role="dialog" aria-label="Corte de caja">
      <div class="report" id="report-printable">
        <header class="report__head">
          <h2 class="report__shop">{{ report.session.register?.name ?? 'Caja' }}</h2>
          <p v-if="report.session.register?.code" class="report__line muted">
            {{ report.session.register.code }}
          </p>
          <p class="report__title">
            {{ report.session.status === 'open' ? 'CORTE X' : 'CORTE Z' }}
          </p>
          <p class="report__line muted">Apertura: {{ formatDateTime(report.session.opened_at) }}</p>
          <p v-if="report.session.opening.by?.name" class="report__line muted">
            Abrio: {{ report.session.opening.by.name }}
          </p>
          <p v-if="report.session.status !== 'open'" class="report__line muted">
            Cierre: {{ formatDateTime(report.session.closed_at) }}
          </p>
          <p v-if="report.session.closing?.by?.name" class="report__line muted">
            Cerro: {{ report.session.closing.by.name }}
          </p>
        </header>

        <div class="report__divider" />

        <div class="report__section">
          <p class="report__section-title">Ventas del turno</p>
          <div class="report__row">
            <span>Ventas</span><span>{{ report.sales.count }}</span>
          </div>
          <div class="report__row">
            <span>Total</span><span>{{ formatPrice(report.sales.total_amount) }}</span>
          </div>
        </div>

        <div class="report__divider" />

        <div class="report__section">
          <p class="report__section-title">Pagos por metodo</p>
          <p v-if="report.payments.length === 0" class="muted">Sin pagos registrados.</p>
          <div v-for="p in report.payments" :key="p.method" class="report__row">
            <span>{{ paymentLabel(p.method) }} ({{ p.count }})</span>
            <span>{{ formatPrice(p.amount) }}</span>
          </div>
        </div>

        <div class="report__divider" />

        <div class="report__section">
          <p class="report__section-title">Movimientos de caja</p>
          <p v-if="report.movements.length === 0" class="muted">Sin movimientos registrados.</p>
          <div v-for="m in report.movements" :key="m.type" class="report__row">
            <span>{{ movementLabel(m.type) }} ({{ m.count }})</span>
            <span>{{ formatPrice(m.delta_signed) }}</span>
          </div>
        </div>

        <div class="report__divider" />

        <div class="report__section">
          <p class="report__section-title">Efectivo</p>
          <div class="report__row">
            <span>Apertura</span><span>{{ formatPrice(report.cash.opening_amount) }}</span>
          </div>
          <div class="report__row">
            <span>Movimientos</span><span>{{ formatPrice(report.cash.cash_affecting_delta) }}</span>
          </div>
          <div class="report__row report__row--grand">
            <span>Esperado</span><span>{{ formatPrice(report.cash.expected_amount) }}</span>
          </div>
          <template v-if="report.cash.counted_amount !== null">
            <div class="report__row">
              <span>Contado</span><span>{{ formatPrice(report.cash.counted_amount) }}</span>
            </div>
            <div class="report__row report__row--grand">
              <span>Diferencia</span><span>{{ formatPrice(report.cash.difference ?? 0) }}</span>
            </div>
          </template>
        </div>

        <footer class="report__foot">
          <p class="muted">{{ formatDateTime(new Date().toISOString()) }}</p>
        </footer>
      </div>

      <div class="report-modal__actions">
        <button type="button" class="btn-secondary" @click="$emit('close')">Cerrar</button>
        <button type="button" class="btn-primary" @click="printReport">Imprimir</button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.report-modal__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  /* Mayor que CashOpenModal (z-index 100): "Ver corte Z" se muestra
     justo despues de cerrar caja, cuando CashOpenModal ya reaparecio
     por hasActiveSession=false y quedaria encima si no se ajusta. */
  z-index: 110;
}
.report-modal {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 111;
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
  max-height: 90vh;
}
.report-modal.is-hidden,
.report-modal__overlay.is-hidden {
  display: none;
}
.report {
  background: #fff;
  color: #111;
  width: 340px;
  max-height: 70vh;
  overflow-y: auto;
  padding: var(--pos-space-lg);
  border-radius: var(--pos-radius-md);
  font-family: 'Courier New', monospace;
  font-size: 0.85rem;
}
.report__head {
  text-align: center;
}
.report__shop {
  font-size: 1.1rem;
  margin: 0 0 var(--pos-space-xs);
}
.report__title {
  font-weight: 700;
  font-size: 1rem;
  letter-spacing: 0.1em;
  margin: var(--pos-space-xs) 0;
}
.report__line {
  margin: 2px 0;
}
.report .muted {
  color: #666;
  font-size: 0.78rem;
}
.report__divider {
  border-top: 1px dashed #999;
  margin: var(--pos-space-sm) 0;
}
.report__section-title {
  font-weight: 700;
  margin: 0 0 var(--pos-space-xs);
}
.report__row {
  display: flex;
  justify-content: space-between;
  margin: 2px 0;
}
.report__row--grand {
  font-weight: 700;
  font-size: 1rem;
  margin-top: var(--pos-space-xs);
}
.report__foot {
  text-align: center;
  margin-top: var(--pos-space-md);
}
.report-modal__actions {
  display: flex;
  gap: var(--pos-space-sm);
  justify-content: flex-end;
}
.btn-secondary,
.btn-primary {
  padding: var(--pos-space-sm) var(--pos-space-lg);
  border-radius: var(--pos-radius-sm);
  border: 1px solid var(--color-border);
  cursor: pointer;
}
.btn-secondary {
  background: var(--color-background-mute);
  color: var(--color-text);
}
.btn-primary {
  background: var(--pos-accent);
  color: var(--pos-accent-text);
  border-color: var(--pos-accent);
}

@media print {
  .report-modal__overlay,
  .report-modal__actions {
    display: none !important;
  }
  .report-modal,
  .report-modal.is-hidden {
    display: block !important;
    position: static;
    transform: none;
    max-height: none;
    width: 100%;
  }
  .report {
    width: 100%;
    max-width: 80mm;
    max-height: none;
    overflow: visible;
    box-shadow: none;
    border-radius: 0;
    padding: 2mm;
    margin: 0;
    background: #fff !important;
    color: #000 !important;
    font-size: 11px;
  }
}
</style>

<style>
@page {
  size: 80mm auto;
  margin: 0;
}

@media print {
  #app {
    display: none !important;
  }
}
</style>
