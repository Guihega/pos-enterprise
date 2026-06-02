<script setup lang="ts">
/**
 * Modal de cobro: recolecta uno o varios pagos y los emite al
 * confirmar. NO toca stores ni el backend: es un componente
 * controlado por sus props (`total`, `open`) que emite los pagos
 * cuadrados al parent. El parent decide si llamar a createSale.
 *
 * Reglas de cuadre (sin depender del backend):
 *  - sum(payments[].amount) debe igualar `total` para habilitar
 *    "Confirmar".
 *  - Sobrepago (sum > total) NO se permite en la lista de pagos:
 *    el cambio se calcula en el campo "Recibido" del pago en
 *    efectivo y se envia como `tendered_amount`; `amount` sigue
 *    siendo lo aplicado a la venta.
 *  - El backend validara de nuevo y rechazara con PAYMENT_MISMATCH
 *    si algo no cuadra. Aqui solo evitamos el caso obvio.
 *
 * Metodos visibles en MVP: cash, card_credit, card_debit, transfer.
 * check/voucher/credit/other se documentan en el SDK pero no se
 * exponen aun (credit necesita selector de cliente).
 */
import { computed, ref, watch } from 'vue'
import { formatPrice } from '@/lib/format'
import type { CreateSalePayment } from '@/lib/api/generated'

type PaymentMethod = 'cash' | 'card_credit' | 'card_debit' | 'transfer'

interface MethodOption {
  value: PaymentMethod
  label: string
  short: string
}

const METHODS: MethodOption[] = [
  { value: 'cash', label: 'Efectivo', short: 'Efectivo' },
  { value: 'card_credit', label: 'Tarjeta credito', short: 'T. Credito' },
  { value: 'card_debit', label: 'Tarjeta debito', short: 'T. Debito' },
  { value: 'transfer', label: 'Transferencia', short: 'Transfer.' },
]

const CASH_DENOMINATIONS = [50, 100, 200, 500, 1000]

const props = defineProps<{
  total: number
  open: boolean
  /** Mensaje de error externo (ej. backend rechazo la venta). */
  errorMessage?: string | null
  /** Indica que el parent esta procesando la venta (POST en vuelo). */
  submitting?: boolean
}>()

const emit = defineEmits<{
  (e: 'confirm', payments: CreateSalePayment[]): void
  (e: 'close'): void
}>()

function round2(n: number): number {
  return Math.round((n + Number.EPSILON) * 100) / 100
}


// ---------------------------------------------------------------
// State: pagos ya confirmados + formulario activo
// ---------------------------------------------------------------
const payments = ref<CreateSalePayment[]>([])
const activeMethod = ref<PaymentMethod>('cash')

// Campos del formulario activo (se resetean al cambiar de metodo
// o al anadir un pago).
const inputAmount = ref<string>('')         // "A pagar" con este metodo
const inputTendered = ref<string>('')       // "Recibido" (solo cash)
const inputReference = ref<string>('')      // transfer/check
const inputAuthCode = ref<string>('')       // tarjetas
const inputCardBrand = ref<string>('')      // tarjetas
const inputCardLast4 = ref<string>('')      // tarjetas

const formError = ref<string | null>(null)

// ---------------------------------------------------------------
// Computados
// ---------------------------------------------------------------
const paidSoFar = computed(() =>
  round2(payments.value.reduce((acc, p) => acc + p.amount, 0)),
)

const remaining = computed(() => round2(props.total - paidSoFar.value))

const isFullyPaid = computed(() => remaining.value <= 0.001)

/** Parse defensivo: string vacio -> 0, comas -> puntos. */
function parseAmount(raw: string | number | null | undefined): number {
  if (raw === null || raw === undefined) return 0
  // Si v-model devuelve number (type="number"), conviertelo. Si es string,
  // normaliza coma decimal a punto.
  if (typeof raw === 'number') {
    return Number.isFinite(raw) ? raw : NaN
  }
  if (!raw.trim()) return 0
  const normalized = raw.replace(',', '.')
  const n = Number(normalized)
  return Number.isFinite(n) ? n : NaN
}

const currentAmount = computed(() => parseAmount(inputAmount.value))
const currentTendered = computed(() => parseAmount(inputTendered.value))

