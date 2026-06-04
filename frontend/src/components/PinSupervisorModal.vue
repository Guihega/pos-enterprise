<script setup lang="ts">
import { ref, watch } from 'vue'
import { pinVerify } from '@/lib/api/generated/sdk.gen'
import { getTenantOrThrow } from '@/lib/api/errors'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{
  confirmed: []
  cancelled: []
}>()

const authStore = useAuthStore()

const pin = ref('')
const loading = ref(false)
const errorMsg = ref<string | null>(null)

watch(
  () => props.open,
  (val) => {
    if (val) {
      pin.value = ''
      errorMsg.value = null
      loading.value = false
    }
  },
)

async function submit(): Promise<void> {
  if (pin.value.length < 4) return
  loading.value = true
  errorMsg.value = null
  try {
    const tenant = getTenantOrThrow(authStore.tenant)
    const { data, error } = await pinVerify({
      headers: { 'X-Tenant': tenant },
      body: { pin: pin.value },
    })
    if (error !== undefined || !data?.data.valid) {
      errorMsg.value = 'PIN incorrecto. Intenta de nuevo.'
      pin.value = ''
      return
    }
    emit('confirmed')
  } catch {
    errorMsg.value = 'Error al verificar el PIN.'
    pin.value = ''
  } finally {
    loading.value = false
  }
}

function cancel(): void {
  emit('cancelled')
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="pin-modal__backdrop" @click.self="cancel">
      <div class="pin-modal" role="dialog" aria-modal="true" aria-labelledby="pin-modal-title">
        <h2 id="pin-modal-title" class="pin-modal__title">Autorización de supervisor</h2>
        <p class="pin-modal__desc">Ingresa tu PIN para continuar.</p>

        <input
          v-model="pin"
          type="password"
          inputmode="numeric"
          maxlength="8"
          placeholder="••••"
          class="pin-modal__input"
          :disabled="loading"
          autofocus
          @keyup.enter="submit"
        />

        <p v-if="errorMsg" class="pin-modal__error">{{ errorMsg }}</p>

        <div class="pin-modal__actions">
          <button type="button" class="pin-modal__btn pin-modal__btn--cancel" :disabled="loading" @click="cancel">
            Cancelar
          </button>
          <button
            type="button"
            class="pin-modal__btn pin-modal__btn--confirm"
            :disabled="loading || pin.length < 4"
            @click="submit"
          >
            {{ loading ? 'Verificando...' : 'Confirmar' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.pin-modal__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 200;
}

.pin-modal {
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-lg);
  padding: var(--pos-space-xl);
  width: min(360px, 90vw);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}

.pin-modal__title {
  margin: 0;
  font-size: 1.1rem;
  color: var(--color-heading);
}

.pin-modal__desc {
  margin: 0;
  font-size: 0.875rem;
  color: var(--color-text);
  opacity: 0.7;
}

.pin-modal__input {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 1.25rem;
  text-align: center;
  letter-spacing: 0.3em;
  font-family: inherit;
  box-sizing: border-box;
}

.pin-modal__input:focus {
  outline: 2px solid var(--pos-accent);
  outline-offset: -1px;
}

.pin-modal__error {
  margin: 0;
  font-size: 0.875rem;
  color: var(--pos-danger);
  text-align: center;
}

.pin-modal__actions {
  display: flex;
  gap: var(--pos-space-md);
  justify-content: flex-end;
}

.pin-modal__btn {
  padding: 0.5rem 1.1rem;
  border-radius: var(--pos-radius-md);
  font-size: 0.875rem;
  font-family: inherit;
  cursor: pointer;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text);
}

.pin-modal__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.pin-modal__btn--confirm {
  background: var(--pos-accent);
  color: #fff;
  border-color: var(--pos-accent);
}

.pin-modal__btn--confirm:hover:not(:disabled) {
  opacity: 0.9;
}
</style>
