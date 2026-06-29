<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const LAST_TENANT_KEY = 'pos:auth:last_tenant'

const router = useRouter()
const authStore = useAuthStore()

const tenantSlug = ref('')
const email = ref('')
const password = ref('')
const showPassword = ref(false)
const isSubmitting = ref(false)
const errorMessage = ref<string | null>(null)

const tenantTouched = ref(false)
const emailTouched = ref(false)
const passwordTouched = ref(false)

const tenantError = computed(() => {
  if (!tenantTouched.value) return null
  if (!tenantSlug.value.trim()) return 'El slug de empresa es obligatorio.'
  return null
})

const emailError = computed(() => {
  if (!emailTouched.value) return null
  if (!email.value.trim()) return 'El correo es obligatorio.'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) return 'Ingresa un correo valido.'
  return null
})

const passwordError = computed(() => {
  if (!passwordTouched.value) return null
  if (!password.value) return 'La contrasena es obligatoria.'
  return null
})

const formValid = computed(
  () =>
    tenantSlug.value.trim() !== '' &&
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value) &&
    password.value !== '',
)

onMounted(() => {
  const remembered = localStorage.getItem(LAST_TENANT_KEY)
  if (remembered) {
    tenantSlug.value = remembered
  } else if (import.meta.env.VITE_DEV_TENANT) {
    tenantSlug.value = import.meta.env.VITE_DEV_TENANT
  }
})

async function onSubmit(): Promise<void> {
  tenantTouched.value = true
  emailTouched.value = true
  passwordTouched.value = true
  if (!formValid.value || isSubmitting.value) return
  errorMessage.value = null
  isSubmitting.value = true
  try {
    await authStore.login(email.value, password.value, tenantSlug.value)
    localStorage.setItem(LAST_TENANT_KEY, tenantSlug.value)
    await router.push({ name: 'pos' })
  } catch (err) {
    errorMessage.value = humanizeLoginError(err)
  } finally {
    isSubmitting.value = false
  }
}

function humanizeLoginError(err: unknown): string {
  if (err && typeof err === 'object' && 'error' in err) {
    const e = (err as { error?: { message?: string } }).error
    if (e?.message) return e.message
  }
  return 'No se pudo iniciar sesion. Revisa tus credenciales o intenta de nuevo.'
}
</script>

