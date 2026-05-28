<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const isSubmitting = ref(false)
const errorMessage = ref<string | null>(null)

async function onSubmit(): Promise<void> {
  if (isSubmitting.value) {
    return
  }
  errorMessage.value = null
  isSubmitting.value = true

  try {
    await authStore.login(email.value, password.value)
    await router.push({ name: 'pos' })
  } catch (err) {
    errorMessage.value = humanizeLoginError(err)
  } finally {
    isSubmitting.value = false
  }
}

/**
 * Convierte el error del SDK en un mensaje legible para el usuario.
 * El SDK arroja el objeto `error` directamente cuando el response no
 * es 2xx; segun la spec OpenAPI, sigue el shape de ErrorEnvelope.
 */
function humanizeLoginError(err: unknown): string {
  if (err && typeof err === 'object' && 'error' in err) {
    const errObj = (err as { error?: { message?: string } }).error
    if (errObj?.message) {
      return errObj.message
    }
  }
  return 'No se pudo iniciar sesion. Revisa tus credenciales o intenta de nuevo.'
}
</script>

<template>
  <main class="login">
    <div class="card">
      <h1>POS Enterprise</h1>
      <p class="subtitle">Inicia sesion para continuar.</p>

      <form @submit.prevent="onSubmit" novalidate>
        <label>
          <span>Email</span>
          <input
            v-model="email"
            type="email"
            autocomplete="username"
            required
            :disabled="isSubmitting"
          />
        </label>

        <label>
          <span>Contrasena</span>
          <input
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
            :disabled="isSubmitting"
          />
        </label>

        <p v-if="errorMessage" class="error" role="alert">{{ errorMessage }}</p>

        <button type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? 'Entrando...' : 'Entrar' }}
        </button>
      </form>
    </div>
  </main>
</template>

<style scoped>
.login {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 1rem;
}

.card {
  width: 100%;
  max-width: 360px;
  padding: 2rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

h1 {
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--color-heading);
  margin: 0 0 0.25rem;
  text-align: center;
}

.subtitle {
  color: var(--color-text);
  opacity: 0.7;
  text-align: center;
  margin: 0 0 1.5rem;
  font-size: 0.875rem;
}

form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  font-size: 0.875rem;
  color: var(--color-text);
}

input {
  padding: 0.625rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: transparent;
  color: var(--color-text);
  font-size: 0.95rem;
  font-family: inherit;
}

input:focus {
  outline: 2px solid var(--color-border-hover);
  outline-offset: -1px;
}

input:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

button {
  padding: 0.7rem 1rem;
  border: none;
  border-radius: 6px;
  background: hsl(160, 100%, 37%);
  color: white;
  font-size: 0.95rem;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
}

button:hover:not(:disabled) {
  background: hsl(160, 100%, 32%);
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.error {
  color: hsl(0, 70%, 55%);
  font-size: 0.875rem;
  margin: 0;
}
</style>
