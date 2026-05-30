<script setup lang="ts">
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  loading?: boolean
  errorMessage?: string | null
}>()

const emit = defineEmits<{
  (e: 'confirm', countedAmount: number, closingNotes: string | null): void
  (e: 'close'): void
}>()

// type="text" + inputmode decimal: evita el bug de v-model.number que
// convierte a primitivo Number y rompe el parseo (leccion f3ca041).
const countedAmountRaw = ref<string>('')
const closingNotes = ref<string>('')

function parseAmount(raw: string): number {
  const trimmed = raw.trim()
  if (!trimmed) return NaN
  const normalized = trimmed.replace(',', '.')
  const n = Number(normalized)
  return Number.isFinite(n) ? n : NaN
}

const countedAmount = computed(() => parseAmount(countedAmountRaw.value))

const canSubmit = computed(
  () =>
    Number.isFinite(countedAmount.value) &&
    countedAmount.value >= 0 &&
    !props.loading,
)

// Resetear el form cada vez que el modal se abre.
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      countedAmountRaw.value = ''
      closingNotes.value = ''
    }
  },
)

function onConfirm(): void {
  if (!canSubmit.value) return
  emit('confirm', countedAmount.value, closingNotes.value.trim() || null)
}

function onCancel(): void {
  if (props.loading) return
  emit('close')
}
</script>

<template>
  <div v-if="open" class="cash-modal__backdrop">
    <div class="cash-modal" role="dialog" aria-modal="true">
      <header class="cash-modal__header">
        <h2>Cerrar caja</h2>
        <p>Cuenta el efectivo en el cajon y registra el monto contado. El sistema calculara la diferencia contra lo esperado.</p>
      </header>

      <div class="cash-modal__body">
        <div class="cash-modal__field">
          <label for="counted-amount">Monto contado</label>
          <input
            id="counted-amount"
            v-model="countedAmountRaw"
            type="text"
            inputmode="decimal"
            pattern="[0-9]*[.,]?[0-9]*"
            placeholder="0.00"
            :disabled="loading"
          />
        </div>

        <div class="cash-modal__field">
          <label for="closing-notes">Notas (opcional)</label>
          <textarea
            id="closing-notes"
            v-model="closingNotes"
            rows="2"
            maxlength="500"
            placeholder="Cierre turno matutino..."
            :disabled="loading"
          ></textarea>
        </div>

        <p v-if="errorMessage" class="cash-modal__error">
          {{ errorMessage }}
        </p>
      </div>

      <footer class="cash-modal__footer cash-modal__footer--split">
        <button
          type="button"
          class="cash-modal__cancel"
          :disabled="loading"
          @click="onCancel"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="cash-modal__submit"
          :disabled="!canSubmit"
          @click="onConfirm"
        >
          {{ loading ? 'Cerrando...' : 'Cerrar caja' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.cash-modal__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}

.cash-modal {
  width: 100%;
  max-width: 480px;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-lg);
  box-shadow: var(--pos-shadow-card);
  display: flex;
  flex-direction: column;
  max-height: 90vh;
}

.cash-modal__header {
  padding: var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
}

.cash-modal__header h2 {
  margin: 0 0 var(--pos-space-sm);
  color: var(--color-heading);
  font-size: 1.25rem;
}

.cash-modal__header p {
  margin: 0;
  font-size: 0.875rem;
  color: var(--color-text);
  opacity: 0.75;
}

.cash-modal__body {
  padding: var(--pos-space-lg);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}

.cash-modal__field {
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
}

.cash-modal__field label {
  font-size: 0.75rem;
  color: var(--color-text);
  opacity: 0.75;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.cash-modal__field input,
.cash-modal__field textarea {
  padding: 0.625rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 0.95rem;
  font-family: inherit;
}

.cash-modal__field textarea {
  resize: vertical;
  min-height: 40px;
}

.cash-modal__field input:focus,
.cash-modal__field textarea:focus {
  outline: 2px solid var(--pos-accent);
  outline-offset: -1px;
}

.cash-modal__error {
  margin: 0;
  color: var(--pos-danger);
  font-size: 0.875rem;
  padding: var(--pos-space-sm);
  border: 1px solid var(--pos-danger);
  border-radius: var(--pos-radius-md);
  background: rgba(255, 0, 0, 0.06);
}

.cash-modal__footer {
  padding: var(--pos-space-lg);
  border-top: 1px solid var(--color-border);
}

.cash-modal__footer--split {
  display: flex;
  gap: var(--pos-space-md);
}

.cash-modal__cancel {
  flex: 0 0 auto;
  padding: 0.85rem 1.25rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 1rem;
  font-family: inherit;
  cursor: pointer;
}

.cash-modal__cancel:hover:not(:disabled) {
  border-color: var(--color-border-hover);
}

.cash-modal__cancel:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.cash-modal__submit {
  flex: 1;
  padding: 0.85rem;
  border: none;
  border-radius: var(--pos-radius-md);
  background: var(--pos-accent);
  color: var(--pos-accent-text);
  font-size: 1rem;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
}

.cash-modal__submit:hover:not(:disabled) {
  background: var(--pos-accent-hover);
}

.cash-modal__submit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