<template>
  <main class="lv-root">
    <div class="lv-brand" aria-hidden="true">
      <div class="lv-brand__top">
        <div class="lv-brand__logo">
          <div class="lv-brand__logo-icon">
            <i class="ti ti-building-store"></i>
          </div>
          <span class="lv-brand__logo-text">POS Enterprise</span>
        </div>
        <h1 class="lv-brand__headline">Tu punto de venta,<br>donde lo necesites.</h1>
        <p class="lv-brand__sub">Gestiona ventas, inventario y caja desde un solo lugar. Disenado para retail en LATAM.</p>
        <ul class="lv-brand__feats">
          <li><i class="ti ti-shield-check"></i>Multi-tenant con aislamiento por empresa</li>
          <li><i class="ti ti-bolt"></i>Ventas en tiempo real</li>
          <li><i class="ti ti-chart-bar"></i>Reportes de caja y arqueo automatico</li>
          <li><i class="ti ti-packages"></i>Control de inventario por almacen</li>
        </ul>
      </div>
      <p class="lv-brand__copy">© 2025 POS Enterprise · LATAM</p>
    </div>

    <div class="lv-form-wrap">
      <div class="lv-form-inner">
      <div class="lv-form-header">
        <h2>Iniciar sesion</h2>
        <p>Ingresa tus credenciales para acceder.</p>
      </div>

      <form class="lv-form" @submit.prevent="onSubmit" novalidate>

        <div class="lv-field" :class="{ 'lv-field--error': tenantError, 'lv-field--valid': !tenantError && tenantTouched && tenantSlug }">
          <label for="lv-tenant">Empresa</label>
          <div class="lv-input-wrap">
            <i class="ti ti-building lv-input-icon"></i>
            <input
              id="lv-tenant"
              v-model="tenantSlug"
              type="text"
              placeholder="slug de la empresa"
              autocomplete="organization"
              :disabled="isSubmitting"
              @blur="tenantTouched = true"
            />
            <i v-if="!tenantError && tenantTouched && tenantSlug" class="ti ti-circle-check lv-input-status lv-input-status--valid"></i>
            <i v-else-if="tenantError" class="ti ti-alert-circle lv-input-status lv-input-status--error"></i>
          </div>
          <span v-if="tenantError" class="lv-field-error"><i class="ti ti-alert-circle"></i>{{ tenantError }}</span>
        </div>

        <div class="lv-field" :class="{ 'lv-field--error': emailError, 'lv-field--valid': !emailError && emailTouched && email }">
          <label for="lv-email">Correo electronico</label>
          <div class="lv-input-wrap">
            <i class="ti ti-mail lv-input-icon"></i>
            <input
              id="lv-email"
              v-model="email"
              type="email"
              placeholder="usuario@empresa.com"
              autocomplete="username"
              :disabled="isSubmitting"
              @blur="emailTouched = true"
            />
            <i v-if="!emailError && emailTouched && email" class="ti ti-circle-check lv-input-status lv-input-status--valid"></i>
            <i v-else-if="emailError" class="ti ti-alert-circle lv-input-status lv-input-status--error"></i>
          </div>
          <span v-if="emailError" class="lv-field-error"><i class="ti ti-alert-circle"></i>{{ emailError }}</span>
        </div>

        <div class="lv-field" :class="{ 'lv-field--error': passwordError, 'lv-field--valid': !passwordError && passwordTouched && password }">
          <label for="lv-password">Contrasena</label>
          <div class="lv-input-wrap">
            <i class="ti ti-lock lv-input-icon"></i>
            <input
              id="lv-password"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="••••••••"
              autocomplete="current-password"
              :disabled="isSubmitting"
              @blur="passwordTouched = true"
            />
            <button
              type="button"
              class="lv-toggle-pass"
              :aria-label="showPassword ? 'Ocultar contrasena' : 'Mostrar contrasena'"
              @click="showPassword = !showPassword"
            >
              <i :class="showPassword ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
            </button>
          </div>
          <span v-if="passwordError" class="lv-field-error"><i class="ti ti-alert-circle"></i>{{ passwordError }}</span>
        </div>

        <div v-if="errorMessage" class="lv-error-banner" role="alert">
          <i class="ti ti-alert-triangle"></i>
          <span>{{ errorMessage }}</span>
        </div>

        <button type="submit" class="lv-submit" :disabled="isSubmitting">
          <span v-if="isSubmitting" class="lv-spinner" aria-hidden="true"></span>
          <i v-else class="ti ti-login"></i>
          {{ isSubmitting ? 'Verificando...' : 'Entrar' }}
        </button>

      </form>

      <p class="lv-footer">Problemas para acceder? Contacta a tu administrador.</p>
      </div>
    </div>
  </main>
</template>

<style scoped>
.lv-root {
  display: flex;
  min-height: 100vh;
  background: var(--color-background-primary);
  align-items: stretch;
}

.lv-brand {
  flex: 0 0 42%;
  background: var(--color-background-secondary);
  border-right: 0.5px solid var(--color-border-tertiary);
  padding: 3.5rem 3.5rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 100vh;
}

.lv-brand__logo {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 2.5rem;
}

.lv-brand__logo-icon {
  width: 38px;
  height: 38px;
  border-radius: 9px;
  background: #1D9E75;
  display: flex;
  align-items: center;
  justify-content: center;
}

.lv-brand__logo-icon i {
  font-size: 19px;
  color: #fff;
}

.lv-brand__logo-text {
  font-size: 15px;
  font-weight: 500;
  color: var(--color-text-primary);
}

.lv-brand__headline {
  font-size: 28px;
  font-weight: 500;
  color: var(--color-text-primary);
  line-height: 1.3;
  margin-bottom: 0.85rem;
}

