<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useCustomers } from '@/composables/useCustomers'
import { useCustomersStore } from '@/stores/customers'
import { formatPrice } from '@/lib/format'
import CustomerFormModal from '@/components/CustomerFormModal.vue'
import type { Customer } from '@/lib/api/generated'

const {
  init,
  searchTerm,
  items,
  loading,
  loadingMore,
  errorMessage,
  hasMore,
  total,
  loadMore,
  retry,
} = useCustomers()

const store = useCustomersStore()

// Modal: null = cerrado; { customer: null } = alta; { customer } = edicion.
const modalOpen = ref(false)
const editingCustomer = ref<Customer | null>(null)

// Banner de feedback tras guardar/eliminar.
const feedback = ref<string | null>(null)
// Confirmacion de borrado: uuid del cliente en confirmacion, o null.
const confirmingDelete = ref<string | null>(null)
const deleteError = ref<string | null>(null)

onMounted(() => {
  void init()
})

function openCreate(): void {
  editingCustomer.value = null
  modalOpen.value = true
}

function openEdit(customer: Customer): void {
  editingCustomer.value = customer
  modalOpen.value = true
}

function onSaved(customer: Customer): void {
  modalOpen.value = false
  feedback.value = `Cliente "${customer.name}" guardado.`
  void retry()
  setTimeout(() => { feedback.value = null }, 4000)
}

function onCancel(): void {
  modalOpen.value = false
  editingCustomer.value = null
}

async function onConfirmDelete(customer: Customer): Promise<void> {
  deleteError.value = null
  const result = await store.remove(customer.uuid)
  if (result.ok) {
    confirmingDelete.value = null
    feedback.value = `Cliente "${customer.name}" eliminado.`
    void retry()
    setTimeout(() => { feedback.value = null }, 4000)
  } else {
    // Incluye 409 CUSTOMER_HAS_BALANCE (cliente con saldo deudor).
    deleteError.value = result.errorMessage ?? 'No se pudo eliminar.'
  }
}

function typeLabel(type: Customer['type']): string {
  return type === 'business' ? 'Empresa' : 'Persona'
}
</script>

<template>
  <div class="cust-view">
    <header class="cust-view__header">
      <div>
        <RouterLink :to="{ name: 'pos' }" class="cust-view__back">&larr; Punto de venta</RouterLink>
        <h1 class="cust-view__title">Clientes</h1>
        <p class="cust-view__subtitle">{{ total }} cliente(s)</p>
      </div>
      <button type="button" class="cust-view__new" @click="openCreate">
        Nuevo cliente
      </button>
    </header>

    <div class="cust-view__search">
      <input
        v-model="searchTerm"
        type="search"
        placeholder="Buscar por nombre, codigo, RFC o email..."
        aria-label="Buscar cliente"
      />
    </div>

    <p v-if="feedback" class="cust-view__feedback">{{ feedback }}</p>

    <div class="cust-view__body">
      <div v-if="errorMessage" class="cust-view__state">
        <p class="cust-view__error">{{ errorMessage }}</p>
        <button type="button" @click="retry">Reintentar</button>
      </div>

      <div v-else-if="loading" class="cust-view__state">
        <p>Cargando clientes...</p>
      </div>

      <div v-else-if="items.length === 0" class="cust-view__state">
        <p>No hay clientes. Crea el primero con "Nuevo cliente".</p>
      </div>

      <table v-else class="cust-table">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Tipo</th>
            <th>Contacto</th>
            <th>Credito disp.</th>
            <th>Estado</th>
            <th class="cust-table__actions-col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in items" :key="c.uuid">
            <td>
              {{ c.name }}
              <span v-if="c.code" class="cust-table__code">{{ c.code }}</span>
            </td>
            <td>{{ typeLabel(c.type) }}</td>
            <td class="cust-table__contact">
              <span v-if="c.contact.email">{{ c.contact.email }}</span>
              <span v-else-if="c.contact.phone">{{ c.contact.phone }}</span>
              <span v-else class="cust-table__muted">—</span>
            </td>
            <td>{{ formatPrice(c.credit.available) }}</td>
            <td>
              <span v-if="c.flags.is_blocked" class="cust-table__status cust-table__status--blocked">Bloqueado</span>
              <span v-else-if="c.flags.is_active" class="cust-table__status cust-table__status--active">Activo</span>
              <span v-else class="cust-table__status cust-table__status--inactive">Inactivo</span>
            </td>
            <td class="cust-table__actions">
              <template v-if="confirmingDelete === c.uuid">
                <span class="cust-table__confirm-text">Eliminar?</span>
                <button type="button" class="cust-table__btn cust-table__btn--danger" :disabled="store.deleting" @click="onConfirmDelete(c)">
                  Si
                </button>
                <button type="button" class="cust-table__btn" :disabled="store.deleting" @click="confirmingDelete = null">
                  No
                </button>
              </template>
              <template v-else>
                <button type="button" class="cust-table__btn" @click="openEdit(c)">Editar</button>
                <button type="button" class="cust-table__btn cust-table__btn--danger" @click="confirmingDelete = c.uuid; deleteError = null">
                  Eliminar
                </button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>

      <p v-if="deleteError" class="cust-view__error">{{ deleteError }}</p>

      <div v-if="hasMore" class="cust-view__more">
        <button type="button" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? 'Cargando...' : 'Cargar mas' }}
        </button>
      </div>
    </div>

    <CustomerFormModal
      v-if="modalOpen"
      :customer="editingCustomer"
      @saved="onSaved"
      @cancel="onCancel"
    />
  </div>