const changeForCash = computed(() => {
  if (activeMethod.value !== 'cash') return 0
  const tendered = currentTendered.value
  const amount = currentAmount.value
  if (!Number.isFinite(tendered) || !Number.isFinite(amount)) return 0
  if (tendered <= amount) return 0
  return round2(tendered - amount)
})

// ---------------------------------------------------------------
// Lifecycle: al abrir el modal, reset y prefill
// ---------------------------------------------------------------
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      resetAll()
    }
  },
  { immediate: true },
)

function resetAll(): void {
  payments.value = []
  activeMethod.value = 'cash'
  formError.value = null
  prefillForMethod()
}

function prefillForMethod(): void {
  // "A pagar" arranca con el restante. Si ya esta cuadrado, queda 0.
  inputAmount.value = remaining.value > 0 ? remaining.value.toString() : '0'
  inputTendered.value = activeMethod.value === 'cash' ? inputAmount.value : ''
  inputReference.value = ''
  inputAuthCode.value = ''
  inputCardBrand.value = ''
  inputCardLast4.value = ''
}

function selectMethod(m: PaymentMethod): void {
  activeMethod.value = m
  formError.value = null
  prefillForMethod()
}

// ---------------------------------------------------------------
// Botones rapidos de denominaciones (efectivo)
// ---------------------------------------------------------------
function addDenomination(value: number): void {
  const current = parseAmount(inputTendered.value)
  const next = Number.isFinite(current) ? current + value : value
  inputTendered.value = round2(next).toString()
}

function setExact(): void {
  inputTendered.value = inputAmount.value
}

function clearTendered(): void {
  inputTendered.value = ''
}

// ---------------------------------------------------------------
// Anadir el pago en construccion a la lista
// ---------------------------------------------------------------
function addPayment(): void {
  formError.value = null
  const amount = currentAmount.value

  if (!Number.isFinite(amount) || amount <= 0) {
    formError.value = 'El monto debe ser mayor a cero.'
    return
  }

  const rounded = round2(amount)
  const afterAdd = round2(paidSoFar.value + rounded)
  if (afterAdd > round2(props.total) + 0.001) {
    formError.value =
      'El monto excede el total. Reduce el importe de este pago.'
    return
  }

  const payment: CreateSalePayment = {
    method: activeMethod.value,
    amount: rounded,
  }

  if (activeMethod.value === 'cash') {
    const tendered = currentTendered.value
    if (Number.isFinite(tendered) && tendered > 0) {
      if (tendered < rounded) {
        formError.value =
          'Lo recibido no puede ser menor al monto a pagar.'
        return
      }
      if (tendered > rounded) {
        payment.tendered_amount = round2(tendered)
      }
    }
  }

  if (
    activeMethod.value === 'card_credit' ||
    activeMethod.value === 'card_debit'
  ) {
    if (inputAuthCode.value.trim()) {
      payment.authorization_code = inputAuthCode.value.trim()
    }
    if (inputCardBrand.value.trim()) {
      payment.card_brand = inputCardBrand.value.trim()
    }
    if (inputCardLast4.value.trim()) {
      if (!/^\d{4}$/.test(inputCardLast4.value.trim())) {
        formError.value =
          'Los ultimos 4 digitos deben ser exactamente 4 numeros.'
        return
      }
      payment.card_last4 = inputCardLast4.value.trim()
    }
  }

  if (activeMethod.value === 'transfer') {
    if (inputReference.value.trim()) {
      payment.reference = inputReference.value.trim()
    }
  }

  payments.value.push(payment)
  prefillForMethod()
}

function removePayment(index: number): void {
  payments.value.splice(index, 1)
  prefillForMethod()
}

// ---------------------------------------------------------------
// Confirmar venta
// ---------------------------------------------------------------
function onConfirm(): void {
  if (!isFullyPaid.value) {
    formError.value = 'Falta cubrir el total antes de confirmar.'
    return
  }
  if (props.submitting) return
  emit('confirm', payments.value)
}

function onClose(): void {
  if (props.submitting) return
  emit('close')
}

// El parent puede mandar errorMessage tras un fallo del backend.
const visibleError = computed(
  () => formError.value || props.errorMessage || null,
)

function methodLabel(value: PaymentMethod): string {
  return METHODS.find((m) => m.value === value)?.short ?? value
}
</script>

