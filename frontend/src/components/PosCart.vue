<script setup lang="ts">
// Placeholder de 3a. La logica del carrito (Pinia store, modificar
// cantidad, calculos, persistencia) llega en 3c.
</script>

<template>
  <aside class="pos-cart">
    <div class="pos-cart__header">
      <h2>Venta actual</h2>
    </div>
    <div class="pos-cart__items">
      <p class="pos-cart__empty">El carrito esta vacio.</p>
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
  padding: var(--pos-space-md) var(--pos-space-lg);
  border-bottom: 1px solid var(--color-border);
}

.pos-cart__header h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  color: var(--color-heading);
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
</style>
