<script setup lang="ts">
/**
 * Alerta de reloj desincronizado (doc maestro 42.5).
 *
 * Consume el store integrity (driftSeverity / driftMs):
 *  - 'warning' (>5min): banner naranja no bloqueante, el cajero puede seguir.
 *  - 'blocked' (>30min): modal bloqueante, requiere ajustar el reloj del
 *    dispositivo antes de continuar (paralelo al bloqueo de 42.4).
 *
 * No define logica: la severidad la calcula HeartbeatClient.classifyDrift y la
 * expone el store. Estilo siguiendo SyncStatusBanner / IntegrityRecoveryModal.
 */
import { computed } from 'vue'
import { useIntegrityStore } from '@/stores/integrity'

const integrity = useIntegrityStore()

/** Desfase en minutos, con signo: positivo = reloj adelantado. */
const driftMinutes = computed(() => {
  if (integrity.driftMs == null) return null
  return Math.round(integrity.driftMs / 60000)
})

/** Texto legible del desfase (ej "8 minutos adelantado"). */
const driftText = computed(() => {
  const m = driftMinutes.value
  if (m == null) return ''
  const abs = Math.abs(m)
  const dir = m > 0 ? 'adelantado' : 'atrasado'
  return `${abs} minuto${abs === 1 ? '' : 's'} ${dir}`
})
</script>

<template>
  <!-- Bloqueo (>30min): modal que exige ajustar el reloj -->
  <div
    v-if="integrity.driftBlocked"
    class="drift-modal__backdrop"
  >
    <div class="drift-modal" role="alertdialog" aria-modal="true" aria-labelledby="drift-title">
      <header class="drift-modal__header">
        <h2 id="drift-title">Reloj del dispositivo desincronizado</h2>
      </header>
      <div class="drift-modal__body">
        <p class="drift-modal__lead">
          El reloj de este equipo esta {{ driftText }} respecto al servidor. Para evitar
          ventas con fecha incorrecta, ajusta la hora del dispositivo y vuelve a iniciar sesion.
        </p>
        <p class="drift-modal__hint">
          Revisa la configuracion de fecha y hora del sistema (preferiblemente automatica).
        </p>
      </div>
    </div>
  </div>

  <!-- Advertencia (>5min): banner naranja no bloqueante -->
  <transition v-else name="drift-fade">
    <div
      v-if="integrity.driftWarning"
      class="drift-banner"
      role="status"
      aria-live="polite"
    >
      <span class="drift-banner__dot" aria-hidden="true"></span>
      <span class="drift-banner__msg">
        El reloj del equipo esta {{ driftText }} respecto al servidor. Verifica la hora del dispositivo.
      </span>
    </div>
  </transition>
</template>

<style scoped>
/* ---- Banner advertencia (>5min) ---- */
.drift-banner {
  position: sticky;
  top: 0;
  z-index: 92;
  display: flex;
  align-items: center;
  gap: var(--pos-space-sm);
  padding: var(--pos-space-sm) var(--pos-space-md);
  font-size: 0.88rem;
  font-weight: 500;
  background: #ffedd5;
  color: #9a3412;
  border-bottom: 1px solid #fdba74;
}
.drift-banner__dot {
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 50%;
  flex-shrink: 0;
  background: currentColor;
}
.drift-banner__msg {
  flex: 1;
  min-width: 0;
}

/* ---- Modal bloqueo (>30min) ---- */
.drift-modal__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 210;
  padding: var(--pos-space-md);
}
.drift-modal {
  width: 100%;
  max-width: 480px;
  background: var(--color-background);
  border: 1px solid var(--pos-danger);
  border-radius: var(--pos-radius-lg);
  box-shadow: var(--pos-shadow-card);
  display: flex;
  flex-direction: column;
}
.drift-modal__header {
  padding: var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
}
.drift-modal__header h2 {
  margin: 0;
  color: var(--pos-danger);
  font-size: 1.15rem;
}
.drift-modal__body {
  padding: var(--pos-space-lg);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.drift-modal__lead {
  margin: 0;
  color: var(--color-text);
  font-size: 0.92rem;
  line-height: 1.5;
}
.drift-modal__hint {
  margin: 0;
  font-size: 0.85rem;
  opacity: 0.75;
}

.drift-fade-enter-active,
.drift-fade-leave-active {
  transition: opacity 0.2s ease;
}
.drift-fade-enter-from,
.drift-fade-leave-to {
  opacity: 0;
}
</style>