</template>

<style scoped>
.cust-view {
  max-width: 1000px;
  margin: 0 auto;
  padding: var(--pos-space-lg);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.cust-view__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--pos-space-md);
}
.cust-view__back {
  display: inline-block;
  margin-bottom: 0.4rem;
  font-size: 0.85rem;
  color: var(--pos-accent);
  text-decoration: none;
}
.cust-view__back:hover { text-decoration: underline; }
.cust-view__title {
  margin: 0;
  color: var(--color-heading);
  font-size: 1.5rem;
}
.cust-view__subtitle {
  margin: 0.25rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text);
  opacity: 0.7;
}
.cust-view__new {
  padding: 0.7rem 1.3rem;
  border: none;
  border-radius: var(--pos-radius-md);
  background: var(--pos-accent);
  color: var(--pos-accent-text);
  font-size: 0.95rem;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  white-space: nowrap;
}
.cust-view__new:hover {
  background: var(--pos-accent-hover);
}
.cust-view__search input {
  width: 100%;
  padding: 0.7rem 0.9rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 0.95rem;
  font-family: inherit;
  box-sizing: border-box;
}
.cust-view__search input:focus {
  outline: 2px solid var(--pos-accent);
  outline-offset: -1px;
}
.cust-view__feedback {
  margin: 0;
  padding: var(--pos-space-sm) var(--pos-space-md);
  border-radius: var(--pos-radius-md);
  background: rgba(42, 138, 62, 0.12);
  color: #2a8a3e;
  font-size: 0.9rem;
}
.cust-view__state {
  padding: var(--pos-space-xl);
  text-align: center;
  color: var(--color-text);
  opacity: 0.8;
}
.cust-view__error {
  color: var(--pos-danger);
}
.cust-table {
  width: 100%;
  border-collapse: collapse;
}
.cust-table th,
.cust-table td {
  text-align: left;
  padding: 0.7rem 0.75rem;
  border-bottom: 1px solid var(--color-border);
  font-size: 0.9rem;
}
.cust-table th {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text);
  opacity: 0.7;
}
.cust-table__code {
  font-family: monospace;
  font-size: 0.75rem;
  opacity: 0.6;
  margin-left: 0.4rem;
}
.cust-table__contact {
  opacity: 0.85;
}
.cust-table__muted {
  opacity: 0.5;
}
.cust-table__status {
  font-size: 0.75rem;
  padding: 0.15rem 0.5rem;
  border-radius: var(--pos-radius-sm);
}
.cust-table__status--active { background: rgba(42, 138, 62, 0.15); color: #2a8a3e; }
.cust-table__status--blocked { background: rgba(184, 38, 38, 0.15); color: var(--pos-danger); }
.cust-table__status--inactive { background: var(--color-background-mute); opacity: 0.7; }
.cust-table__actions-col {
  text-align: right;
}
.cust-table__actions {
  text-align: right;
  white-space: nowrap;
}
.cust-table__confirm-text {
  font-size: 0.85rem;
  margin-right: 0.5rem;
  opacity: 0.8;
}
.cust-table__btn {
  padding: 0.35rem 0.75rem;
  margin-left: 0.4rem;
  border-radius: var(--pos-radius-sm);
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text);
  font-size: 0.8rem;
  font-family: inherit;
  cursor: pointer;
}
.cust-table__btn:hover:not(:disabled) {
  border-color: var(--color-border-hover);
}
.cust-table__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.cust-table__btn--danger {
  color: var(--pos-danger);
  border-color: var(--pos-danger);
}
.cust-view__more {
  text-align: center;
  padding: var(--pos-space-md);
}
.cust-view__more button {
  padding: 0.6rem 1.5rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-family: inherit;
  cursor: pointer;
}
</style>
