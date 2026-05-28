<script setup lang="ts">
// Placeholder de 3a. La logica de busqueda y catalogo llega en 3b
// (despues de extender el OpenAPI con /products).
</script>

<template>
  <section class="pos-catalog">
    <div class="pos-catalog__search">
      <input
        type="search"
        placeholder="Buscar producto, codigo o codigo de barras..."
        disabled
        aria-label="Buscar producto"
      />
    </div>
    <div class="pos-catalog__empty">
      <p>La busqueda de productos llega en la siguiente pieza.</p>
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

.pos-catalog__search input:disabled {
  opacity: 0.5;
}

.pos-catalog__empty {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--pos-space-xl);
  text-align: center;
  color: var(--color-text);
  opacity: 0.6;
  font-size: 0.875rem;
}
</style>
