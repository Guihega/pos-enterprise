<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useProducts } from '@/composables/useProducts'
import { useProductsStore } from '@/stores/products'
import { formatPrice } from '@/lib/format'
import ProductFormModal from '@/components/ProductFormModal.vue'
import type { Product } from '@/lib/api/generated'

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
} = useProducts()

const store = useProductsStore()

// Modal: null = cerrado; { product: null } = alta; { product } = edicion.
const modalOpen = ref(false)
const editingProduct = ref<Product | null>(null)

// Banner de feedback tras guardar/eliminar.
const feedback = ref<string | null>(null)
// Confirmacion de borrado: uuid del producto en confirmacion, o null.
const confirmingDelete = ref<string | null>(null)
const deleteError = ref<string | null>(null)

onMounted(() => {
  void init()
})

function openCreate(): void {
  editingProduct.value = null
  modalOpen.value = true
}

function openEdit(product: Product): void {
  editingProduct.value = product
  modalOpen.value = true
}

function onSaved(product: Product): void {
  modalOpen.value = false
  feedback.value = `Producto "${product.name}" guardado.`
  void retry()
  setTimeout(() => { feedback.value = null }, 4000)
}

function onCancel(): void {
  modalOpen.value = false
  editingProduct.value = null
}

async function onConfirmDelete(product: Product): Promise<void> {
  deleteError.value = null
  const result = await store.remove(product.uuid)
  if (result.ok) {
    confirmingDelete.value = null
    feedback.value = `Producto "${product.name}" eliminado.`
    void retry()
    setTimeout(() => { feedback.value = null }, 4000)
  } else {
    deleteError.value = result.errorMessage ?? 'No se pudo eliminar.'
  }
}
</script>

<template>
  <div class="prod-view">
    <header class="prod-view__header">
      <div>
        <RouterLink :to="{ name: 'pos' }" class="prod-view__back">&larr; Punto de venta</RouterLink>
        <h1 class="prod-view__title">Catalogo de productos</h1>
        <p class="prod-view__subtitle">{{ total }} producto(s)</p>
      </div>
      <button type="button" class="prod-view__new" @click="openCreate">
        Nuevo producto
      </button>
    </header>

    <div class="prod-view__search">
      <input
        v-model="searchTerm"
        type="search"
        placeholder="Buscar por nombre, SKU o codigo de barras..."
        aria-label="Buscar producto"
      />
    </div>

    <p v-if="feedback" class="prod-view__feedback">{{ feedback }}</p>

    <div class="prod-view__body">
      <div v-if="errorMessage" class="prod-view__state">
        <p class="prod-view__error">{{ errorMessage }}</p>
        <button type="button" @click="retry">Reintentar</button>
      </div>

      <div v-else-if="loading" class="prod-view__state">
        <p>Cargando catalogo...</p>
      </div>

      <div v-else-if="items.length === 0" class="prod-view__state">
        <p>No hay productos. Crea el primero con "Nuevo producto".</p>
      </div>

      <table v-else class="prod-table">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>SKU</th>
            <th>Precio</th>
            <th>Estado</th>
            <th class="prod-table__actions-col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in items" :key="p.uuid">
            <td>{{ p.name }}</td>
            <td class="prod-table__sku">{{ p.sku }}</td>
            <td>{{ formatPrice(p.pricing.price) }}</td>
            <td>
              <span class="prod-table__status" :class="`prod-table__status--${p.status}`">
                {{ p.status }}
              </span>
            </td>
            <td class="prod-table__actions">
              <template v-if="confirmingDelete === p.uuid">
                <span class="prod-table__confirm-text">Eliminar?</span>
                <button type="button" class="prod-table__btn prod-table__btn--danger" :disabled="store.deleting" @click="onConfirmDelete(p)">
                  Si
                </button>
                <button type="button" class="prod-table__btn" :disabled="store.deleting" @click="confirmingDelete = null">
                  No
                </button>
              </template>
              <template v-else>
                <button type="button" class="prod-table__btn" @click="openEdit(p)">Editar</button>
                <button type="button" class="prod-table__btn prod-table__btn--danger" @click="confirmingDelete = p.uuid; deleteError = null">
                  Eliminar
                </button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>

      <p v-if="deleteError" class="prod-view__error">{{ deleteError }}</p>

      <div v-if="hasMore" class="prod-view__more">
        <button type="button" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? 'Cargando...' : 'Cargar mas' }}
        </button>
      </div>
    </div>

    <ProductFormModal
      v-if="modalOpen"
      :product="editingProduct"
      @saved="onSaved"
      @cancel="onCancel"
    />
  </div>
</template>

<style scoped>
.prod-view {
  max-width: 1000px;
  margin: 0 auto;
  padding: var(--pos-space-lg);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-md);
}
.prod-view__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--pos-space-md);
}
.prod-view__back {
  display: inline-block;
  margin-bottom: 0.4rem;
  font-size: 0.85rem;
  color: var(--pos-accent);
  text-decoration: none;
}
.prod-view__back:hover { text-decoration: underline; }
.prod-view__title {
  margin: 0;
  color: var(--color-heading);
  font-size: 1.5rem;
}
.prod-view__subtitle {
  margin: 0.25rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text);
  opacity: 0.7;
}
.prod-view__new {
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
.prod-view__new:hover {
  background: var(--pos-accent-hover);
}
.prod-view__search input {
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
.prod-view__search input:focus {
  outline: 2px solid var(--pos-accent);
  outline-offset: -1px;
}
.prod-view__feedback {
  margin: 0;
  padding: var(--pos-space-sm) var(--pos-space-md);
  border-radius: var(--pos-radius-md);
  background: rgba(42, 138, 62, 0.12);
  color: #2a8a3e;
  font-size: 0.9rem;
}
.prod-view__state {
  padding: var(--pos-space-xl);
  text-align: center;
  color: var(--color-text);
  opacity: 0.8;
}
.prod-view__error {
  color: var(--pos-danger);
}
.prod-table {
  width: 100%;
  border-collapse: collapse;
}
.prod-table th,
.prod-table td {
  text-align: left;
  padding: 0.7rem 0.75rem;
  border-bottom: 1px solid var(--color-border);
  font-size: 0.9rem;
}
.prod-table th {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text);
  opacity: 0.7;
}
.prod-table__sku {
  font-family: monospace;
  opacity: 0.8;
}
.prod-table__status {
  font-size: 0.75rem;
  padding: 0.15rem 0.5rem;
  border-radius: var(--pos-radius-sm);
  text-transform: capitalize;
}
.prod-table__status--active { background: rgba(42, 138, 62, 0.15); color: #2a8a3e; }
.prod-table__status--draft { background: rgba(184, 134, 11, 0.15); color: #b8860b; }
.prod-table__status--archived { background: var(--color-background-mute); opacity: 0.7; }
.prod-table__actions-col {
  text-align: right;
}
.prod-table__actions {
  text-align: right;
  white-space: nowrap;
}
.prod-table__confirm-text {
  font-size: 0.85rem;
  margin-right: 0.5rem;
  opacity: 0.8;
}
.prod-table__btn {
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
.prod-table__btn:hover:not(:disabled) {
  border-color: var(--color-border-hover);
}
.prod-table__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.prod-table__btn--danger {
  color: var(--pos-danger);
  border-color: var(--pos-danger);
}
.prod-view__more {
  text-align: center;
  padding: var(--pos-space-md);
}
.prod-view__more button {
  padding: 0.6rem 1.5rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-family: inherit;
  cursor: pointer;
}
</style>
