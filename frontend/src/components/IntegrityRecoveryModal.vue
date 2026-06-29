<script setup lang="ts">
/**
 * Modal de recuperacion ante datos locales corruptos (doc maestro 42.3).
 *
 * Bloquea la UI con el mensaje "Datos locales corruptos. Soporte requerido."
 * y ofrece dos acciones:
 *  - Exportar pendientes -> JSON descargable (enviar a soporte ANTES de borrar)
 *  - Restaurar           -> borra IndexedDB + repobla via snapshot inicial
 *
 * Estilo siguiendo el estandar de ProductFormModal.
 */
import { useIntegrityRecovery } from '@/composables/useIntegrityRecovery'

const emit = defineEmits<{ restored: [] }>()

const {
  exporting,
  restoring,
  errorMessage,
  exported,
  restored,
  restoreProgress,
  exportPending,
  restore,
} = useIntegrityRecovery()

async function onRestore(): Promise<void> {
  const ok = await restore()
  if (ok) emit('restored')
}

const PROGRESS_LABELS: Record<string, string> = {
  products: 'productos',
  customers: 'clientes',
  taxes: 'impuestos',
}
</script>

<template>
  <div class="intg-modal__backdrop">
    <div class="intg-modal" role="alertdialog" aria-modal="true" aria-labelledby="intg-title">
      <header class="intg-modal__header">
        <h2 id="intg-title">Datos locales corruptos</h2>
      </header>

      <div class="intg-modal__body">
        <p class="intg-modal__lead">
          Se detecto un problema con la base de datos local. Para evitar perder ventas
          sin sincronizar, exporta primero los datos pendientes y luego restaura.
        </p>

        <p v-if="errorMessage" class="intg-modal__error">{{ errorMessage }}</p>

        <div v-if="exported" class="intg-modal__note intg-modal__note--ok">
          Datos pendientes exportados. Guarda el archivo o envialo a soporte.
        </div>

        <div v-if="restoreProgress" class="intg-modal__note">
          Repoblando {{ PROGRESS_LABELS[restoreProgress.entity] ?? restoreProgress.entity }}...
          <span v-if="restoreProgress.phase === 'done' && restoreProgress.count != null">
            ({{ restoreProgress.count }})
          </span>
        </div>

        <div v-if="restored" class="intg-modal__note intg-modal__note--ok">
          Restauracion completada. Ya puedes continuar.
        </div>
      </div>

      <footer class="intg-modal__footer">
        <button
          type="button"
          class="intg-modal__btn"
          :disabled="exporting || restoring"
          @click="exportPending"
        >
          {{ exporting ? 'Exportando...' : 'Exportar pendientes' }}
        </button>
        <button
          type="button"
          class="intg-modal__btn intg-modal__btn--danger"
          :disabled="restoring || exporting"
          @click="onRestore"
        >
          {{ restoring ? 'Restaurando...' : 'Restaurar' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.intg-modal__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 200;
  padding: var(--pos-space-md);
}
.intg-modal {
  width: 100%;
  max-width: 480px;
  background: var(--color-background);
  border: 1px solid var(--pos-danger);
  border-radius: var(--pos-radius-lg);
  box-shadow: var(--pos-shadow-card);
  display: flex;
  flex-direction: column;
}
.intg-modal__header {
  padding: var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
}
.intg-modal__header h2 {
  margin: 0;
  color: var(--pos-danger);
  font-size: 1.15rem;
}
.intg-modal__body {
  padding: var(--pos-space-lg);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.intg-modal__lead {
  margin: 0;
  color: var(--color-text);
  font-size: 0.92rem;
  line-height: 1.5;
}
.intg-modal__error {
  margin: 0;
  color: var(--pos-danger);
  font-size: 0.85rem;
  padding: var(--pos-space-sm);
  border: 1px solid var(--pos-danger);
  border-radius: var(--pos-radius-md);
  background: rgba(255, 0, 0, 0.06);
}
.intg-modal__note {
  margin: 0;
  font-size: 0.85rem;
  padding: var(--pos-space-sm) var(--pos-space-md);
  border-radius: var(--pos-radius-md);
  background: var(--color-background-mute);
  color: var(--color-text);
}
.intg-modal__note--ok {
  background: rgba(42, 138, 62, 0.12);
  color: #2a8a3e;
}
.intg-modal__footer {
  padding: var(--pos-space-lg);
  border-top: 1px solid var(--color-border);
  display: flex;
  gap: var(--pos-space-md);
  justify-content: flex-end;
}
.intg-modal__btn {
  padding: 0.7rem 1.3rem;
  border-radius: var(--pos-radius-md);
  font-size: 0.95rem;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text);
}
.intg-modal__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.intg-modal__btn--danger {
  background: var(--pos-danger);
  color: #fff;
  border-color: var(--pos-danger);
}
</style>
