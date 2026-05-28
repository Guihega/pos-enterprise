<script setup lang="ts">
// Placeholder de 3a. El boton "Cobrar" abrira el modal de cobro
// multi-metodo en 3e. El total se conectara al store del carrito
// en 3c.
const total = 0
const itemCount = 0
</script>

<template>
  <footer class="pos-checkout">
    <div class="pos-checkout__summary">
      <span class="pos-checkout__count">{{ itemCount }} item(s)</span>
      <span class="pos-checkout__total">$ {{ total.toFixed(2) }}</span>
    </div>
    <button
      type="button"
      class="pos-checkout__btn"
      :disabled="itemCount === 0"
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
</style>
