<script setup lang="ts">
import PosHeader from '@/components/PosHeader.vue'
import PosCatalog from '@/components/PosCatalog.vue'
import PosCart from '@/components/PosCart.vue'
import PosCheckoutBar from '@/components/PosCheckoutBar.vue'
import type { Product } from '@/lib/api/generated'

/**
 * Recibe productos seleccionados desde PosCatalog.
 *
 * En 3b solo logueamos; en 3c (store de carrito) conectaremos esto a
 * `cartStore.add(product)`.
 */
function onProductSelected(product: Product): void {
   
  console.log('[POS] producto seleccionado:', product.uuid, product.name)
}
</script>

<template>
  <div class="pos-shell">
    <PosHeader />
    <main class="pos-main">
      <PosCatalog @product-selected="onProductSelected" />
      <PosCart />
    </main>
    <PosCheckoutBar />
  </div>
</template>

<style scoped>
.pos-shell {
  display: grid;
  grid-template-rows: auto 1fr auto;
  min-height: 100vh;
}

.pos-main {
  display: grid;
  grid-template-columns: 1fr 380px;
  min-height: 0;
}
</style>
