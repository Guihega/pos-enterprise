<script setup lang="ts">
import PosHeader from '@/components/PosHeader.vue'
import PosCatalog from '@/components/PosCatalog.vue'
import PosCart from '@/components/PosCart.vue'
import PosCheckoutBar from '@/components/PosCheckoutBar.vue'
</script>

<template>
  <div class="pos-shell">
    <PosHeader />
    <main class="pos-main">
      <PosCatalog />
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
