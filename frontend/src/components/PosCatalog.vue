<script setup lang="ts">
import { onMounted } from 'vue'
import { formatPrice } from '@/lib/format'
import { useProducts } from '@/composables/useProducts'
import { useStock } from '@/composables/useStock'
import { useAuthStore } from '@/stores/auth'
import type { Product } from '@/lib/api/generated'

const emit = defineEmits<{
  productSelected: [product: Product]
}>()

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

const authStore = useAuthStore()
const stock = useStock()

onMounted(() => {
  void init()
  const tenant = authStore.tenant
  const warehouseUuid = authStore.user?.default_branch?.default_warehouse_uuid
  if (tenant && warehouseUuid) {
    void stock.init(tenant, warehouseUuid)
  }
})

function onProductClick(product: Product): void {
  if (stock.isOutOfStock(product.uuid, product.flags.track_inventory)) return
  emit('productSelected', product)
}

</script>

<template>
  <section class="pos-catalog">
    <div class="pos-catalog__search">
      <input
        v-model="searchTerm"
        type="search"
        placeholder="Buscar producto, SKU o codigo de barras..."
        aria-label="Buscar producto"
        autofocus
      />
    </div>

    <div class="pos-catalog__body">
      <!-- Estado: error -->
      <div v-if="errorMessage" class="pos-catalog__state">
        <p class="pos-catalog__error">{{ errorMessage }}</p>
        <button type="button" class="pos-catalog__retry" @click="retry">
          Reintentar
        </button>
      </div>

      <!-- Estado: cargando (primera vez) -->
      <div v-else-if="loading && items.length === 0" class="pos-catalog__state">
        <p class="pos-catalog__muted">Cargando productos...</p>
      </div>

      <!-- Estado: sin resultados -->
      <div v-else-if="items.length === 0" class="pos-catalog__state">
        <p class="pos-catalog__muted">
          {{
            searchTerm
              ? `No hay productos que coincidan con "${searchTerm}".`
              : 'No hay productos en el catalogo.'
          }}
        </p>
      </div>

      <!-- Estado: con resultados -->
      <div v-else class="pos-catalog__list">
        <p class="pos-catalog__count">{{ total }} producto(s)</p>
        <ul>
          <li
            v-for="product in items"
            :key="product.uuid"
            class="pos-catalog__item"
            :class="{ 'pos-catalog__item--out-of-stock': stock.isOutOfStock(product.uuid, product.flags.track_inventory) }"
            @click="onProductClick(product)"
          >
            <div class="pos-catalog__item-main">
              <span class="pos-catalog__item-name">{{ product.name }}</span>
              <span class="pos-catalog__item-sku">{{ product.sku }}</span>
            </div>
            <div class="pos-catalog__item-right">
              <span
                v-if="stock.isOutOfStock(product.uuid, product.flags.track_inventory)"
                class="pos-catalog__item-badge"
              >Sin stock</span>
              <span class="pos-catalog__item-price">
                {{ formatPrice(product.pricing.price) }}
              </span>
            </div>
          </li>
        </ul>

        <button
          v-if="hasMore"
          type="button"
          class="pos-catalog__load-more"
          :disabled="loadingMore"
          @click="loadMore"
        >
          {{ loadingMore ? 'Cargando...' : 'Cargar mas' }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.pos-catalog {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--color-background-soft, var(--color-background));
}

.pos-catalog__search {
  padding: var(--pos-space-md);
  border-bottom: 1px solid var(--color-border);
}

.pos-catalog__search input {
  width: 100%;
  padding: 0.625rem 0.75rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 0.95rem;
  font-family: inherit;
}

.pos-catalog__search input:focus {
  outline: 2px solid var(--color-border-hover);
  outline-offset: -1px;
}

.pos-catalog__body {
  flex: 1;
  overflow-y: auto;
  padding: var(--pos-space-md);
}

.pos-catalog__state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 100%;
  gap: var(--pos-space-md);
  text-align: center;
}

.pos-catalog__muted {
  margin: 0;
  color: var(--color-text);
  opacity: 0.6;
  font-size: 0.875rem;
}

.pos-catalog__error {
  margin: 0;
  color: var(--pos-danger);
  font-size: 0.875rem;
}

.pos-catalog__retry {
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
  font-family: inherit;
  cursor: pointer;
}

.pos-catalog__list ul {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
}

.pos-catalog__count {
  margin: 0 0 var(--pos-space-sm);
  font-size: 0.75rem;
  color: var(--color-text);
  opacity: 0.6;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.pos-catalog__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--pos-space-md);
  padding: var(--pos-space-md);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: var(--color-background);
  cursor: pointer;
  transition: border-color 0.1s ease;
}

.pos-catalog__item:hover {
  border-color: var(--pos-accent);
}

.pos-catalog__item-main {
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
  min-width: 0;
}

.pos-catalog__item-name {
  color: var(--color-heading);
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pos-catalog__item-sku {
  font-size: 0.75rem;
  color: var(--color-text);
  opacity: 0.6;
}

.pos-catalog__item-price {
  font-weight: 600;
  color: var(--color-heading);
  white-space: nowrap;
}

.pos-catalog__load-more {
  margin-top: var(--pos-space-md);
  width: 100%;
  padding: 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
  font-family: inherit;
  cursor: pointer;
}

.pos-catalog__load-more:hover:not(:disabled) {
  border-color: var(--color-border-hover);
}

.pos-catalog__load-more:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.pos-catalog__item-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: var(--pos-space-xs);
}

.pos-catalog__item--out-of-stock {
  opacity: 0.55;
  cursor: not-allowed;
}

.pos-catalog__item--out-of-stock:hover {
  border-color: var(--color-border);
}

.pos-catalog__item-badge {
  font-size: 0.65rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--pos-danger);
  background: color-mix(in srgb, var(--pos-danger) 12%, transparent);
  padding: 0.1rem 0.4rem;
  border-radius: var(--pos-radius-sm);
  white-space: nowrap;
}
</style>
