<script setup lang="ts">
import { onMounted, ref } from 'vue'
import PosHeader from '@/components/PosHeader.vue'
import PosCatalog from '@/components/PosCatalog.vue'
import PosCart from '@/components/PosCart.vue'
import PosCheckoutBar from '@/components/PosCheckoutBar.vue'
import CashOpenModal from '@/components/CashOpenModal.vue'
import CashCloseModal from '@/components/CashCloseModal.vue'
import PaymentModal from '@/components/PaymentModal.vue'
import PinSupervisorModal from '@/components/PinSupervisorModal.vue'
import { useCartStore } from '@/stores/cart'
import { useCashSessionStore } from '@/stores/cashSession'
import { useSalesStore } from '@/stores/sales'
import type { CashSession, CreateSalePayment, Product, Sale } from '@/lib/api/generated'

const cartStore = useCartStore()
const cashStore = useCashSessionStore()
const salesStore = useSalesStore()

const showPaymentModal = ref(false)
const showCloseModal = ref(false)
const showCartDrawer = ref(false)
// Sesion recien cerrada: alimenta el banner de arqueo. null = sin banner.
const closedSession = ref<CashSession | null>(null)
// Flujo de anulacion de venta.
const showCancelPin = ref(false)
const showCancelReason = ref(false)
const cancelReason = ref('')
const cancelError = ref<string | null>(null)
// Venta objetivo de la anulacion (capturada del banner de exito).
const cancelTarget = ref<Sale | null>(null)
// Venta ya anulada: alimenta el banner. null = sin banner.
const cancelledSale = ref<Sale | null>(null)

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

function onCloseCashRequested(): void {
  if (!cashStore.currentSession) return
  cashStore.errorMessage = null
  showCloseModal.value = true
}

async function onConfirmClose(
  countedAmount: number,
  closingNotes: string | null,
): Promise<void> {
  const sessionUuid = cashStore.currentSession?.uuid
  if (!sessionUuid) {
    showCloseModal.value = false
    return
  }

  const result = await cashStore.close(sessionUuid, countedAmount, closingNotes)
  if (result.ok && result.session) {
    // Caja cerrada: mostrar arqueo y cerrar el modal. currentSession
    // ya quedo en null dentro del store, asi que el modal de apertura
    // reaparece por hasActiveSession=false.
    closedSession.value = result.session
    showCloseModal.value = false
  } else if (result.sessionLost) {
    // La sesion ya no estaba abierta: cerrar modal, el de apertura
    // aparece solo tras loadCurrent.
    showCloseModal.value = false
  }
  // Si !ok && !sessionLost: modal sigue abierto con errorMessage.
}

function onCancelClose(): void {
  if (cashStore.loading) return
  showCloseModal.value = false
  cashStore.errorMessage = null
}

function dismissCloseBanner(): void {
  closedSession.value = null
}

function dismissSuccessBanner(): void {
  salesStore.clearLastSale()
}

function onCancelRequested(): void {
  if (!salesStore.lastSale) return
  cancelTarget.value = salesStore.lastSale
  cancelReason.value = ''
  cancelError.value = null
  showCancelPin.value = true
}

function onCancelPinConfirmed(): void {
  showCancelPin.value = false
  showCancelReason.value = true
}

function onCancelPinCancelled(): void {
  showCancelPin.value = false
  cancelTarget.value = null
}

function onCancelReasonCancel(): void {
  if (salesStore.cancelling) return
  showCancelReason.value = false
  cancelTarget.value = null
  cancelError.value = null
}

async function onConfirmCancel(): Promise<void> {
  if (!cancelTarget.value) return
  if (cancelReason.value.trim().length < 3) return
  cancelError.value = null
  const result = await salesStore.cancel(cancelTarget.value.uuid, cancelReason.value.trim())
  if (result.ok) {
    showCancelReason.value = false
    cancelTarget.value = null
    cancelledSale.value = result.sale ?? null
    salesStore.clearLastSale()
    const anulada = cancelledSale.value
    setTimeout(() => {
      if (cancelledSale.value?.uuid === anulada?.uuid) {
        cancelledSale.value = null
      }
    }, 6000)
  } else {
    cancelError.value = result.errorMessage ?? 'No se pudo anular la venta.'
  }
}

function dismissCancelBanner(): void {
  cancelledSale.value = null
}
</script>

