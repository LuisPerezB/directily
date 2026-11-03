<script setup lang="ts">
/**
 * Embudo de oportunidades: identificadas → accionables → atendidas → medidas, con la tasa de acción y la de respuesta. La respuesta es ASOCIACIÓN temporal, no efecto:
 * se atiende a los casos más fáciles. Lo aún no medible es «previsto» y no tiene cifras de resultado. NO muestra cuántas se retienen para medición.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RoiFunnel } from '@/api/types'
import { formatInteger, formatPercent } from '@/utils/format'
import { funnelCounts } from '@/utils/outcomes'

/** `canApprove`: quien aprueba ve todas las medidas; el resto, solo las que puede listar (la API no le entrega ni se derivan las retenidas). */
const props = withDefaults(defineProps<{ funnel: RoiFunnel; canApprove?: boolean }>(), { canApprove: false })
const { t } = useI18n()
const pending = computed(() => props.funnel.forecast_pending ?? {})
const counts = computed(() => funnelCounts(props.funnel, props.canApprove))
</script>

<template>
  <section class="flex flex-col gap-3" :aria-label="t('roi.funnel.title')" data-test="funnel">
    <h2 class="section-title m-0">{{ t('roi.funnel.title') }}</h2>
    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 m-0">
      <div class="rounded-xl bg-surface-2 p-3"><dt class="text-sm text-fg-muted">{{ t('roi.funnel.identified') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="identified">{{ formatInteger(counts.identified) }}</dd></div>
      <div class="rounded-xl bg-surface-2 p-3"><dt class="text-sm text-fg-muted">{{ t('roi.funnel.attended') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="attended">{{ formatInteger(funnel.attended) }}</dd></div>
      <div class="rounded-xl bg-surface-2 p-3"><dt class="text-sm text-fg-muted">{{ t('roi.funnel.measured') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="measured">{{ formatInteger(counts.measured) }}</dd></div>
      <div class="rounded-xl bg-surface-2 p-3"><dt class="text-sm text-fg-muted">{{ t('roi.funnel.actionRate') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="action-rate">{{ funnel.action_rate === null ? t('enterprise.notEstimatedF') : formatPercent(funnel.action_rate, 1) }}</dd></div>
      <div class="rounded-xl bg-surface-2 p-3"><dt class="text-sm text-fg-muted">{{ t('roi.funnel.responseRate') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="response-rate">{{ funnel.response_rate === null ? t('enterprise.notEstimatedF') : formatPercent(funnel.response_rate, 1) }}</dd><dd class="m-0 text-xs text-fg-muted">{{ t('roi.funnel.responseNote') }}</dd></div>
    </dl>
    <p class="m-0 text-sm text-fg-muted" data-test="forecast-pending">{{ t('roi.funnel.pending', { open: formatInteger(pending.window_open ?? 0), settling: formatInteger(pending.settling ?? 0), ready: formatInteger(pending.measurable_not_yet_measured ?? 0) }) }}</p>
  </section>
</template>
