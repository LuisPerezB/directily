<script setup lang="ts">
/**
 * Ventana probable de recompra (V2.5 · W3-C/D, ADR-V25-11): un RANGO de días hasta la siguiente compra (P10–P90, con la mediana), nunca un día exacto.
 * Solo se muestran cifras cuando el estado las respalda (`estimated`, `overdue`); con `insufficient_data` (historial o validación insuficientes),
 * `censored` (producto sin existencias) o sin estimación solo se muestra el estado: la demo no valida la ventana y por eso no publica ninguna cifra.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CustomerWindow } from '@/api/types'
import { formatNumber } from '@/utils/format'
import { windowView } from '@/utils/customers'

const props = defineProps<{ window: CustomerWindow | null | undefined; cause?: 'failed' | 'unavailable' | null }>()
const { t } = useI18n()
const view = computed(() => windowView(props.window))
</script>

<template>
  <div data-test="window" :data-window-status="window?.status ?? 'unavailable'">
    <template v-if="view.kind === 'range'">
      <span class="font-semibold" data-test="window-range">{{ t('customers.window.range', { p10: formatNumber(view.p10, 0), p90: formatNumber(view.p90, 0) }) }}</span>
      <span v-if="view.p50 != null" class="block text-xs text-fg-muted" data-test="window-median">{{ t('customers.window.median', { p50: formatNumber(view.p50, 0) }) }}</span>
      <span v-if="view.overdue" class="badge badge-warning" data-test="window-overdue" :title="t('customers.window.overdueHint')">{{ t('customers.window.overdue') }}</span>
    </template>
    <template v-else>
      <span class="text-fg-muted text-sm" data-test="window-state" :title="cause ? t(`customers.s05.causeHint.${cause}`) : t(`customers.window.stateHint.${view.status}`)">{{ t(`customers.window.state.${view.status}`) }}</span>
      <span v-if="cause && view.status === 'unavailable'" class="block text-xs text-fg-muted" data-test="window-cause">{{ t(`customers.s05.cause.${cause}`) }}</span>
    </template>
  </div>
</template>
