<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router'
import { formatPrice } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'
import { useCashSessionStore } from '@/stores/cashSession'

const router = useRouter()
const authStore = useAuthStore()
const cashStore = useCashSessionStore()

const emit = defineEmits<{
  (e: 'close-cash'): void
}>()


async function onLogout(): Promise<void> {
  await authStore.logout()
  cashStore.clear()
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
    <nav class="pos-header__nav">
      <RouterLink :to="{ name: 'pos' }" class="pos-header__navlink">Punto de venta</RouterLink>
      <RouterLink :to="{ name: 'catalogo' }" class="pos-header__navlink">Catalogo</RouterLink>
    </nav>

    <div class="pos-header__user">
      <span
        v-if="cashStore.currentSession"
        class="pos-header__cash"
        :title="`Caja abierta a las ${cashStore.currentSession.opened_at}`"
      >
        {{ cashStore.currentSession.register?.code ?? 'Caja' }} ·
        {{ formatPrice(cashStore.currentSession.opening.amount) }}
      </span>
      <button
        v-if="cashStore.currentSession"
        type="button"
        class="pos-header__close-cash"
        @click="emit('close-cash')"
      >
        Cerrar caja
      </button>
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

.pos-header__nav {
  display: flex;
  gap: var(--pos-space-sm);
  margin-left: var(--pos-space-lg);
}
.pos-header__navlink {
  padding: 0.4rem 0.85rem;
  border-radius: var(--pos-radius-md);
  color: var(--color-text);
  text-decoration: none;
  font-size: 0.875rem;
  opacity: 0.7;
}
.pos-header__navlink:hover {
  opacity: 1;
  background: var(--color-background-mute);
}
.pos-header__navlink.router-link-active {
  opacity: 1;
  color: var(--pos-accent);
  background: rgba(0, 200, 130, 0.1);
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

.pos-header__close-cash {
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--pos-danger);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--pos-danger);
  font-size: 0.875rem;
  font-family: inherit;
  cursor: pointer;
}

.pos-header__close-cash:hover {
  background: rgba(255, 0, 0, 0.06);
}

.pos-header__cash {
  padding: 0.25rem 0.55rem;
  border-radius: var(--pos-radius-sm);
  background: rgba(0, 200, 130, 0.12);
  color: var(--pos-accent);
  font-size: 0.75rem;
  font-weight: 500;
  white-space: nowrap;
}
</style>
