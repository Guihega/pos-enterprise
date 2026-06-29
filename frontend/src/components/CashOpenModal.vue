<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useCashSessionStore } from '@/stores/cashSession'

const cashStore = useCashSessionStore()

const selectedRegisterUuid = ref<string>('')
const openingAmount = ref<number>(0)
const openingNotes = ref<string>('')

onMounted(async () => {
  await cashStore.loadRegisters()
  // Si hay una sola caja, auto-seleccionarla.
  if (cashStore.registers.length === 1) {
    selectedRegisterUuid.value = cashStore.registers[0]?.uuid ?? ''
  }
})

const canSubmit = computed(
  () => selectedRegisterUuid.value !== '' && !cashStore.loading,
)

async function onSubmit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  await cashStore.open(
    selectedRegisterUuid.value,
    openingAmount.value,
    openingNotes.value.trim() || null,
  )

  // Si quedo sesion activa (open exitoso o 409 con sesion existente del
  // usuario), el modal se desmonta automaticamente porque hasActiveSession
  // pasa a true en PosView.
}
</script>

<template>
  <div class="cash-modal__backdrop">
    <div class="cash-modal" role="dialog" aria-modal="true">
      <header class="cash-modal__header">
        <h2>Abrir caja</h2>
        <p>Antes de empezar a vender, abre tu sesion de caja con el monto inicial en efectivo.</p>
      </header>

      <div class="cash-modal__body">
        <div class="cash-modal__field">
          <label for="cash-register">Caja registradora</label>
          <select
            id="cash-register"
            v-model="selectedRegisterUuid"
            :disabled="cashStore.registers.length <= 1"
          >
            <option value="" disabled>Selecciona una caja...</option>
            <option
              v-for="r in cashStore.registers"
              :key="r.uuid"
              :value="r.uuid"
            >
              {{ r.name }} ({{ r.code }})
            </option>
          </select>
        </div>

        <div class="cash-modal__field">
          <label for="opening-amount">Monto inicial</label>
          <input
            id="opening-amount"
            v-model.number="openingAmount"
            type="number"
            min="0"
            step="0.01"
            placeholder="0.00"
          />
        </div>

        <div class="cash-modal__field">
          <label for="opening-notes">Notas (opcional)</label>
          <textarea
            id="opening-notes"
            v-model="openingNotes"
            rows="2"
            maxlength="500"
            placeholder="Apertura turno matutino..."
          ></textarea>
        </div>

        <p v-if="cashStore.errorMessage" class="cash-modal__error">
          {{ cashStore.errorMessage }}
        </p>
      </div>

      <footer class="cash-modal__footer">
        <button
          type="button"
          class="cash-modal__submit"
          :disabled="!canSubmit"
          @click="onSubmit"
        >
          {{ cashStore.loading ? 'Abriendo...' : 'Abrir caja' }}
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
.cash-modal__field select,
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
.cash-modal__field select:focus,
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

.cash-modal__submit {
  width: 100%;
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
