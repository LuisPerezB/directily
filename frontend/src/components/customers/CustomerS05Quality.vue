<script setup lang="ts">
/**
 * Calidad de S05 del Customer Core (V2.5 · W3-C/D): calibración de la probabilidad por segmento (frecuencia, valor, antigüedad e historial) y estado de la
 * validación de la ventana de recompra. Un segmento con pocas filas o compras no muestra cifras de calibración; una ventana no validada no publica rangos
 * (aquí solo se informa el estado y la cobertura medida). Los datos ficticios o de demostración comprueban el procedimiento; no validan precisión comercial.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import { modelQualityApi } from '@/api'
import type { CustomerHorizon, ModelQualityEvaluation } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { DASH, formatInteger, formatNumber, formatPercent } from '@/utils/format'
import { CUSTOMER_DIMENSIONS, TONE_CLASS, segmentOf, segmentValueLabel } from '@/utils/modelQuality'

const props = defineProps<{ coreRunId: number | null; horizon: CustomerHorizon }>()
const { t, te } = useI18n()

const segments = useAsync(
  async () => (props.coreRunId === null ? null : modelQualityApi.evaluations({ core: 'customer', core_run_id: props.coreRunId, level: 'segment', horizon: props.horizon, per_page: 100 })),
  { watch: [() => props.coreRunId, () => props.horizon] },
)
const windowRow = useAsync(
  async () => (props.coreRunId === null ? null : modelQualityApi.evaluations({ core: 'customer', core_run_id: props.coreRunId, level_key: 'window', per_page: 1 })),
  { watch: [() => props.coreRunId] },
)

const groups = computed(() => {
  const rows = segments.data.value?.data ?? []
  return CUSTOMER_DIMENSIONS.map((dimension) => ({ dimension, rows: rows.filter((r) => segmentOf(r)?.dimension === dimension) })).filter((g) => g.rows.length > 0)
})
const ctx = { t: (key: string, named?: Record<string, unknown>) => t(key, named ?? {}), te: (key: string) => te(key) }
const label = (r: ModelQualityEvaluation): string => {
  const s = segmentOf(r)
  return s ? segmentValueLabel(s.dimension, s.value, ctx) : (r.level_key ?? DASH)
}
const statusClass = (s: string | null): string => TONE_CLASS[s === 'calibrated' ? 'success' : s === 'miscalibrated' ? 'warning' : 'neutral']

const windowInfo = computed(() => {
  const r = windowRow.data.value?.data[0] ?? null
  if (!r) return null
  const m = (r.metrics ?? {}) as { status?: string; reason?: string; interval?: { n_relations?: number } }
  const status = typeof m.status === 'string' ? m.status : 'unknown'
  const relations = m.interval?.n_relations
  return { status, reason: typeof m.reason === 'string' ? m.reason : null, coverage: r.coverage_80, relations: typeof relations === 'number' ? relations : null }
})
</script>

<template>
  <div class="flex flex-col gap-5" data-test="customer-s05-quality">
    <div data-test="segment-calibration">
      <h3 class="section-title mb-1">{{ t('customers.s05.segmentsTitle') }}</h3>
      <p class="text-sm text-fg-muted mb-2">{{ t('customers.s05.segmentsText') }}</p>
      <LoadingState v-if="segments.loading.value && !segments.data.value" :lines="2" />
      <ErrorState v-else-if="segments.error.value" :error="segments.error.value" compact @retry="segments.execute" />
      <p v-else-if="!groups.length" class="text-sm text-fg-muted m-0" data-test="segments-empty">{{ t('customers.s05.segmentsEmpty') }}</p>
      <template v-else>
      <div v-for="g in groups" :key="g.dimension" class="mb-3" :data-dimension="g.dimension">
        <h4 class="font-semibold text-sm mb-1">{{ t(`segments.dimensions.${g.dimension}`) }}</h4>
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('customers.s05.segmentsTitle') }} · {{ t(`segments.dimensions.${g.dimension}`) }}</caption>
            <thead>
              <tr>
                <th scope="col">{{ t('segmentAccuracy.columns.segment') }}</th>
                <th scope="col" class="num">n / {{ t('customers.eval.positives') }}</th>
                <th scope="col" class="num">{{ t('customers.s05.observedRate') }}</th>
                <th scope="col" class="num">{{ t('customers.s05.meanPredicted') }}</th>
                <th scope="col" class="num">Brier</th>
                <th scope="col" class="num">{{ t('customers.s05.referenceBrier') }}</th>
                <th scope="col">{{ t('common.status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in g.rows" :key="r.id" :data-segment="r.level_key" :data-status="r.status ?? undefined">
                <th scope="row" class="font-medium text-left">{{ label(r) }}</th>
                <td class="num">{{ r.calibration?.n != null ? formatInteger(r.calibration.n) : DASH }} / {{ r.calibration?.n_positive != null ? formatInteger(r.calibration.n_positive) : DASH }}</td>
                <template v-if="r.figures_available && r.calibration">
                  <td class="num">{{ formatPercent(r.calibration.base_rate, 1) }}</td>
                  <td class="num">{{ formatPercent(r.calibration.mean_predicted, 1) }}</td>
                  <td class="num">{{ r.calibration.brier != null ? formatNumber(r.calibration.brier, 4) : DASH }}</td>
                  <td class="num">{{ r.calibration.reference_brier != null ? formatNumber(r.calibration.reference_brier, 4) : DASH }}</td>
                </template>
                <td v-else colspan="4" class="text-fg-muted text-sm" data-test="segment-insufficient">{{ r.calibration?.reason ?? t('customers.s05.insufficient') }}</td>
                <td><span class="badge" :class="statusClass(r.status)">{{ r.status && te(`customers.s05.status.${r.status}`) ? t(`customers.s05.status.${r.status}`) : DASH }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      </template>
    </div>

    <div v-if="windowInfo" data-test="window-validation">
      <h3 class="section-title mb-1">{{ t('customers.s05.windowTitle') }}</h3>
      <InlineNotice :kind="windowInfo.status === 'validated' ? 'success' : 'warning'" :title="te(`customers.s05.windowStatus.${windowInfo.status}`) ? t(`customers.s05.windowStatus.${windowInfo.status}`) : t('customers.s05.windowStatus.unknown')">
        <span data-test="window-validation-text">{{ te(`customers.s05.windowStatusText.${windowInfo.status}`) ? t(`customers.s05.windowStatusText.${windowInfo.status}`) : t('customers.s05.windowStatusText.unknown') }}</span>
        <template v-if="windowInfo.coverage != null"> {{ t('customers.s05.windowCoverage', { value: formatPercent(windowInfo.coverage, 0) }) }}</template>
        <template v-if="windowInfo.coverage != null && windowInfo.relations != null"> {{ t('customers.s05.windowRelations', { n: formatInteger(windowInfo.relations) }) }}</template>
        <template v-if="windowInfo.reason"> {{ windowInfo.reason }}</template>
      </InlineNotice>
    </div>
  </div>
</template>