.lv-brand__sub {
  font-size: 13.5px;
  color: var(--color-text-secondary);
  line-height: 1.65;
  max-width: 280px;
  margin-bottom: 2rem;
}

.lv-brand__feats {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.lv-brand__feats li {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: var(--color-text-secondary);
}

.lv-brand__feats i {
  font-size: 16px;
  color: #1D9E75;
  flex-shrink: 0;
}

.lv-brand__copy {
  font-size: 11px;
  color: var(--color-text-tertiary);
}

.lv-form-wrap {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  padding: 4rem 3rem;
  min-height: 100vh;
}

.lv-form-inner {
  width: 100%;
  max-width: 380px;
}

.lv-form-header {
  margin-bottom: 2rem;
}

.lv-form-header h2 {
  font-size: 22px;
  font-weight: 500;
  color: var(--color-text-primary);
  margin-bottom: 0.35rem;
}

.lv-form-header p {
  font-size: 13.5px;
  color: var(--color-text-secondary);
}

.lv-form {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
}

.lv-field {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.lv-field label {
  font-size: 11.5px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-secondary);
}

.lv-input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}

.lv-input-icon {
  position: absolute;
  left: 11px;
  font-size: 16px;
  color: var(--color-text-tertiary);
  pointer-events: none;
}

.lv-input-wrap input {
  width: 100%;
  padding: 10px 36px 10px 34px;
  border: 0.5px solid var(--color-border-secondary);
  border-radius: var(--border-radius-md);
  background: var(--color-background-primary);
  color: var(--color-text-primary);
  font-size: 14px;
  font-family: inherit;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
}

.lv-input-wrap input:focus {
  border-color: #1D9E75;
  box-shadow: 0 0 0 3px rgba(29, 158, 117, 0.13);
}

.lv-input-wrap input:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.lv-field--error .lv-input-wrap input {
  border-color: #E24B4A;
  box-shadow: 0 0 0 3px rgba(226, 75, 74, 0.1);
}

.lv-field--valid .lv-input-wrap input {
  border-color: #639922;
}

.lv-input-status {
  position: absolute;
  right: 10px;
  font-size: 16px;
  pointer-events: none;
}

.lv-input-status--valid { color: #639922; }
.lv-input-status--error { color: #E24B4A; }

.lv-toggle-pass {
  position: absolute;
  right: 10px;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--color-text-tertiary);
  font-size: 16px;
  padding: 0;
  display: flex;
  line-height: 1;
}

.lv-toggle-pass:hover { color: var(--color-text-secondary); }

.lv-field-error {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: #E24B4A;
}

.lv-field-error i { font-size: 13px; }

.lv-error-banner {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  padding: 10px 13px;
  border-radius: var(--border-radius-md);
  background: var(--color-background-danger);
  border: 0.5px solid var(--color-border-danger);
  font-size: 13px;
  color: var(--color-text-danger);
}

.lv-error-banner i { font-size: 16px; flex-shrink: 0; margin-top: 1px; }

.lv-submit {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  padding: 11px;
  border: none;
  border-radius: var(--border-radius-md);
  background: #1D9E75;
  color: #fff;
  font-size: 14px;
  font-weight: 500;
  font-family: inherit;
  cursor: pointer;
  transition: background 0.15s, opacity 0.15s;
  margin-top: 0.25rem;
}

.lv-submit:hover:not(:disabled) { background: #0F6E56; }
.lv-submit:disabled { opacity: 0.6; cursor: not-allowed; }

.lv-spinner {
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255,255,255,0.35);
  border-top-color: #fff;
  border-radius: 50%;
  animation: lv-spin 0.7s linear infinite;
  flex-shrink: 0;
}

@keyframes lv-spin { to { transform: rotate(360deg); } }

.lv-footer {
  margin-top: 1.5rem;
  font-size: 12px;
  color: var(--color-text-tertiary);
  text-align: center;
}

@media (max-width: 680px) {
  .lv-brand { display: none; }
  .lv-form-wrap { padding: 2rem 1.5rem; }
}
</style>
