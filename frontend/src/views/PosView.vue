<script setup lang="ts">
import PosHeader from '@/components/PosHeader.vue'
import PosCatalog from '@/components/PosCatalog.vue'
import PosCart from '@/components/PosCart.vue'
import PosCheckoutBar from '@/components/PosCheckoutBar.vue'
import { useCartStore } from '@/stores/cart'
import type { Product } from '@/lib/api/generated'

const cartStore = useCartStore()

function onProductSelected(product: Product): void {
  cartStore.add(product)
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
  /* Altura fija de viewport + overflow hidden: el shell ocupa exacto
     el alto del viewport y NO scrollea. Quien scrollea es el contenido
     interno (catalogo y carrito por separado). Asi el header y el
     PosCheckoutBar siempre quedan visibles. */
  height: 100vh;
  overflow: hidden;
}

.pos-main {
  display: grid;
  grid-template-columns: 1fr 380px;
  /* min-height: 0 en grid items es necesario para que los hijos puedan
     hacer overflow internamente sin empujar al padre. */
  min-height: 0;
  overflow: hidden;
}
</style>
