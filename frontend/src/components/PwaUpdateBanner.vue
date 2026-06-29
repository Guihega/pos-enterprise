<script setup lang="ts">
/**
 * Banner de actualizacion del Service Worker (doc maestro 37.2).
 *
 * immediate-notify + manual-approve: cuando hay una version nueva, muestra
 * "Hay una nueva version disponible" con boton "Actualizar ahora". El usuario
 * puede ignorarlo (Despues); nunca se actualiza solo para no interrumpir una
 * venta. Tambien confirma brevemente cuando la app queda lista offline.
 *
 * Estilo siguiendo el estandar de SyncStatusBanner.
 */
import { usePwaStore } from '@/stores/pwa'

const pwa = usePwaStore()
</script>

<template>
  <transition name="pwa-banner-fade">
    <div v-if="pwa.needRefresh" class="pwa-banner pwa-banner--update" role="status" aria-live="polite">
      <span class="pwa-banner__msg">Hay una nueva version disponible.</span>
      <span class="pwa-banner__actions">
        <button
          type="button"
          class="pwa-banner__btn pwa-banner__btn--primary"
          :disabled="pwa.updating"
          @click="pwa.applyUpdate"
        >
          {{ pwa.updating ? 'Actualizando...' : 'Actualizar ahora' }}
        </button>
        <button
          type="button"
          class="pwa-banner__btn"
          :disabled="pwa.updating"
          @click="pwa.dismiss"
        >
          Despues
        </button>
      </span>
    </div>
  </transition>
</template>

<style scoped>
.pwa-banner {
  position: sticky;
  top: 0;
  z-index: 95;
  display: flex;
  align-items: center;
  gap: var(--pos-space-sm);
  padding: var(--pos-space-sm) var(--pos-space-md);
  font-size: 0.88rem;
  font-weight: 500;
  border-bottom: 1px solid transparent;
}
.pwa-banner__msg {
  flex: 1;
  min-width: 0;
}
.pwa-banner__actions {
  display: flex;
  gap: var(--pos-space-xs);
  flex-shrink: 0;
}
.pwa-banner__btn {
  padding: 0.3rem 0.8rem;
  border-radius: var(--pos-radius-sm);
  border: 1px solid currentColor;
  background: transparent;
  color: inherit;
  font-size: 0.82rem;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
}
.pwa-banner__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.pwa-banner__btn--primary {
  background: var(--pos-accent);
  color: var(--pos-accent-text);
  border-color: var(--pos-accent);
}
.pwa-banner--update {
  background: rgba(30, 64, 175, 0.1);
  color: #1e40af;
  border-bottom-color: rgba(30, 64, 175, 0.25);
}

.pwa-banner-fade-enter-active,
.pwa-banner-fade-leave-active {
  transition: opacity 0.2s ease;
}
.pwa-banner-fade-enter-from,
.pwa-banner-fade-leave-to {
  opacity: 0;
}
</style>
