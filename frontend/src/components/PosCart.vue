<script setup lang="ts">
import { useCartStore } from '@/stores/cart'
import { useStock } from '@/composables/useStock'
import { formatPrice } from '@/lib/format'

const cartStore = useCartStore()
const stock = useStock()


function onQuantityInput(productUuid: string, event: Event): void {
  const input = event.target as HTMLInputElement
  const value = parseFloat(input.value)
  if (Number.isFinite(value)) {
    const maxQty = stock.availableFor(productUuid)
    const clamped = maxQty < Infinity ? Math.min(value, maxQty) : value
    cartStore.setQuantity(productUuid, clamped)
    if (clamped !== value) {
      input.value = String(clamped)
    }
  }
}

function increment(uuid: string, current: number, allowDecimals: boolean): void {
  cartStore.setQuantity(uuid, current + (allowDecimals ? 0.5 : 1), stock.availableFor(uuid))
}

function decrement(uuid: string, current: number, allowDecimals: boolean): void {
  cartStore.setQuantity(uuid, current - (allowDecimals ? 0.5 : 1))
}
</script>

<template>
  <aside class="pos-cart">
    <div class="pos-cart__header">
      <h2>Venta actual</h2>
      <button
        v-if="!cartStore.isEmpty"
        type="button"
        class="pos-cart__clear"
        @click="cartStore.clear()"
      >
        Vaciar
      </button>
    </div>

    <div class="pos-cart__items">
      <p v-if="cartStore.isEmpty" class="pos-cart__empty">
        El carrito esta vacio.
      </p>

      <ul v-else class="pos-cart__list">
        <li
          v-for="item in cartStore.items"
          :key="item.productUuid"
          class="pos-cart__item"
        >
          <div class="pos-cart__item-main">
            <span class="pos-cart__item-name">{{ item.name }}</span>
            <span class="pos-cart__item-meta">
              {{ formatPrice(item.unitPrice) }} / {{ item.unitSymbol }}
            </span>
          </div>

          <div class="pos-cart__item-qty">
            <button
              type="button"
              class="pos-cart__qty-btn"
              @click="decrement(item.productUuid, item.quantity, item.allowDecimals)"
              aria-label="Disminuir"
            >
              -
            </button>
            <input
              type="number"
              :value="item.quantity"
              :step="item.allowDecimals ? 0.001 : 1"
              :min="0"
              :max="stock.availableFor(item.productUuid) < Infinity ? stock.availableFor(item.productUuid) : undefined"
              class="pos-cart__qty-input"
              @input="onQuantityInput(item.productUuid, $event)"
            />
            <button
              type="button"
              class="pos-cart__qty-btn"
              @click="increment(item.productUuid, item.quantity, item.allowDecimals)"
              aria-label="Aumentar"
            >
              +
            </button>
          </div>

          <div class="pos-cart__item-total">
            {{ formatPrice(item.unitPrice * item.quantity) }}
          </div>

          <button
            type="button"
            class="pos-cart__remove"
            @click="cartStore.remove(item.productUuid)"
            aria-label="Eliminar"
          >
            x
          </button>
        </li>
      </ul>
    </div>

    <div v-if="!cartStore.isEmpty" class="pos-cart__footer">
      <div class="pos-cart__row">
        <span>Subtotal</span>
        <span>{{ formatPrice(cartStore.subtotal) }}</span>
      </div>
      <div class="pos-cart__row">
        <span>IVA</span>
        <span>{{ formatPrice(cartStore.taxTotal) }}</span>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.pos-cart {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--color-background);
  border-left: 1px solid var(--color-border);
}

.pos-cart__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--pos-space-md) var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
}

.pos-cart__header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  color: var(--color-heading);
}

.pos-cart__clear {
  padding: 0.3rem 0.7rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
  background: transparent;
  color: var(--pos-danger);
  font-size: 0.75rem;
  font-family: inherit;
  cursor: pointer;
}

.pos-cart__items {
  flex: 1;
  padding: var(--pos-space-md);
  overflow-y: auto;
}

.pos-cart__empty {
  margin: 0;
  text-align: center;
  color: var(--color-text);
  opacity: 0.5;
  font-size: 0.875rem;
  padding: var(--pos-space-xl) 0;
}

.pos-cart__list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-sm);
}

.pos-cart__item {
  display: grid;
  grid-template-columns: 1fr auto auto auto;
  align-items: center;
  gap: var(--pos-space-sm);
  padding: var(--pos-space-sm);
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-md);
}

.pos-cart__item-main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.pos-cart__item-name {
  color: var(--color-heading);
  font-size: 0.875rem;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pos-cart__item-meta {
  font-size: 0.75rem;
  color: var(--color-text);
  opacity: 0.6;
}

.pos-cart__item-qty {
  display: flex;
  align-items: center;
  gap: 2px;
}

.pos-cart__qty-btn {
  width: 26px;
  height: 26px;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-sm);
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
  font-family: inherit;
  cursor: pointer;
  padding: 0;
}

.pos-cart__qty-input {
  width: 56px;
  padding: 0.25rem 0.4rem;
  border: 1px solid var(--color-border);
  border-radius: var(--pos-radius-sm);
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
  font-family: inherit;
  text-align: center;
  -moz-appearance: textfield;
}

.pos-cart__qty-input::-webkit-outer-spin-button,
.pos-cart__qty-input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.pos-cart__item-total {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-heading);
  white-space: nowrap;
  min-width: 70px;
  text-align: right;
}

.pos-cart__remove {
  width: 26px;
  height: 26px;
  border: none;
  border-radius: var(--pos-radius-sm);
  background: transparent;
  color: var(--pos-danger);
  font-size: 1rem;
  font-family: inherit;
  cursor: pointer;
  padding: 0;
}

.pos-cart__remove:hover {
  background: rgba(255, 0, 0, 0.08);
}

.pos-cart__footer {
  padding: var(--pos-space-md);
  border-top: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
}

.pos-cart__row {
  display: flex;
  justify-content: space-between;
  font-size: 0.875rem;
  color: var(--color-text);
  opacity: 0.85;
}
</style>
