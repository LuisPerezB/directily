<script setup lang="ts">
/**
 * Shadow mode (V2.5 · W3-B/D, §8): el mejor candidato NO servido predice el horizonte sin servirse y, cuando pasa el horizonte, se evalúa contra lo realmente
 * vendido con la MISMA censura de días que el modelo servido (quiebre, fuente ausente y cierre no son ceros). Mientras no haya ventas posteriores al corte que
 * comparar el estado es «pendiente» y no se muestra ninguna cifra. Nunca se muestran los valores pronosticados.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { modelQualityApi } from '@/api'
import type { ModelQualityEvaluation, ShadowForecastSummary } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { DASH, formatChange, formatDate, formatNumber, formatPercent } from '@/utils/format'

const props = defineProps<{ runId: number | null }>()
const { t, te } = useI18n()

const result = useAsync(
  async () => (props.runId === null ? null : modelQualityApi.shadowEvaluations({ run_id: props.runId, level: 'overall', per_page: 50 })),
  { watch: [() => props.runId] },
)
const families = computed<ShadowForecastSummary[]>(() => result.data.value?.shadow_forecasts ?? [])
const overallOf = (family: string): ModelQualityEvaluation | null => result.data.value?.data.find((e) => e.model_family === family && e.level === 'overall') ?? null
const unstableHint = (e: ModelQualityEvaluation): string => {
  const sh = e.shadow
  return sh?.wape_status === 'undefined'
    ? t('shadow.undefinedHint')
    : t('shadow.unstableHint', { sum: formatNumber(sh?.denominator?.sum_actual ?? null, 1), min: formatNumber(sh?.denominator?.min_required ?? null, 0) })
}
const familyText = (f: string): string => (te(`models.${f}`) ? t(`models.${f}`) : f)
/** Causas por las que un día-serie no se evalúa (el resto de `exclusions` son totales: series-día y evaluados). */
const EXCLUSION_CAUSES = ['stockout_days', 'missing_source_days', 'closed_days'] as const
const exclusionText = (e: ModelQualityEvaluation | null): string =>
  EXCLUSION_CAUSES.map((key) => ({ key, days: e?.shadow?.exclusions?.[key] ?? 0 })).filter((x) => typeof x.days === 'number' && x.days > 0)
    .map((x) => `${t(`segmentAccuracy.exclusions.${x.key}`)}: ${formatNumber(x.days, 0)}`).join(' · ')
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('shadow.title')" data-test="shadow-evaluations">
    <LoadingState v-if="result.loading.value && !result.data.value" :lines="2" />
    <ErrorState v-else-if="result.error.value" :error="result.error.value" compact @retry="result.execute" />
    <EmptyState v-else-if="props.runId === null || !families.length" icon="layers" :title="t('shadow.emptyTitle')" :description="t('shadow.emptyText')" data-test="shadow-empty" />
    <template v-else>
      <p class="text-sm m-0" data-test="shadow-note">{{ t('shadow.note') }}</p>
      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">{{ t('shadow.title') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('shadow.columns.challenger') }}</th>
              <th scope="col">{{ t('common.status') }}</th>
              <th scope="col" class="num">{{ t('shadow.columns.predictions') }}</th>
              <th scope="col" class="num">{{ t('shadow.columns.evaluated') }}</th>
              <th scope="col" class="num">{{ t('shadow.columns.denominator') }}</th>
              <th scope="col" class="num">{{ t('shadow.columns.wape') }}</th>
              <th scope="col" class="num">{{ t('shadow.columns.served') }}</th>
              <th scope="col" class="num">{{ t('shadow.columns.bias') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in families" :key="`${f.forecast_run_id}-${f.model_family}`" :data-family="f.model_family" :data-status="f.status">
              <th scope="row" class="font-medium text-left">{{ familyText(f.model_family) }}<span class="block text-xs text-fg-muted">{{ t('shadow.notServed') }}</span></th>
              <td>
                <span class="badge" :class="f.status === 'evaluated' ? 'badge-success' : 'badge-warning'" data-test="shadow-status">{{ t(`shadow.status.${f.status}`) }}</span>
              </td>
              <td class="num" data-test="shadow-predicted">
                {{ formatNumber(f.n_values, 0) }}
                <span v-if="f.first_target_date && f.last_target_date" class="block text-xs text-fg-muted">{{ t('shadow.predictedRange', { from: formatDate(f.first_target_date), to: formatDate(f.last_target_date) }) }}</span>
              </td>
              <template v-if="f.status === 'evaluated' && overallOf(f.model_family)">
                <td class="num" data-test="shadow-evaluated" :title="exclusionText(overallOf(f.model_family))">
                  <template v-if="overallOf(f.model_family)!.shadow?.evaluated.first_date && overallOf(f.model_family)!.shadow?.evaluated.last_date">
                    {{ formatDate(overallOf(f.model_family)!.shadow!.evaluated.first_date) }} – {{ formatDate(overallOf(f.model_family)!.shadow!.evaluated.last_date) }}
                    <span v-if="overallOf(f.model_family)!.shadow!.evaluated.n_days != null" class="block text-xs text-fg-muted">{{ t('shadow.evaluatedDays', { n: formatNumber(overallOf(f.model_family)!.shadow!.evaluated.n_days, 0) }) }}</span>
                  </template>
                  <span v-else class="text-fg-muted text-sm">{{ t('shadow.evaluatedUnknown') }}</span>
                  <span v-if="overallOf(f.model_family)!.shadow?.evaluated.n_series_days != null" class="block text-xs text-fg-muted">{{ t('shadow.evaluatedSeriesDays', { n: formatNumber(overallOf(f.model_family)!.shadow!.evaluated.n_series_days, 0) }) }}</span>
                  <span v-if="exclusionText(overallOf(f.model_family))" class="block text-xs text-fg-muted">{{ t('shadow.censored') }}</span>
                </td>
                <td class="num" data-test="shadow-denominator">
                  <template v-if="overallOf(f.model_family)!.shadow?.denominator?.sum_actual != null">{{ formatNumber(overallOf(f.model_family)!.shadow!.denominator!.sum_actual, 1) }} <span class="text-xs text-fg-muted">{{ t('segmentAccuracy.units') }}</span></template>
                  <template v-else>{{ DASH }}</template>
                </td>
                <td v-if="overallOf(f.model_family)!.shadow?.wape_unstable" colspan="3" class="text-sm" data-test="shadow-unstable">
                  <span class="badge badge-warning" :title="unstableHint(overallOf(f.model_family)!)">{{ overallOf(f.model_family)!.shadow!.wape_status === 'undefined' ? t('shadow.undefined') : t('shadow.unstable') }}</span>
                  <span class="block text-xs text-fg-muted">{{ unstableHint(overallOf(f.model_family)!) }}</span>
                </td>
                <template v-else>
                  <td class="num" data-test="shadow-wape">{{ formatPercent(overallOf(f.model_family)!.wape) }}</td>
                  <td class="num" data-test="shadow-served">
                    {{ formatPercent(overallOf(f.model_family)!.shadow?.served_wape_same_series_days ?? null) }}
                    <span v-if="overallOf(f.model_family)!.shadow?.relative_improvement_vs_served != null" class="block text-xs text-fg-muted">{{ t('shadow.improvement', { value: formatChange(overallOf(f.model_family)!.shadow!.relative_improvement_vs_served) }) }}</span>
                  </td>
                  <td class="num">{{ formatChange(overallOf(f.model_family)!.bias) }}</td>
                </template>
              </template>
              <td v-else colspan="5" class="text-fg-muted text-sm" data-test="shadow-pending">{{ t('shadow.pendingText') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="hint m-0">{{ t('shadow.sameDaysNote') }}</p>
    </template>
  </section>
</template>