<template>
  <div v-if="open" class="payment-modal" role="dialog" aria-modal="true">
    <div class="payment-modal__overlay" @click="onClose" />

    <div class="payment-modal__panel">
      <header class="payment-modal__header">
        <div>
          <h2 class="payment-modal__title">Cobrar</h2>
          <p class="payment-modal__total">
            Total <strong>{{ formatPrice(total) }}</strong>
          </p>
        </div>
        <button
          type="button"
          class="payment-modal__close"
          aria-label="Cerrar"
          :disabled="submitting"
          @click="onClose"
        >
          ×
        </button>
      </header>

      <div class="payment-modal__body">
        <!-- Panel izquierdo: pagos agregados + restante -->
        <section class="payment-modal__summary">
          <h3 class="payment-modal__section-title">Pagos</h3>

          <ul v-if="payments.length > 0" class="payment-modal__payments">
            <li
              v-for="(p, idx) in payments"
              :key="idx"
              class="payment-modal__payment-item"
            >
              <div class="payment-modal__payment-info">
                <span class="payment-modal__payment-method">
                  {{ methodLabel(p.method as PaymentMethod) }}
                </span>
                <span class="payment-modal__payment-amount">
                  {{ formatPrice(p.amount) }}
                </span>
                <span
                  v-if="p.tendered_amount !== undefined"
                  class="payment-modal__payment-tendered"
                >
                  recibido {{ formatPrice(p.tendered_amount) }} ·
                  cambio
                  {{ formatPrice(round2(p.tendered_amount - p.amount)) }}
                </span>
              </div>
              <button
                type="button"
                class="payment-modal__remove"
                aria-label="Quitar pago"
                :disabled="submitting"
                @click="removePayment(idx)"
              >
                ×
              </button>
            </li>
          </ul>
          <p v-else class="payment-modal__empty">Sin pagos aun</p>

          <div class="payment-modal__remaining">
            <span>Resta</span>
            <strong :class="{ 'payment-modal__remaining-zero': isFullyPaid }">
              {{ formatPrice(Math.max(0, remaining)) }}
            </strong>
          </div>
        </section>

        <!-- Panel derecho: formulario del metodo activo -->
        <section class="payment-modal__form">
          <div class="payment-modal__methods">
            <button
              v-for="m in METHODS"
              :key="m.value"
              type="button"
              class="payment-modal__method-btn"
              :class="{
                'payment-modal__method-btn--active': activeMethod === m.value,
              }"
              :disabled="submitting || isFullyPaid"
              @click="selectMethod(m.value)"
            >
              {{ m.label }}
            </button>
          </div>

          <div v-if="!isFullyPaid" class="payment-modal__fields">
            <label class="payment-modal__field">
              <span>A pagar</span>
              <input
                v-model="inputAmount"
                type="text"
                inputmode="decimal"
                pattern="[0-9]*[.,]?[0-9]*"
                :disabled="submitting"
              />
            </label>

            <template v-if="activeMethod === 'cash'">
              <label class="payment-modal__field">
                <span>Recibido</span>
                <input
                  v-model="inputTendered"
                  type="text"
                  inputmode="decimal"
                  pattern="[0-9]*[.,]?[0-9]*"
                  :disabled="submitting"
                />
              </label>

              <div class="payment-modal__denominations">
                <button
                  v-for="d in CASH_DENOMINATIONS"
                  :key="d"
                  type="button"
                  class="payment-modal__denom-btn"
                  :disabled="submitting"
                  @click="addDenomination(d)"
                >
                  +{{ formatPrice(d) }}
                </button>
                <button
                  type="button"
                  class="payment-modal__denom-btn payment-modal__denom-btn--exact"
                  :disabled="submitting"
                  @click="setExact"
                >
                  Exacto
                </button>
                <button
                  type="button"
                  class="payment-modal__denom-btn payment-modal__denom-btn--clear"
                  :disabled="submitting"
                  @click="clearTendered"
                >
                  Limpiar
                </button>
              </div>

              <p
                v-if="changeForCash > 0"
                class="payment-modal__change"
                role="status"
              >
                Cambio <strong>{{ formatPrice(changeForCash) }}</strong>
              </p>
            </template>

            <template
              v-if="activeMethod === 'card_credit' || activeMethod === 'card_debit'"
            >
              <label class="payment-modal__field">
                <span>Cod. autorizacion (opcional)</span>
                <input
                  v-model="inputAuthCode"
                  type="text"
                  maxlength="100"
                  :disabled="submitting"
                />
              </label>
              <label class="payment-modal__field">
                <span>Marca (opcional)</span>
                <input
                  v-model="inputCardBrand"
                  type="text"
                  maxlength="50"
                  placeholder="visa, mastercard..."
                  :disabled="submitting"
                />
              </label>
              <label class="payment-modal__field">
                <span>Ultimos 4 (opcional)</span>
                <input
                  v-model="inputCardLast4"
                  type="text"
                  maxlength="4"
                  inputmode="numeric"
                  placeholder="1234"
                  :disabled="submitting"
                />
              </label>
            </template>

            <template v-if="activeMethod === 'transfer'">
              <label class="payment-modal__field">
                <span>Referencia (opcional)</span>
                <input
                  v-model="inputReference"
                  type="text"
                  maxlength="255"
                  :disabled="submitting"
                />
              </label>
            </template>

            <button
              type="button"
              class="payment-modal__add-btn"
              :disabled="submitting"
              @click="addPayment"
            >
              Agregar pago
            </button>
          </div>

          <p v-if="visibleError" class="payment-modal__error" role="alert">
            {{ visibleError }}
          </p>
        </section>
      </div>

      <footer class="payment-modal__footer">
        <button
          type="button"
          class="payment-modal__cancel"
          :disabled="submitting"
          @click="onClose"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="payment-modal__confirm"
          :disabled="!isFullyPaid || submitting"
          @click="onConfirm"
        >
          {{ submitting ? 'Procesando...' : 'Confirmar venta' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.payment-modal {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
}

.payment-modal__overlay {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
}

.payment-modal__panel {
  position: relative;
  width: min(960px, 96vw);
  max-height: 92vh;
  display: flex;
  flex-direction: column;
  background: var(--color-background, #fff);
  border-radius: var(--pos-radius-lg, 12px);
  box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
  overflow: hidden;
}

.payment-modal__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--color-border, #e5e5e5);
}

.payment-modal__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--color-text, #1a1a1a);
}

.payment-modal__total {
  margin: 0.25rem 0 0;
  font-size: 1.6rem;
  color: var(--color-text, #1a1a1a);
}

.payment-modal__total strong {
  font-weight: 700;
}

.payment-modal__close {
  background: transparent;
  border: none;
  font-size: 1.75rem;
  line-height: 1;
  cursor: pointer;
  color: var(--color-text-muted, #666);
  padding: 0 0.5rem;
}

.payment-modal__close:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.payment-modal__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr);
  gap: 0;
  flex: 1;
  overflow: hidden;
}

.payment-modal__summary,
.payment-modal__form {
  padding: 1.25rem 1.5rem;
  overflow-y: auto;
}

.payment-modal__summary {
  background: var(--color-background-soft, #f7f7f7);
  border-right: 1px solid var(--color-border, #e5e5e5);
}

.payment-modal__section-title {
  margin: 0 0 0.75rem;
  font-size: 0.85rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted, #666);
}

.payment-modal__payments {
  list-style: none;
  margin: 0 0 1rem;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.payment-modal__payment-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem;
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e5e5);
  border-radius: var(--pos-radius-md, 8px);
}

.payment-modal__payment-info {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}

.payment-modal__payment-method {
  font-size: 0.85rem;
  color: var(--color-text-muted, #666);
}

.payment-modal__payment-amount {
  font-size: 1.1rem;
  font-weight: 600;
}

.payment-modal__payment-tendered {
  font-size: 0.75rem;
  color: var(--color-text-muted, #666);
}

.payment-modal__remove {
  background: transparent;
  border: 1px solid var(--color-border, #e5e5e5);
  border-radius: 50%;
  width: 28px;
  height: 28px;
  cursor: pointer;
  line-height: 1;
  color: var(--color-text-muted, #666);
}

.payment-modal__remove:hover:not(:disabled) {
  background: #fee;
  color: #c33;
  border-color: #c33;
}

.payment-modal__empty {
  margin: 0 0 1rem;
  color: var(--color-text-muted, #999);
  font-size: 0.9rem;
}

.payment-modal__remaining {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding-top: 0.75rem;
  border-top: 2px solid var(--color-border, #e5e5e5);
  font-size: 1rem;
}

.payment-modal__remaining strong {
  font-size: 1.4rem;
  font-weight: 700;
}

.payment-modal__remaining-zero {
  color: #2a8a3e;
}

.payment-modal__methods {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.payment-modal__method-btn {
  padding: 0.85rem 0.75rem;
  border: 1px solid var(--color-border, #e5e5e5);
  border-radius: var(--pos-radius-md, 8px);
  background: var(--color-background, #fff);
  font-size: 0.95rem;
  font-weight: 500;
  cursor: pointer;
  color: var(--color-text, #1a1a1a);
}

.payment-modal__method-btn--active {
  background: var(--pos-accent, #1e6dd8);
  color: #fff;
  border-color: var(--pos-accent, #1e6dd8);
}

.payment-modal__method-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.payment-modal__fields {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.payment-modal__field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.payment-modal__field span {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted, #666);
  font-weight: 500;
}

.payment-modal__field input {
  padding: 0.75rem;
  font-size: 1.05rem;
  border: 1px solid var(--color-border, #d4d4d4);
  border-radius: var(--pos-radius-md, 8px);
  background: var(--color-background, #fff);
  color: var(--color-text, #1a1a1a);
}

.payment-modal__field input:focus {
  outline: 2px solid var(--pos-accent, #1e6dd8);
  outline-offset: -1px;
}

.payment-modal__denominations {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0.4rem;
}

.payment-modal__denom-btn {
  padding: 0.55rem 0.25rem;
  border: 1px solid var(--color-border, #d4d4d4);
  border-radius: var(--pos-radius-sm, 6px);
  background: var(--color-background, #fff);
  cursor: pointer;
  font-size: 0.85rem;
  color: var(--color-text, #1a1a1a);
}

.payment-modal__denom-btn:hover:not(:disabled) {
  background: var(--color-background-soft, #f0f0f0);
}

.payment-modal__denom-btn--exact {
  background: var(--color-background-soft, #f0f0f0);
  font-weight: 600;
}

.payment-modal__denom-btn--clear {
  background: var(--color-background, #fff);
  color: var(--color-text-muted, #999);
}

.payment-modal__change {
  margin: 0;
  padding: 0.75rem;
  background: #e8f5ec;
  border: 1px solid #2a8a3e;
  border-radius: var(--pos-radius-md, 8px);
  color: #1a5c2a;
  font-size: 1rem;
}

.payment-modal__change strong {
  font-size: 1.2rem;
  font-weight: 700;
  margin-left: 0.4rem;
}

.payment-modal__add-btn {
  padding: 0.85rem;
  border: 2px dashed var(--pos-accent, #1e6dd8);
  border-radius: var(--pos-radius-md, 8px);
  background: transparent;
  color: var(--pos-accent, #1e6dd8);
  font-weight: 600;
  cursor: pointer;
  margin-top: 0.5rem;
}

.payment-modal__add-btn:hover:not(:disabled) {
  background: rgba(30, 109, 216, 0.06);
}

.payment-modal__add-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.payment-modal__error {
  margin: 1rem 0 0;
  padding: 0.75rem 1rem;
  background: #fdecec;
  border: 1px solid #c33;
  border-radius: var(--pos-radius-md, 8px);
  color: #8a1f1f;
  font-size: 0.9rem;
}

.payment-modal__footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--color-border, #e5e5e5);
  background: var(--color-background-soft, #fafafa);
}

.payment-modal__cancel {
  padding: 0.75rem 1.25rem;
  background: transparent;
  border: 1px solid var(--color-border, #d4d4d4);
  border-radius: var(--pos-radius-md, 8px);
  cursor: pointer;
  color: var(--color-text, #1a1a1a);
  font-size: 1rem;
}

.payment-modal__confirm {
  padding: 0.75rem 1.5rem;
  background: var(--pos-accent, #1e6dd8);
  color: #fff;
  border: none;
  border-radius: var(--pos-radius-md, 8px);
  cursor: pointer;
  font-size: 1.05rem;
  font-weight: 600;
}

.payment-modal__confirm:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

@media (max-width: 720px) {
  .payment-modal__panel {
    width: 100vw;
    height: 100vh;
    max-height: 100vh;
    border-radius: 0;
  }

  .payment-modal__body {
    grid-template-columns: 1fr;
    grid-template-rows: auto 1fr;
  }

  .payment-modal__summary {
    border-right: none;
    border-bottom: 1px solid var(--color-border, #e5e5e5);
  }
}
</style>
