<script setup lang="ts">
import type { Sale, Branch } from '@/lib/api/generated'
import { nextTick, watch } from 'vue'
import { formatPrice } from '@/lib/format'

const props = defineProps<{
  sale: Sale
  branch?: Branch | null
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

function paymentLabel(method: string): string {
  return PAYMENT_LABELS[method] ?? method
}

function formatDateTime(iso: string | undefined): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleString('es-MX')
}

function printTicket(): void {
  window.print()
}

// Imprime automaticamente cada vez que se registra una venta nueva
// (sale.uuid cambia), sin requerir clic en "Ver ticket" ni "Imprimir".
// flush:'post' espera a que el DOM del ticket refleje los datos de la
// venta (incluida la primera, por immediate:true) antes de imprimir.
watch(
  () => props.sale.uuid,
  () => {
    // flush:'post' asegura que Vue ya aplico el parche del DOM, pero
    // no que el navegador ya pinto ese frame. nextTick + rAF esperan
    // un ciclo de pintado real antes de invocar print(); sin esto la
    // vista de impresion puede salir en blanco.
    nextTick(() => {
      requestAnimationFrame(() => {
        window.print()
      })
    })
  },
  { immediate: true, flush: 'post' },
)
</script>

<template>
  <Teleport to="body">
    <div class="ticket-modal__overlay" :class="{ 'is-hidden': !visible }" @click="$emit('close')" />
    <div class="ticket-modal" :class="{ 'is-hidden': !visible }" role="dialog" aria-label="Ticket de venta">
      <div class="ticket" id="ticket-printable">
        <header class="ticket__head">
          <h2 class="ticket__shop">{{ branch?.name ?? 'Punto de venta' }}</h2>
          <p v-if="branch?.code" class="ticket__line muted">Sucursal {{ branch.code }}</p>
          <p class="ticket__line">Folio: <strong>{{ sale.number }}</strong></p>
          <p class="ticket__line muted">{{ formatDateTime(sale.completed_at ?? sale.created_at) }}</p>
          <p v-if="sale.cashier?.name" class="ticket__line muted">Atendio: {{ sale.cashier.name }}</p>
        </header>

        <div class="ticket__divider" />

        <table class="ticket__items">
          <tbody>
            <tr v-for="item in sale.items ?? []" :key="item.uuid" class="ticket__item">
              <td class="ticket__item-qty">{{ item.quantity }}x</td>
              <td class="ticket__item-name">
                {{ item.product_name }}
                <span class="muted">@ {{ formatPrice(item.unit_price) }}</span>
              </td>
              <td class="ticket__item-total">{{ formatPrice(item.line_total) }}</td>
            </tr>
          </tbody>
        </table>

        <div class="ticket__divider" />

        <div class="ticket__totals">
          <div class="ticket__total-row">
            <span>Subtotal</span><span>{{ formatPrice(sale.totals.subtotal) }}</span>
          </div>
          <div v-if="sale.totals.discount > 0" class="ticket__total-row">
            <span>Descuento</span><span>-{{ formatPrice(sale.totals.discount) }}</span>
          </div>
          <div class="ticket__total-row">
            <span>Impuestos</span><span>{{ formatPrice(sale.totals.tax) }}</span>
          </div>
          <div class="ticket__total-row ticket__total-row--grand">
            <span>Total</span><span>{{ formatPrice(sale.totals.total) }}</span>
          </div>
        </div>

        <div class="ticket__divider" />

        <div class="ticket__payments">
          <div v-for="p in sale.payments ?? []" :key="p.uuid" class="ticket__total-row">
            <span>{{ paymentLabel(p.method) }}</span><span>{{ formatPrice(p.amount) }}</span>
          </div>
          <div v-if="sale.totals.change > 0" class="ticket__total-row">
            <span>Cambio</span><span>{{ formatPrice(sale.totals.change) }}</span>
          </div>
        </div>

        <footer class="ticket__foot">
          <p class="muted">Gracias por su compra</p>
        </footer>
      </div>

      <div class="ticket-modal__actions">
        <button type="button" class="btn-secondary" @click="$emit('close')">Cerrar</button>
        <button type="button" class="btn-primary" @click="printTicket">Imprimir</button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.ticket-modal__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 40;
}
.ticket-modal {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 41;
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
  max-height: 90vh;
}
.ticket-modal.is-hidden,
.ticket-modal__overlay.is-hidden {
  display: none;
}
.ticket {
  background: #fff;
  color: #111;
  width: 320px;
  max-height: 70vh;
  overflow-y: auto;
  padding: var(--pos-space-lg);
  border-radius: var(--pos-radius-md);
  font-family: 'Courier New', monospace;
  font-size: 0.85rem;
}
.ticket__head {
  text-align: center;
}
.ticket__shop {
  font-size: 1.1rem;
  margin: 0 0 var(--pos-space-xs);
}
.ticket__line {
  margin: 2px 0;
}
.ticket .muted {
  color: #666;
  font-size: 0.78rem;
}
.ticket__divider {
  border-top: 1px dashed #999;
  margin: var(--pos-space-sm) 0;
}
.ticket__items {
  width: 100%;
  border-collapse: collapse;
}
.ticket__item td {
  vertical-align: top;
  padding: 2px 0;
}
.ticket__item-qty {
  width: 32px;
}
.ticket__item-name {
  padding-right: var(--pos-space-sm);
}
.ticket__item-name .muted {
  display: block;
}
.ticket__item-total {
  text-align: right;
  white-space: nowrap;
}
.ticket__total-row {
  display: flex;
  justify-content: space-between;
  margin: 2px 0;
}
.ticket__total-row--grand {
  font-weight: 700;
  font-size: 1rem;
  margin-top: var(--pos-space-xs);
}
.ticket__foot {
  text-align: center;
  margin-top: var(--pos-space-md);
}
.ticket-modal__actions {
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
  .ticket-modal__overlay,
  .ticket-modal__actions {
    display: none !important;
  }
  .ticket-modal,
  .ticket-modal.is-hidden {
    display: block !important;
    position: static;
    transform: none;
    max-height: none;
    width: 100%;
  }
  .ticket {
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
/* Reglas globales de impresion (NO scoped): el ticket se teleporta a
   <body>, por lo que aqui ocultamos el resto de la app para que SOLO
   se imprima el ticket. Tamano por defecto 80mm (impresora termica
   tipica de POS); para 58mm cambiar 'size' aqui y el max-width de
   .ticket en el bloque scoped de arriba. */
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
