<script setup lang="ts">
import { onMounted, ref } from 'vue'
import PosHeader from '@/components/PosHeader.vue'
import PosCatalog from '@/components/PosCatalog.vue'
import PosCart from '@/components/PosCart.vue'
import PosCheckoutBar from '@/components/PosCheckoutBar.vue'
import CashOpenModal from '@/components/CashOpenModal.vue'
import PaymentModal from '@/components/PaymentModal.vue'
import { useCartStore } from '@/stores/cart'
import { useCashSessionStore } from '@/stores/cashSession'
import { useSalesStore } from '@/stores/sales'
import type { CreateSalePayment, Product } from '@/lib/api/generated'

const cartStore = useCartStore()
const cashStore = useCashSessionStore()
const salesStore = useSalesStore()

const showPaymentModal = ref(false)

onMounted(async () => {
  // Consultar si hay una sesion de caja abierta. Si no, el modal
  // bloqueante se muestra para que el cajero la abra antes de vender.
  await cashStore.loadCurrent()
})

function onProductSelected(product: Product): void {
  cartStore.add(product)
}

function onCheckoutRequested(): void {
  if (cartStore.isEmpty) return
  salesStore.clearError()
  showPaymentModal.value = true
}

async function onConfirmSale(payments: CreateSalePayment[]): Promise<void> {
  const result = await salesStore.checkout(payments)
  if (result.ok) {
    // Exito: vaciar carrito, cerrar modal, banner se muestra por lastSale.
    cartStore.clear()
    showPaymentModal.value = false
    // Auto-dismiss del banner a los 6 segundos.
    setTimeout(() => {
      // Solo limpiar si nadie mas lo cambio.
      if (salesStore.lastSale?.uuid === result.sale?.uuid) {
        salesStore.clearLastSale()
      }
    }, 6000)
  } else if (result.sessionLost) {
    // La caja se cerro: cerrar modal de pago, el modal de apertura
    // aparece solo por hasActiveSession=false despues de loadCurrent.
    showPaymentModal.value = false
  }
  // Si !result.ok && !sessionLost: el modal sigue abierto con
  // salesStore.errorMessage visible para que el cajero ajuste.
}

function onClosePaymentModal(): void {
  if (salesStore.submitting) return
  showPaymentModal.value = false
  salesStore.clearError()
}

function dismissSuccessBanner(): void {
  salesStore.clearLastSale()
}
</script>

<template>
  <div class="pos-shell">
    <PosHeader />
    <main class="pos-main">
      <PosCatalog @product-selected="onProductSelected" />
      <PosCart />
    </main>
    <PosCheckoutBar @checkout="onCheckoutRequested" />

    <!-- Modal bloqueante de apertura de caja. -->
    <CashOpenModal v-if="!cashStore.hasActiveSession && !cashStore.loading" />

    <!-- Modal de cobro multi-metodo. -->
    <PaymentModal
      :open="showPaymentModal"
      :total="cartStore.grandTotal"
      :error-message="salesStore.errorMessage"
      :submitting="salesStore.submitting"
      @confirm="onConfirmSale"
      @close="onClosePaymentModal"
    />

    <!-- Banner de venta exitosa. -->
    <div v-if="salesStore.lastSale" class="pos-success-banner" role="status">
      <div class="pos-success-banner__content">
        <strong>Venta {{ salesStore.lastSale.number }} registrada</strong>
        <span class="pos-success-banner__total">
          Total {{ salesStore.lastSale.totals.total.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
          <template v-if="salesStore.lastSale.totals.change > 0">
            · Cambio {{ salesStore.lastSale.totals.change.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
          </template>
        </span>
      </div>
      <button
        type="button"
        class="pos-success-banner__close"
        aria-label="Cerrar notificacion"
        @click="dismissSuccessBanner"
      >
        ×
      </button>
    </div>
  </div>
</template>

<style scoped>
.pos-shell {
  display: grid;
  grid-template-rows: auto 1fr auto;
  /* Altura fija de viewport + overflow hidden: el shell ocupa exacto
     el alto del viewport y NO scrollea. Quien scrollea es el contenido
     interno (catalogo y carrito por separado). Asi el header y el
     PosCheckoutBar siempre quedan visibles. */
  height: 100vh;
  overflow: hidden;
}

.pos-main {
  display: grid;
  grid-template-columns: 1fr 380px;
  /* min-height: 0 en grid items es necesario para que los hijos puedan
     hacer overflow internamente sin empujar al padre. */
  min-height: 0;
  overflow: hidden;
}

.pos-success-banner {
  position: fixed;
  top: 5rem;
  right: 1.5rem;
  z-index: 150;
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.85rem 1rem 0.85rem 1.25rem;
  background: #e8f5ec;
  border: 1px solid #2a8a3e;
  border-left-width: 4px;
  border-radius: var(--pos-radius-md, 8px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  color: #1a5c2a;
  max-width: 420px;
  animation: pos-success-slide-in 0.3s ease-out;
}

@keyframes pos-success-slide-in {
  from {
    opacity: 0;
    transform: translateX(20px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

.pos-success-banner__content {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}

.pos-success-banner__content strong {
  font-size: 0.95rem;
  font-weight: 700;
}

.pos-success-banner__total {
  font-size: 0.8rem;
  color: #2a5c3a;
}

.pos-success-banner__close {
  background: transparent;
  border: none;
  font-size: 1.4rem;
  line-height: 1;
  cursor: pointer;
  color: #2a8a3e;
  padding: 0 0.25rem;
}

.pos-success-banner__close:hover {
  color: #1a5c2a;
}
</style>
