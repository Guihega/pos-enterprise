<script setup lang="ts">
import { computed, ref } from 'vue'
import { useInventoryStore } from '@/stores/inventory'
import type { Stock, InventoryMovement } from '@/lib/api/generated'

const props = defineProps<{ stock: Stock }>()
const emit = defineEmits<{ adjusted: [movement: InventoryMovement]; cancel: [] }>()

const store = useInventoryStore()

const errorMessage = ref<string | null>(null)
const deltaInput = ref('')
const reason = ref('')

const productName = computed(() => props.stock.product?.name ?? 'Producto')
const productSku = computed(() => props.stock.product?.sku ?? '')
const warehouseName = computed(() => props.stock.warehouse?.name ?? 'Almacen')
const onHand = computed(() => props.stock.quantity.on_hand)

/** Parsea el delta a number; null si vacio o invalido. */
function parseDelta(value: string): number | null {
  const trimmed = value.trim()
  if (trimmed === '') return null
  const n = Number(trimmed)
  return Number.isFinite(n) ? n : null
}

const parsedDelta = computed(() => parseDelta(deltaInput.value))

const resultingQty = computed(() => {
  const d = parsedDelta.value
  return d !== null ? onHand.value + d : null
})

const canSubmit = computed(() => {
  const d = parsedDelta.value
  return d !== null && d !== 0 && reason.value.trim().length >= 3 && !store.adjusting
})

async function onSubmit(): Promise<void> {
  if (!canSubmit.value) return
  errorMessage.value = null

  const d = parsedDelta.value
  if (d === null || d === 0) {
    errorMessage.value = 'El ajuste debe ser un numero distinto de cero.'
    return
  }
  if (reason.value.trim().length < 3) {
    errorMessage.value = 'El motivo debe tener al menos 3 caracteres.'
    return
  }

  const productUuid = props.stock.product?.uuid
  const warehouseUuid = props.stock.warehouse?.uuid
  if (!productUuid || !warehouseUuid) {
    errorMessage.value = 'No se pudo identificar el producto o almacen de la fila.'
    return
  }

  const result = await store.adjust({
    product_uuid: productUuid,
    warehouse_uuid: warehouseUuid,
    delta: d,
    reason: reason.value.trim(),
  })

  if (result.ok && result.movement) {
    emit('adjusted', result.movement)
  } else {
    errorMessage.value = result.errorMessage ?? 'No se pudo aplicar el ajuste.'
  }
}
</script>

