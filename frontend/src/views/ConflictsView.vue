<script setup lang="ts">
/**
 * Cola de conflictos para humanos (doc maestro 39.3).
 *
 * Muestra los conflictos no resueltos. Solo gerente o admin puede resolver
 * (canResolve); para otros roles la vista es de solo lectura. Cada accion
 * de resolucion queda auditada (markResolved auto=false).
 */
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useConflicts } from '@/composables/useConflicts'

const {
  items,
  loading,
  errorMessage,
  resolvingUuid,
  canResolve,
  isEmpty,
  load,
  resolveManual,
} = useConflicts()

onMounted(() => {
  void load()
})

const REASON_LABELS: Record<string, string> = {
  IDEMPOTENT: 'Operacion duplicada',
  STOCK_NEGATIVE: 'Stock insuficiente',
  PRICE_MISMATCH: 'Precio modificado en otro lugar',
  PRODUCT_DELETED: 'Producto eliminado',
  CASH_SESSION_CLOSED: 'Caja ya cerrada',
  STALE_VERSION: 'Version desactualizada',
  FOLIO_DUPLICATE: 'Folio duplicado',
  TENANT_SUSPENDED: 'Cuenta suspendida',
  UNKNOWN: 'Conflicto desconocido',
}

function reasonLabel(reason: string): string {
  return REASON_LABELS[reason] ?? reason
}

const ENTITY_LABELS: Record<string, string> = {
  sale: 'Venta',
  product: 'Producto',
  customer: 'Cliente',
}

function entityLabel(entity: string): string {
  return ENTITY_LABELS[entity] ?? entity
}
</script>

<template>
  <div class="conf-view">
    <header class="conf-view__header">
      <div>
        <RouterLink :to="{ name: 'pos' }" class="conf-view__back">&larr; Punto de venta</RouterLink>
        <h1 class="conf-view__title">Conflictos pendientes</h1>
        <p class="conf-view__subtitle">{{ items.length }} por resolver</p>
      </div>
    </header>

    <p v-if="!canResolve && !isEmpty" class="conf-view__notice">
      Solo un gerente o administrador puede resolver conflictos. Esta vista es de solo lectura.
    </p>

    <p v-if="errorMessage" class="conf-view__error">{{ errorMessage }}</p>

    <div class="conf-view__body">
      <div v-if="loading" class="conf-view__state">
        <p>Cargando conflictos...</p>
      </div>

      <div v-else-if="isEmpty" class="conf-view__state">
        <p>No hay conflictos pendientes.</p>
      </div>

      <ul v-else class="conf-list">
        <li v-for="c in items" :key="c.uuid" class="conf-card">
          <div class="conf-card__head">
            <span class="conf-card__badge">{{ entityLabel(c.entityType) }}</span>
            <span class="conf-card__reason">{{ reasonLabel(c.reason) }}</span>
          </div>
          <p class="conf-card__meta">
            <span class="conf-card__uuid">{{ c.entityUuid }}</span>
            <span class="conf-card__date">{{ new Date(c.detectedAt).toLocaleString() }}</span>
          </p>
          <div v-if="canResolve" class="conf-card__actions">
            <button
              type="button"
              class="conf-card__btn conf-card__btn--client"
              :disabled="resolvingUuid === c.uuid"
              @click="resolveManual(c.uuid, 'use_client')"
            >
              Mantener mio
            </button>
            <button
              type="button"
              class="conf-card__btn conf-card__btn--server"
              :disabled="resolvingUuid === c.uuid"
              @click="resolveManual(c.uuid, 'use_server')"
            >
              Aceptar el otro
            </button>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.conf-view {
  max-width: 900px;
  margin: 0 auto;
  padding: var(--pos-space-lg);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.conf-view__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--pos-space-md);
}
.conf-view__back {
  display: inline-block;
  margin-bottom: 0.4rem;
  font-size: 0.85rem;
  color: var(--pos-accent);
  text-decoration: none;
}
.conf-view__back:hover { text-decoration: underline; }
.conf-view__title {
  margin: 0;
  color: var(--color-heading);
  font-size: 1.5rem;
}
.conf-view__subtitle {
  margin: 0.25rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text);
  opacity: 0.7;
}
.conf-view__notice {
  margin: 0;
  padding: var(--pos-space-sm) var(--pos-space-md);
  border-radius: var(--pos-radius-md);
  background: #fef3c7;
  color: #92400e;
  font-size: 0.88rem;
}
.conf-view__error {
  margin: 0;
  color: var(--pos-danger);
  font-size: 0.9rem;
}
.conf-view__state {
  padding: var(--pos-space-xl);
  text-align: center;
  color: var(--color-text);
  opacity: 0.8;
}
.conf-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.conf-card {
  border: 1px solid var(--color-border);
  border-left: 3px solid var(--pos-danger);
  border-radius: var(--pos-radius-md);
  padding: var(--pos-space-md);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-sm);
  background: var(--color-background);
}
.conf-card__head {
  display: flex;
  align-items: center;
  gap: var(--pos-space-sm);
}
.conf-card__badge {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 0.15rem 0.5rem;
  border-radius: var(--pos-radius-sm);
  background: var(--color-background-mute);
  color: var(--color-text);
}
.conf-card__reason {
  font-weight: 600;
  color: var(--color-heading);
  font-size: 0.95rem;
}
.conf-card__meta {
  margin: 0;
  display: flex;
  justify-content: space-between;
  gap: var(--pos-space-md);
  font-size: 0.8rem;
  opacity: 0.7;
}
.conf-card__uuid {
  font-family: monospace;
}
.conf-card__actions {
  display: flex;
  gap: var(--pos-space-sm);
  justify-content: flex-end;
}
.conf-card__btn {
  padding: 0.4rem 0.9rem;
  border-radius: var(--pos-radius-sm);
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text);
  font-size: 0.85rem;
  font-family: inherit;
  font-weight: 600;
  cursor: pointer;
}
.conf-card__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.conf-card__btn--server {
  border-color: var(--pos-accent);
  color: var(--pos-accent);
}
.conf-card__btn:hover:not(:disabled) {
  border-color: var(--color-border-hover);
}
</style>
