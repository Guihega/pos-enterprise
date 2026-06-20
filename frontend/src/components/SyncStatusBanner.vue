<script setup lang="ts">
/**
 * Banner global de estado de sincronizacion (doc maestro 35.5, 42.4).
 *
 * Consume useSyncStore y muestra:
 *  - offline  -> banner amarillo permanente (35.5, 42.4)
 *  - degraded -> alerta naranja "sync con errores"
 *  - blocked  -> alerta roja "solo lectura" (tenant suspendido)
 *  - syncing  -> indicador sutil de actividad
 *  - idle online sin pendientes/conflictos -> oculto
 *
 * Los contadores (pendingCount, conflictCount) se muestran como apoyo.
 * No define logica: solo refleja el estado del store.
 */
import { computed } from 'vue'
import { useSyncStore } from '@/stores/sync'

const sync = useSyncStore()

type BannerKind = 'offline' | 'degraded' | 'blocked' | 'syncing' | 'pending' | 'none'

const kind = computed<BannerKind>(() => {
  if (sync.isBlocked) return 'blocked'
  if (sync.status === 'offline' || !sync.isOnline) return 'offline'
  if (sync.isDegraded) return 'degraded'
  if (sync.status === 'syncing') return 'syncing'
  if (sync.hasPending || sync.hasConflicts) return 'pending'
  return 'none'
})

const visible = computed(() => kind.value !== 'none')

const message = computed(() => {
  switch (kind.value) {
    case 'blocked':
      return 'Cuenta suspendida. El sistema esta en modo solo lectura.'
    case 'offline':
      return 'Sin conexion. Las ventas se guardan localmente y se sincronizaran al reconectar.'
    case 'degraded':
      return 'Sincronizacion con errores. Reintentando en segundo plano.'
    case 'syncing':
      return 'Sincronizando...'
    case 'pending':
      return 'Cambios pendientes de sincronizar.'
    default:
      return ''
  }
})
</script>

<template>
  <transition name="sync-banner-fade">
    <div
      v-if="visible"
      class="sync-banner"
      :class="`sync-banner--${kind}`"
      role="status"
      aria-live="polite"
      data-cy="offline-banner"
    >
      <span class="sync-banner__dot" aria-hidden="true"></span>
      <span class="sync-banner__msg">{{ message }}</span>
      <span class="sync-banner__counters">
        <span v-if="sync.hasPending" class="sync-banner__chip" title="Operaciones pendientes">
          {{ sync.pendingCount }} pendiente(s)
        </span>
        <RouterLink
          v-if="sync.hasConflicts"
          :to="{ name: 'conflictos' }"
          class="sync-banner__chip sync-banner__chip--link"
          title="Conflictos por resolver"
        >
          {{ sync.conflictCount }} conflicto(s)
        </RouterLink>
      </span>
    </div>
  </transition>
</template>

<style scoped>
.sync-banner {
  position: sticky;
  top: 0;
  z-index: 90;
  display: flex;
  align-items: center;
  gap: var(--pos-space-sm);
  padding: var(--pos-space-sm) var(--pos-space-md);
  font-size: 0.88rem;
  font-weight: 500;
  border-bottom: 1px solid transparent;
}
.sync-banner__dot {
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 50%;
  flex-shrink: 0;
  background: currentColor;
}
.sync-banner__msg {
  flex: 1;
  min-width: 0;
}
.sync-banner__counters {
  display: flex;
  gap: var(--pos-space-xs);
  flex-shrink: 0;
}
.sync-banner__chip {
  font-size: 0.78rem;
  padding: 0.1rem 0.5rem;
  border-radius: var(--pos-radius-sm);
  background: rgba(0, 0, 0, 0.12);
  white-space: nowrap;
}
.sync-banner__chip--link {
  text-decoration: none;
  color: inherit;
  cursor: pointer;
}
.sync-banner__chip--link:hover {
  text-decoration: underline;
}

/* offline: amarillo (35.5, 42.4 banner amarillo permanente) */
.sync-banner--offline {
  background: #fef3c7;
  color: #92400e;
  border-bottom-color: #fcd34d;
}
/* degraded: naranja */
.sync-banner--degraded {
  background: #ffedd5;
  color: #9a3412;
  border-bottom-color: #fdba74;
}
/* blocked: rojo (solo lectura) */
.sync-banner--blocked {
  background: #fee2e2;
  color: #991b1b;
  border-bottom-color: #fca5a5;
}
/* syncing: azul sutil */
.sync-banner--syncing {
  background: rgba(30, 64, 175, 0.1);
  color: #1e40af;
  border-bottom-color: rgba(30, 64, 175, 0.25);
}
.sync-banner--syncing .sync-banner__dot {
  animation: sync-pulse 1.2s ease-in-out infinite;
}
/* pending: gris neutro */
.sync-banner--pending {
  background: var(--color-background-mute);
  color: var(--color-text);
  border-bottom-color: var(--color-border);
}

@keyframes sync-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.3; }
}

.sync-banner-fade-enter-active,
.sync-banner-fade-leave-active {
  transition: opacity 0.2s ease;
}
.sync-banner-fade-enter-from,
.sync-banner-fade-leave-to {
  opacity: 0;
}
</style>