<template>
  <div class="adj-modal__backdrop" @click.self="emit('cancel')">
    <div class="adj-modal" role="dialog" aria-modal="true">
      <header class="adj-modal__header">
        <h2>Ajustar existencias</h2>
      </header>

      <div class="adj-modal__body">
        <div class="adj-modal__info">
          <div class="adj-modal__info-row">
            <span class="adj-modal__info-label">Producto</span>
            <span class="adj-modal__info-value">
              {{ productName }}
              <span v-if="productSku" class="adj-modal__sku">{{ productSku }}</span>
            </span>
          </div>
          <div class="adj-modal__info-row">
            <span class="adj-modal__info-label">Almacen</span>
            <span class="adj-modal__info-value">{{ warehouseName }}</span>
          </div>
          <div class="adj-modal__info-row">
            <span class="adj-modal__info-label">Existencia actual</span>
            <span class="adj-modal__info-value">{{ onHand }}</span>
          </div>
        </div>

        <div class="adj-modal__field">
          <label for="a-delta">Ajuste (delta) *</label>
          <input
            id="a-delta"
            v-model="deltaInput"
            type="text"
            inputmode="decimal"
            placeholder="Ej. 5 para sumar, -3 para restar"
          />
          <p class="adj-modal__hint">
            Positivo suma, negativo resta. No puede ser cero.
          </p>
        </div>

        <div v-if="resultingQty !== null" class="adj-modal__preview">
          Existencia resultante: <strong>{{ resultingQty }}</strong>
        </div>

        <div class="adj-modal__field">
          <label for="a-reason">Motivo *</label>
          <textarea
            id="a-reason"
            v-model="reason"
            rows="2"
            maxlength="500"
            placeholder="Ej. conteo fisico, merma, entrada inicial..."
          ></textarea>
        </div>

        <p v-if="errorMessage" class="adj-modal__error">{{ errorMessage }}</p>
      </div>

      <footer class="adj-modal__footer">
        <button type="button" class="adj-modal__btn adj-modal__btn--cancel" :disabled="store.adjusting" @click="emit('cancel')">
          Cancelar
        </button>
        <button type="button" class="adj-modal__btn adj-modal__btn--save" :disabled="!canSubmit" @click="onSubmit">
          {{ store.adjusting ? 'Aplicando...' : 'Aplicar ajuste' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.adj-modal__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
  padding: var(--pos-space-md);
}
.adj-modal {
  width: 100%;
  max-width: 480px;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-lg);
  box-shadow: var(--pos-shadow-card);
  display: flex;
  flex-direction: column;
  max-height: 92vh;
}
.adj-modal__header {
  padding: var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
}
.adj-modal__header h2 {
  margin: 0;
  color: var(--color-heading);
  font-size: 1.2rem;
}
.adj-modal__body {
  padding: var(--pos-space-lg);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.adj-modal__info {
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
  padding: var(--pos-space-md);
  background: var(--color-background-soft);
  border-radius: var(--pos-radius-md);
}
.adj-modal__info-row {
  display: flex;
  justify-content: space-between;
  gap: var(--pos-space-md);
  font-size: 0.9rem;
}
.adj-modal__info-label {
  color: var(--color-text);
  opacity: 0.7;
}
.adj-modal__info-value {
  color: var(--color-heading);
  font-weight: 600;
  text-align: right;
}
.adj-modal__sku {
  font-family: monospace;
  font-size: 0.75rem;
  opacity: 0.6;
  font-weight: 400;
  margin-left: 0.4rem;
}
.adj-modal__field {
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
}
.adj-modal__field label {
  font-size: 0.7rem;
  color: var(--color-text);
  opacity: 0.75;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.adj-modal__field input,
.adj-modal__field textarea {
  padding: 0.6rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 0.95rem;
  font-family: inherit;
  box-sizing: border-box;
  width: 100%;
}
.adj-modal__field textarea {
  resize: vertical;
}
.adj-modal__field input:focus,
.adj-modal__field textarea:focus {
  outline: 2px solid var(--pos-accent);
  outline-offset: -1px;
}
.adj-modal__hint {
  margin: 0;
  font-size: 0.75rem;
  color: var(--color-text);
  opacity: 0.6;
}
.adj-modal__preview {
  font-size: 0.9rem;
  color: var(--color-text);
  padding: var(--pos-space-sm) var(--pos-space-md);
  background: rgba(42, 138, 62, 0.1);
  border-radius: var(--pos-radius-md);
}
.adj-modal__preview strong {
  color: var(--color-heading);
}
.adj-modal__error {
  margin: 0;
  color: var(--pos-danger);
  font-size: 0.85rem;
  padding: var(--pos-space-sm);
  border: 1px solid var(--pos-danger);
  border-radius: var(--pos-radius-md);
  background: rgba(255, 0, 0, 0.06);
}
.adj-modal__footer {
  padding: var(--pos-space-lg);
  border-top: 1px solid var(--color-border);
  display: flex;
  gap: var(--pos-space-md);
  justify-content: flex-end;
}
.adj-modal__btn {
  padding: 0.7rem 1.3rem;
  border-radius: var(--pos-radius-md);
  font-size: 0.95rem;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text);
}
.adj-modal__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.adj-modal__btn--save {
  background: var(--pos-accent);
  color: var(--pos-accent-text);
  border-color: var(--pos-accent);
}
.adj-modal__btn--save:hover:not(:disabled) {
  background: var(--pos-accent-hover);
}
</style>
