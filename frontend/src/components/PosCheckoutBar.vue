<script setup lang="ts">
import { useCartStore } from '@/stores/cart'
import { formatPrice } from '@/lib/format'

const cartStore = useCartStore()

const emit = defineEmits<{
  (e: 'checkout'): void
  (e: 'open-cart'): void
}>()

function onOpenCart(): void {
  emit('open-cart')
}


function onCheckout(): void {
  emit('checkout')
}
</script>

<template>
  <footer class="pos-checkout">
    <button
      type="button"
      class="pos-checkout__cart-btn"
      aria-label="Ver carrito"
      @click="onOpenCart"
    >
      <span class="pos-checkout__cart-icon">🛒</span>
      <span v-if="cartStore.lineCount > 0" class="pos-checkout__cart-badge">{{ cartStore.lineCount }}</span>
    </button>
    <div class="pos-checkout__summary">
      <span class="pos-checkout__count">
        {{ cartStore.lineCount }} item(s)
      </span>
      <span class="pos-checkout__total">
        {{ formatPrice(cartStore.grandTotal) }}
      </span>
    </div>
    <button
      type="button"
      class="pos-checkout__btn"
      :disabled="cartStore.isEmpty"
      @click="onCheckout"
    >
      Cobrar
    </button>
  </footer>
</template>

<style scoped>
.pos-checkout {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--pos-space-md);
  padding: var(--pos-space-md) var(--pos-space-lg);
  border-top: 1px solid var(--color-border);
  background: var(--color-background);
}

.pos-checkout__summary {
  display: flex;
  flex-direction: column;
  gap: var(--pos-space-xs);
}

.pos-checkout__count {
  font-size: 0.75rem;
  color: var(--color-text);
  opacity: 0.6;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.pos-checkout__total {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--color-heading);
}

.pos-checkout__btn {
  padding: 0.85rem 1.75rem;
  border: none;
  border-radius: var(--pos-radius-md);
  background: var(--pos-accent);
  color: var(--pos-accent-text);
  font-size: 1rem;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  min-width: 140px;
}

.pos-checkout__btn:hover:not(:disabled) {
  background: var(--pos-accent-hover);
}

.pos-checkout__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.pos-checkout__cart-btn {
  display: none;
  position: relative;
  width: 48px;
  height: 48px;
  border: none;
  border-radius: 50%;
  background: var(--color-background-soft, var(--color-background));
  border: 1px solid var(--color-border);
  cursor: pointer;
  align-items: center;
  justify-content: center;
  font-size: 1.4rem;
  flex-shrink: 0;
}

.pos-checkout__cart-badge {
  position: absolute;
  top: -4px;
  right: -4px;
  background: var(--pos-accent);
  color: #fff;
  font-size: 0.65rem;
  font-weight: 700;
  min-width: 18px;
  height: 18px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 4px;
}

@media (max-width: 767px) {
  .pos-checkout__cart-btn {
    display: flex;
  }
}
</style>
