<script setup lang="ts">
import { RouterView } from 'vue-router'
import SyncStatusBanner from '@/components/SyncStatusBanner.vue'
import IntegrityRecoveryModal from '@/components/IntegrityRecoveryModal.vue'
import { useIntegrityStore } from '@/stores/integrity'

const integrity = useIntegrityStore()

function onRestored(): void {
  integrity.clearCorrupt()
}
</script>

<template>
  <SyncStatusBanner />
  <RouterView />
  <IntegrityRecoveryModal v-if="integrity.isCorrupt" @restored="onRestored" />
</template>
