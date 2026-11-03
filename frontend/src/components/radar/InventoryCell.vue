<script setup lang="ts">
/** Disponibilidad al corte (existencias − reservado). Sin fotografía vigente NO es cero: se dice «sin dato de inventario». El tránsito va aparte. */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RadarInventory } from '@/api/types'
import { formatNumber } from '@/utils/format'
import { inventoryView } from '@/utils/radar'

const props = defineProps<{ inventory: RadarInventory | null | undefined; unit?: string | null }>()
const { t } = useI18n()
const view = computed(() => inventoryView(props.inventory))
</script>

<template>
  <div data-test="inventory" :data-status="view.status">
    <template v-if="view.status === 'known'">
      <span class="font-semibold tabular" data-test="inventory-available">{{ view.available !== null ? t('radar.inventory.available', { n: formatNumber(view.available, 1), unit: unit ?? '' }) : t('enterprise.notEstimated') }}</span>
      <span v-if="view.inTransit" class="block text-xs text-fg-muted" data-test="inventory-transit">{{ t('radar.inventory.inTransit', { n: formatNumber(view.inTransit, 1) }) }}</span>
    </template>
    <span v-else-if="view.status === 'not_applicable'" class="text-fg-muted text-sm">{{ t('radar.inventory.notApplicable') }}</span>
    <span v-else class="text-fg-muted text-sm" data-test="inventory-unknown" :title="view.reason ?? undefined">{{ t('radar.inventory.unknown') }}</span>
  </div>
</template>
