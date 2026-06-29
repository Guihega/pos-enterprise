<script setup lang="ts">
import { RouterView } from 'vue-router'
import SyncStatusBanner from '@/components/SyncStatusBanner.vue'
import PwaUpdateBanner from '@/components/PwaUpdateBanner.vue'
import IntegrityRecoveryModal from '@/components/IntegrityRecoveryModal.vue'
import ClockDriftAlert from '@/components/ClockDriftAlert.vue'
import { useIntegrityStore } from '@/stores/integrity'

const integrity = useIntegrityStore()

function onRestored(): void {
  integrity.clearCorrupt()
}
</script>

<template>
  <PwaUpdateBanner />
  <SyncStatusBanner />
  <RouterView />
  <ClockDriftAlert />
  <IntegrityRecoveryModal v-if="integrity.isCorrupt" @restored="onRestored" />
</template>
