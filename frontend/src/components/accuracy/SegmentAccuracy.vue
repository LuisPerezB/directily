<script setup lang="ts">
/**
 * Precisión del modelo servido por horizonte y segmento (V2.5 · W3-A/D; V25-DEM-030..035, V25-HOR-008). Cada fila declara sobre cuánto se calculó
 * (denominador = ventas reales del segmento y días evaluados/excluidos), compara con la línea base y marca la inestabilidad. Nunca una cifra donde
 * el motor no la respalda: un segmento con pocas observaciones no muestra error y dice por qué; un WAPE con denominador insuficiente no se publica
 * como porcentaje y se acompaña de su métrica alternativa. Datos de demostración o sintéticos comprueban el procedimiento, no la precisión comercial.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import { modelQualityApi } from '@/api'
import type { DemandSegmentDimension, ModelQualityEvaluation, SegmentMetricsSummary } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useHorizonSelection } from '@/composables/useHorizons'
import { useCatalogStore } from '@/stores/catalog'
import { DASH, formatNumber, formatPercent } from '@/utils/format'
import { DEMAND_DIMENSIONS, TONE_CLASS, segmentOf, segmentValueLabel, sampleView, verdictTone, wapeView } from '@/utils/modelQuality'

const props = defineProps<{ runId: number | null; summary?: SegmentMetricsSummary | null }>()
const { t, te } = useI18n()
const catalog = useCatalogStore()
const { horizon, options: horizonOptions, hasHorizons } = useHorizonSelection()
const dimension = ref<DemandSegmentDimension>('demand_class')
const page = ref(1)
watch([() => props.runId, dimension, horizon], () => (page.value = 1))

const dimensionOptions = computed(() => DEMAND_DIMENSIONS.map((d) => ({ value: d, label: t(`segments.dimensions.${d}`) })))
const result = useAsync(
  async () => {
    if (props.runId === null) return null
    return modelQualityApi.evaluations({
      core: 'demand', run_id: props.runId, level: 'segment', dimension: dimension.value, horizon: horizon.value,
      evaluation_type: 'backtest', is_selected: true, page: page.value, per_page: 50,
    })
  },
  { watch: [() => props.runId, dimension, horizon, page] },
)
const rows = computed(() => result.data.value?.data ?? [])
const scope = computed<string | null>(() => {
  const s = (rows.value[0]?.extra as { evaluation_scope?: string } | null | undefined)?.evaluation_scope
  return typeof s === 'string' ? s : null
})
const unavailable = computed(() => props.summary?.available === false)
const notEvaluated = computed(() => (props.summary?.horizons_not_evaluated ?? []).includes(horizon.value))
const attention = computed(() => props.summary?.attention_total ?? 0)
const cells = computed(() => props.summary?.counts?.cells ?? 0)

const labelCtx = computed(() => ({
  t: (key: string, named?: Record<string, unknown>) => t(key, named ?? {}),
  te: (key: string) => te(key),
  category: (id: number) => catalog.categories.find((c) => c.id === id)?.name ?? null,
  branch: (id: number) => catalog.branches.find((b) => b.id === id)?.name ?? null,
}))
const segmentLabel = (r: ModelQualityEvaluation): string => {
  const s = segmentOf(r)
  return s ? segmentValueLabel(s.dimension, s.value, labelCtx.value) : (r.level_key ?? DASH)
}
const nf = (v: number | null | undefined, d = 0): string => (v == null ? DASH : formatNumber(v, d))
const baselineWape = (r: ModelQualityEvaluation): number | null => (r.quality?.comparison?.metric === 'wape' ? r.quality.comparison.baseline_value : null)
const reasonText = (r: ModelQualityEvaluation): string[] =>
  (r.quality?.insufficient_reasons ?? []).map((x) => {
    const code = String(x.code)
    return te(`segmentAccuracy.reasons.${code}`) ? t(`segmentAccuracy.reasons.${code}`, x as Record<string, unknown>) : code
  })
function alternativeText(r: ModelQualityEvaluation): string | null {
  const alt = r.quality?.alternative_metric
  if (!alt) return null
  if (alt.name === 'aggregate_absolute_error') return t('segmentAccuracy.alt.aggregate_absolute_error', { value: nf(typeof alt.value === 'number' ? alt.value : null, 1), perDay: nf(typeof alt.per_evaluable_day === 'number' ? alt.per_evaluable_day : null, 2) })
  if (alt.name === 'mase') return t('segmentAccuracy.alt.mase', { value: nf(typeof alt.value === 'number' ? alt.value : null, 2) })
  return null
}
const exclusionText = (r: ModelQualityEvaluation): string => {
  const view = sampleView(r.quality?.sample)
  return (view?.exclusions ?? []).map((e) => `${te(`segmentAccuracy.exclusions.${e.key}`) ? t(`segmentAccuracy.exclusions.${e.key}`) : e.key}: ${nf(e.days)}`).join(' · ')
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('segmentAccuracy.title')" data-test="segment-accuracy">
    <div class="flex flex-wrap items-end gap-4">
      <SegmentedControl v-model="dimension" :options="dimensionOptions" :label="t('segmentAccuracy.dimension')" size="sm" />
      <SegmentedControl v-if="hasHorizons" v-model="horizon" :options="horizonOptions" :label="t('common.horizon')" size="sm" />
    </div>

    <InlineNotice v-if="unavailable" kind="warning" :title="t('segmentAccuracy.unavailableTitle')" data-test="segment-unavailable">{{ t('segmentAccuracy.unavailableText') }}</InlineNotice>
    <p v-else-if="cells > 0" class="text-sm text-fg-muted m-0" data-test="segment-attention">{{ t('segmentAccuracy.attention', { n: attention, cells }) }}</p>

    <LoadingState v-if="result.loading.value && !result.data.value" :lines="3" />
    <ErrorState v-else-if="result.error.value" :error="result.error.value" compact @retry="result.execute" />
    <EmptyState v-else-if="props.runId === null || !rows.length" icon="chart" :title="t('segmentAccuracy.emptyTitle')" :description="notEvaluated ? t('segmentAccuracy.horizonNotEvaluated', { n: horizon }) : t('segmentAccuracy.emptyText')" data-test="segment-empty" />
    <template v-else>
      <p v-if="scope === 'holdout'" class="text-sm text-fg-muted m-0" data-test="segment-scope">{{ t('segmentAccuracy.scopeHoldout') }}</p>
      <InlineNotice v-else-if="scope === 'selection_windows'" kind="warning" data-test="segment-scope">{{ t('segmentAccuracy.scopeSelection') }}</InlineNotice>
      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">{{ t('segmentAccuracy.title') }} · {{ t(`segments.dimensions.${dimension}`) }} · {{ t('common.days', { n: horizon }) }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('segmentAccuracy.columns.segment') }}</th>
              <th scope="col" class="num">{{ t('segmentAccuracy.columns.wape') }}</th>
              <th scope="col" class="num">{{ t('segmentAccuracy.columns.denominator') }}</th>
              <th scope="col" class="num">{{ t('segmentAccuracy.columns.coverage') }}</th>
              <th scope="col" class="num">{{ t('segmentAccuracy.columns.series') }}</th>
              <th scope="col" class="num">{{ t('segmentAccuracy.columns.baseline') }}</th>
              <th scope="col">{{ t('segmentAccuracy.columns.verdict') }}</th>
              <th scope="col">{{ t('segmentAccuracy.columns.stability') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.id" :data-segment="r.level_key" :data-status="r.status ?? undefined">
              <th scope="row" class="font-medium text-left">{{ segmentLabel(r) }}</th>
              <td class="num" data-test="wape">
                <template v-if="wapeView(r).kind === 'figure'">{{ formatPercent((wapeView(r) as { value: number }).value) }}</template>
                <template v-else-if="wapeView(r).kind === 'insufficient'">
                  <span class="badge" data-test="insufficient">{{ t('segmentAccuracy.insufficient') }}</span>
                  <span v-for="(reason, i) in reasonText(r)" :key="i" class="block text-xs text-fg-muted text-right" data-test="insufficient-reason">{{ reason }}</span>
                </template>
                <template v-else-if="wapeView(r).kind === 'unstable'">
                  <span class="badge badge-warning" data-test="unstable" :title="t('segmentAccuracy.unstableHint', { sum: nf(r.quality?.denominator?.sum_actual, 1), min: nf(r.quality?.denominator?.min_required, 0) })">{{ t('segmentAccuracy.unstable') }}</span>
                  <span v-if="alternativeText(r)" class="block text-xs text-fg-muted text-right" data-test="alternative">{{ alternativeText(r) }}</span>
                </template>
                <template v-else-if="wapeView(r).kind === 'undefined'">
                  <span class="badge badge-warning" data-test="undefined" :title="t('segmentAccuracy.undefinedHint')">{{ t('segmentAccuracy.undefined') }}</span>
                  <span v-if="alternativeText(r)" class="block text-xs text-fg-muted text-right" data-test="alternative">{{ alternativeText(r) }}</span>
                </template>
                <template v-else>{{ DASH }}</template>
              </td>
              <td class="num" data-test="denominator">
                <template v-if="r.quality?.denominator?.sum_actual != null">{{ nf(r.quality.denominator.sum_actual, 1) }} <span class="text-xs text-fg-muted">{{ t('segmentAccuracy.units') }}</span></template>
                <template v-else>{{ DASH }}</template>
              </td>
              <td class="num" data-test="coverage" :title="exclusionText(r)">
                <template v-if="sampleView(r.quality?.sample)?.evaluable != null">
                  {{ t('segmentAccuracy.daysOf', { evaluable: nf(sampleView(r.quality?.sample)?.evaluable), total: nf(sampleView(r.quality?.sample)?.total) }) }}
                  <span v-if="(sampleView(r.quality?.sample)?.excluded ?? 0) > 0" class="block text-xs text-fg-muted">{{ t('segmentAccuracy.excluded', { n: nf(sampleView(r.quality?.sample)?.excluded) }) }}</span>
                </template>
                <template v-else>{{ DASH }}</template>
              </td>
              <td class="num">{{ nf(r.quality?.sample?.n_series) }}<span class="block text-xs text-fg-muted">{{ t('segmentAccuracy.windows', { n: r.n_windows }) }}</span></td>
              <td class="num" data-test="baseline">
                <template v-if="r.figures_available && baselineWape(r) != null">{{ formatPercent(baselineWape(r)) }}</template>
                <template v-else>{{ DASH }}</template>
              </td>
              <td>
                <span v-if="r.quality?.comparison?.verdict" class="badge" :class="TONE_CLASS[verdictTone(r.quality.comparison.verdict)]" data-test="verdict">{{ t(`segmentAccuracy.verdict.${r.quality.comparison.verdict}`) }}</span>
                <span v-else class="text-fg-muted">{{ DASH }}</span>
              </td>
              <td data-test="stability">
                <template v-if="!r.figures_available || r.quality?.stability?.wape_cv == null">
                  <span class="text-fg-muted">{{ DASH }}</span>
                </template>
                <template v-else>
                  <span class="badge" :class="r.quality.stability.unstable_across_windows ? 'badge-warning' : 'badge-success'">{{ r.quality.stability.unstable_across_windows ? t('segmentAccuracy.variable') : t('segmentAccuracy.stable') }}</span>
                  <span class="block text-xs text-fg-muted">{{ t('segmentAccuracy.cv', { value: formatPercent(r.quality.stability.wape_cv, 0) }) }}</span>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-model:page="page" :meta="result.data.value?.meta ?? null" />
      <p class="hint m-0">{{ t('segmentAccuracy.denominatorNote') }}</p>
    </template>
  </section>
</template>