<template>
  <div class="pos-shell">
    <PosHeader @close-cash="onCloseCashRequested" />
    <main class="pos-main">
      <PosCatalog @product-selected="onProductSelected" />
      <PosCart :drawer="showCartDrawer" />
      <Transition name="pos-overlay">
        <div v-if="showCartDrawer" class="pos-cart-overlay" @click="showCartDrawer = false" />
      </Transition>
    </main>
    <PosCheckoutBar @checkout="onCheckoutRequested" @open-cart="showCartDrawer = true" />

    <!-- Modal bloqueante de apertura de caja. -->
    <CashOpenModal v-if="!cashStore.hasActiveSession && !cashStore.loading" />

    <!-- Modal de cierre de caja (arqueo). -->
    <CashCloseModal
      :open="showCloseModal"
      :loading="cashStore.loading"
      :error-message="cashStore.errorMessage"
      @confirm="onConfirmClose"
      @close="onCancelClose"
    />

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
        class="pos-success-banner__cancel"
        @click="onCancelRequested"
      >
        Anular
      </button>
      <button
        type="button"
        class="pos-success-banner__close"
        aria-label="Cerrar notificacion"
        @click="dismissSuccessBanner"
      >
        ×
      </button>
    </div>

    <!-- PIN supervisor para autorizar la anulacion. -->
    <PinSupervisorModal
      :open="showCancelPin"
      @confirmed="onCancelPinConfirmed"
      @cancelled="onCancelPinCancelled"
    />

    <!-- Modal de motivo de anulacion. -->
    <Teleport to="body">
      <div v-if="showCancelReason" class="pos-cancel-modal__backdrop" @click.self="onCancelReasonCancel">
        <div class="pos-cancel-modal" role="dialog" aria-modal="true" aria-labelledby="pos-cancel-title">
          <h2 id="pos-cancel-title" class="pos-cancel-modal__title">Anular venta</h2>
          <p v-if="cancelTarget" class="pos-cancel-modal__desc">
            Venta {{ cancelTarget.number }}. Indica el motivo de la anulacion.
          </p>
          <textarea
            v-model="cancelReason"
            class="pos-cancel-modal__input"
            rows="3"
            maxlength="500"
            placeholder="Motivo (minimo 3 caracteres)"
            :disabled="salesStore.cancelling"
          ></textarea>
          <p v-if="cancelError" class="pos-cancel-modal__error">{{ cancelError }}</p>
          <div class="pos-cancel-modal__actions">
            <button
              type="button"
              class="pos-cancel-modal__btn pos-cancel-modal__btn--cancel"
              :disabled="salesStore.cancelling"
              @click="onCancelReasonCancel"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="pos-cancel-modal__btn pos-cancel-modal__btn--confirm"
              :disabled="salesStore.cancelling || cancelReason.trim().length < 3"
              @click="onConfirmCancel"
            >
              {{ salesStore.cancelling ? 'Anulando...' : 'Confirmar anulacion' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Banner de anulacion exitosa. -->
    <div v-if="cancelledSale" class="pos-cancel-banner" role="status">
      <div class="pos-cancel-banner__content">
        <strong>Venta {{ cancelledSale.number }} anulada</strong>
        <span class="pos-cancel-banner__row">Stock y pagos revertidos.</span>
      </div>
      <button
        type="button"
        class="pos-cancel-banner__close"
        aria-label="Cerrar notificacion"
        @click="dismissCancelBanner"
      >
        x
      </button>
    </div>

    <!-- Banner de arqueo tras cerrar caja. -->
    <div
      v-if="closedSession && closedSession.closing"
      class="pos-arqueo-banner"
      role="status"
    >
      <div class="pos-arqueo-banner__content">
        <strong>Caja cerrada</strong>
        <span class="pos-arqueo-banner__row">
          Esperado
          {{ (closedSession.closing.expected_amount ?? 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
          · Contado
          {{ (closedSession.closing.counted_amount ?? 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
        </span>
        <span
          class="pos-arqueo-banner__diff"
          :class="{
            'pos-arqueo-banner__diff--ok': (closedSession.closing.difference ?? 0) === 0,
            'pos-arqueo-banner__diff--short': (closedSession.closing.difference ?? 0) < 0,
            'pos-arqueo-banner__diff--over': (closedSession.closing.difference ?? 0) > 0,
          }"
        >
          Diferencia
          {{ (closedSession.closing.difference ?? 0).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) }}
        </span>
      </div>
      <button
        type="button"
        class="pos-arqueo-banner__close"
        aria-label="Cerrar notificacion"
        @click="dismissCloseBanner"
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

.pos-arqueo-banner {
  position: fixed;
  top: 5rem;
  right: 1.5rem;
  z-index: 150;
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding: 0.85rem 1rem 0.85rem 1.25rem;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-left-width: 4px;
  border-left-color: var(--pos-accent);
  border-radius: var(--pos-radius-md, 8px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  color: var(--color-text);
  max-width: 420px;
  animation: pos-success-slide-in 0.3s ease-out;
}

.pos-arqueo-banner__content {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  min-width: 0;
}

.pos-arqueo-banner__content strong {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--color-heading);
}

.pos-arqueo-banner__row {
  font-size: 0.8rem;
  opacity: 0.8;
}

.pos-arqueo-banner__diff {
  font-size: 0.85rem;
  font-weight: 600;
}

.pos-arqueo-banner__diff--ok {
  color: #2a8a3e;
}

.pos-arqueo-banner__diff--short {
  color: var(--pos-danger);
}

.pos-arqueo-banner__diff--over {
  color: #b8860b;
}

.pos-arqueo-banner__close {
  background: transparent;
  border: none;
  font-size: 1.4rem;
  line-height: 1;
  cursor: pointer;
  color: var(--color-text);
  opacity: 0.6;
  padding: 0 0.25rem;
}

.pos-arqueo-banner__close:hover {
  opacity: 1;
}

.pos-cart-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  z-index: 99;
}

.pos-overlay-enter-active,
.pos-overlay-leave-active {
  transition: opacity 0.2s ease;
}

.pos-overlay-enter-from,
.pos-overlay-leave-to {
  opacity: 0;
}

@media (max-width: 767px) {
  .pos-main {
    grid-template-columns: 1fr;
  }
}
.pos-success-banner__cancel {
  background: transparent;
  border: 1px solid #2a8a3e;
  color: #2a8a3e;
  border-radius: var(--pos-radius-md, 8px);
  font-size: 0.8rem;
  font-family: inherit;
  padding: 0.25rem 0.6rem;
  cursor: pointer;
  align-self: center;
}
.pos-success-banner__cancel:hover {
  background: #2a8a3e;
  color: #fff;
}
.pos-cancel-modal__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 200;
}
.pos-cancel-modal {
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-lg, 12px);
  padding: var(--pos-space-xl, 1.5rem);
  width: min(420px, 92vw);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md, 0.75rem);
}
.pos-cancel-modal__title { margin: 0; font-size: 1.1rem; color: var(--color-heading); }
.pos-cancel-modal__desc { margin: 0; font-size: 0.875rem; color: var(--color-text); opacity: 0.7; }
.pos-cancel-modal__input {
  width: 100%;
  padding: 0.65rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md, 8px);
  background: transparent;
  color: var(--color-text);
  font-size: 0.95rem;
  font-family: inherit;
  resize: vertical;
  box-sizing: border-box;
}
.pos-cancel-modal__input:focus { outline: 2px solid var(--pos-accent); outline-offset: -1px; }
.pos-cancel-modal__error { margin: 0; font-size: 0.875rem; color: var(--pos-danger); }
.pos-cancel-modal__actions { display: flex; gap: var(--pos-space-md, 0.75rem); justify-content: flex-end; }
.pos-cancel-modal__btn {
  padding: 0.5rem 1.1rem;
  border-radius: var(--pos-radius-md, 8px);
  font-size: 0.875rem;
  font-family: inherit;
  cursor: pointer;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text);
}
.pos-cancel-modal__btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pos-cancel-modal__btn--confirm { background: var(--pos-danger); color: #fff; border-color: var(--pos-danger); }
.pos-cancel-modal__btn--confirm:hover:not(:disabled) { opacity: 0.9; }
.pos-cancel-banner {
  position: fixed;
  top: 5rem;
  right: 1.5rem;
  z-index: 150;
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding: 0.85rem 1rem 0.85rem 1.25rem;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-left-width: 4px;
  border-left-color: var(--pos-danger);
  border-radius: var(--pos-radius-md, 8px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  color: var(--color-text);
  max-width: 420px;
}
.pos-cancel-banner__content { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; }
.pos-cancel-banner__content strong { font-size: 0.95rem; font-weight: 700; color: var(--color-heading); }
.pos-cancel-banner__row { font-size: 0.8rem; opacity: 0.8; }
.pos-cancel-banner__close {
  background: transparent;
  border: none;
  font-size: 1.4rem;
  line-height: 1;
  cursor: pointer;
  color: var(--color-text);
  opacity: 0.6;
  padding: 0 0.25rem;
}
.pos-cancel-banner__close:hover { opacity: 1; }
</style>