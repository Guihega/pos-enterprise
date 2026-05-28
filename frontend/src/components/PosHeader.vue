<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

async function onLogout(): Promise<void> {
  await authStore.logout()
  // El guard del router redirige automaticamente al cambiar
  // isAuthenticated, pero forzamos el push para mas claridad.
  await router.push({ name: 'login' })
}
</script>

<template>
  <header class="pos-header">
    <div class="pos-header__brand">
      <span class="pos-header__logo" aria-hidden="true">POS</span>
      <strong>POS Enterprise</strong>
    </div>

    <div class="pos-header__user">
      <span v-if="authStore.user" class="pos-header__user-name">
        {{ authStore.user.name }}
      </span>
      <button type="button" class="pos-header__logout" @click="onLogout">
        Salir
      </button>
    </div>
  </header>
</template>

<style scoped>
.pos-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--pos-space-md) var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-background);
}

.pos-header__brand {
  display: flex;
  align-items: center;
  gap: var(--pos-space-sm);
  color: var(--color-heading);
}

.pos-header__logo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--pos-radius-sm);
  background: var(--pos-accent);
  color: var(--pos-accent-text);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.05em;
}

.pos-header__user {
  display: flex;
  align-items: center;
  gap: var(--pos-space-md);
  color: var(--color-text);
}

.pos-header__user-name {
  font-size: 0.875rem;
  opacity: 0.85;
}

.pos-header__logout {
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
  font-family: inherit;
  cursor: pointer;
}

.pos-header__logout:hover {
  border-color: var(--color-border-hover);
}
</style>
