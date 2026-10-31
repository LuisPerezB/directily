<script setup lang="ts">
/**
 * Evaluación del Customer Core en el período de prueba final (holdout): Brier, log loss y PR-AUC frente a la referencia,
 * curva de calibración con n por grupo, precision@k/recall@k del ranking, error de cantidad y monto, y el manifiesto
 * del protocolo (fechas de entrenamiento, calibración, selección y prueba; maduración de etiquetas).
 * Datos ficticios o de demostración comprueban el procedimiento; no validan precisión comercial.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import CalibrationChart from '@/components/charts/CalibrationChart.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import { customerApi } from '@/api'
import type { CustomerEvaluationRow, CustomerHorizon, ProbabilityMetrics, RankingMetrics } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { evaluationKey, horizonNotAvailable, rankingAtK, RANKING_KS } from '@/utils/customers'
import { customerBlockRows, tierAbstains, tierIsFullModel } from '@/utils/manifest'
import { formatChange, formatDate, formatInteger, formatNumber, formatPercent } from '@/utils/format'

const props = defineProps<{ horizon: CustomerHorizon }>()
const { t, te } = useI18n()
const result = useAsync(() => customerApi.evaluation({ horizon: props.horizon }), { watch: [() => props.horizon] })
const unavailable = computed(() => horizonNotAvailable(result.error.value))
const data = computed(() => result.data.value?.data ?? null)
const envelope = computed(() => result.data.value?.envelope ?? null)
const key = computed(() => String(props.horizon))
const manifestH = computed(() => data.value?.manifest?.horizons?.[key.value] ?? null)
const blocks = computed(() => customerBlockRows(manifestH.value))
const metrics = computed(() => data.value?.metrics ?? {})
const hasMetrics = computed(() => Object.keys(metrics.value).length > 0)

const probRows = computed(() => (metrics.value[evaluationKey(props.horizon, 'probability')] ?? []).map((r) => ({ row: r, m: r.metrics as unknown as ProbabilityMetrics })))
const reference = computed(() => probRows.value.find((r) => r.row.is_baseline) ?? null)
const served = computed(() => probRows.value.find((r) => r.row.is_selected) ?? null)

// Curva a mostrar: la del modelo servido por defecto; se puede cambiar a cualquier otro evaluado.
const curveFamily = ref<string | null>(null)
watch(probRows, (rows) => { curveFamily.value = (rows.find((r) => r.row.is_selected) ?? rows[0])?.row.model_family ?? null }, { immediate: true })
const curve = computed(() => probRows.value.find((r) => r.row.model_family === curveFamily.value)?.m.calibration_curve ?? [])

const quantityKeys = ['quantity_if_purchase', 'expected_quantity', 'expected_amount'] as const
const quantityBlocks = computed(() =>
  quantityKeys
    .map((k) => ({ k, rows: metrics.value[evaluationKey(props.horizon, k)] ?? [] }))
    .filter((b) => b.rows.length > 0),
)
const ranking = computed(() => (metrics.value[evaluationKey(props.horizon, 'ranking')]?.[0]?.metrics ?? null) as unknown as RankingMetrics | null)
const rankingColumns = computed(() => {
  const r = ranking.value
  if (!r) return []
  return [
    { id: 'served', label: t('customers.eval.rankingServed'), m: r.served },
    { id: 'popularity', label: t('rankingSources.popularity'), m: r.popularity },
    { id: 'recency', label: t('rankingSources.recency'), m: r.recency },
    ...(r.challenger_not_promoted ? [{ id: 'challenger', label: t('customers.eval.rankingChallenger'), m: r.challenger_not_promoted }] : []),
  ]
})
const inactivity = computed(() => metrics.value.inactivity?.[0]?.metrics as Record<string, unknown> | undefined)
const frequency = computed(() => metrics.value.frequency?.[0]?.metrics as Record<string, unknown> | undefined)

function familyText(r: CustomerEvaluationRow): string {
  return te(`customerModels.${r.model_family}`) ? t(`customerModels.${r.model_family}`) : r.model_family
}
function metricOf(r: CustomerEvaluationRow, name: string): number | null {
  const direct = (r as unknown as Record<string, unknown>)[name]
  if (typeof direct === 'number') return direct
  const inner = r.metrics?.[name]
  return typeof inner === 'number' ? inner : null
}
const maeOf = (r: CustomerEvaluationRow): number | null => metricOf(r, 'mae')
const biasOf = (r: CustomerEvaluationRow): number | null => metricOf(r, 'bias')
const num = (v: unknown, d = 4): string => (typeof v === 'number' ? formatNumber(v, d) : '—')
const diff = (a: number | null | undefined, b: number | null | undefined): string => (a == null || b == null ? '—' : formatNumber(a - b, 4))
</script>

<template>
  <section class="flex flex-col gap-5" :aria-label="t('customers.tabs.evaluation')" data-test="customer-evaluation">
    <ErrorState v-if="result.error.value && !unavailable" :error="result.error.value" @retry="result.execute" />
    <EmptyState v-else-if="unavailable" icon="target" :title="t('customers.horizonUnavailableTitle', { n: horizon })" :description="t('customers.horizonUnavailableText')" />
    <EmptyState v-else-if="result.loaded.value && !data?.manifest" icon="target" :title="t('customers.eval.noneTitle')" :description="t('customers.eval.noneText')" data-test="evaluation-abstention" />
    <template v-else-if="data">
      <InlineNotice kind="warning" :title="t('customers.eval.honestyTitle')">{{ t('customers.eval.honesty') }}</InlineNotice>

      <div v-if="manifestH" class="rounded-xl border border-line p-4 flex flex-col gap-3" data-test="customer-manifest">
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="section-title m-0">{{ t('customers.eval.protocolTitle', { n: horizon }) }}</h3>
          <span class="badge" :class="tierIsFullModel(manifestH.tier) ? 'badge-success' : tierAbstains(manifestH.tier) ? 'badge-danger' : 'badge-warning'" data-test="tier">{{ t(`customers.eval.tier.${manifestH.tier}`) }}</span>
        </div>
        <p class="text-sm m-0">{{ t(`customers.eval.tierText.${manifestH.tier}`) }}<template v-if="manifestH.reason"> {{ manifestH.reason }}</template></p>
        <div v-if="blocks.length" class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('customers.eval.protocolTitle', { n: horizon }) }}</caption>
            <thead><tr><th scope="col">{{ t('customers.eval.block') }}</th><th scope="col">{{ t('customers.eval.cutoffs') }}</th><th scope="col">{{ t('customers.eval.labelWindow') }}</th><th scope="col" class="num">{{ t('customers.eval.rows') }}</th><th scope="col" class="num">{{ t('customers.eval.positives') }}</th></tr></thead>
            <tbody>
              <tr v-for="b in blocks" :key="b.key" :data-block="b.key">
                <td><span class="font-medium">{{ t(`customers.eval.blocks.${b.key}`) }}</span></td>
                <td>{{ formatDate(b.cutoffFrom) }} – {{ formatDate(b.cutoffTo) }} <span class="text-xs text-fg-muted">({{ b.nCutoffs }})</span></td>
                <td>{{ formatDate(b.labelFrom) }} – {{ formatDate(b.labelTo) }}</td>
                <td class="num">{{ b.rows != null ? formatInteger(b.rows) : '—' }}</td>
                <td class="num">{{ b.positives != null ? formatInteger(b.positives) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 m-0">
          <div v-if="manifestH.labels_mature_through || data.manifest?.labels_mature_through"><dt class="text-fg-muted">{{ t('customers.eval.maturity') }}</dt><dd class="m-0">{{ t('customers.eval.maturityText', { date: formatDate(manifestH.labels_mature_through ?? data.manifest?.labels_mature_through) }) }}</dd></div>
          <div v-if="manifestH.blocks?.gap_days_between_blocks != null"><dt class="text-fg-muted">{{ t('customers.eval.gap') }}</dt><dd class="m-0">{{ t('customers.eval.gapText', { n: manifestH.blocks.gap_days_between_blocks }) }}</dd></div>
          <div v-if="manifestH.holdout_not_used_for?.length"><dt class="text-fg-muted">{{ t('customers.eval.holdoutNotUsed') }}</dt><dd class="m-0">{{ manifestH.holdout_not_used_for.join(', ') }}</dd></div>
          <div v-if="manifestH.overlap_note"><dt class="text-fg-muted">{{ t('manifest.overlap') }}</dt><dd class="m-0">{{ manifestH.overlap_note }}</dd></div>
        </dl>
        <ul v-if="manifestH.promotion" class="list-none p-0 m-0 flex flex-col gap-1 text-sm" data-test="promotion">
          <li v-for="(p, objective) in manifestH.promotion" :key="objective">
            <span class="badge" :class="p.passed ? 'badge-success' : ''">{{ p.passed ? t('customers.eval.promoted') : t('customers.eval.notPromoted') }}</span>
            {{ t(`customers.eval.objectives.${objective}`) }} · {{ t('customers.eval.challenger', { name: p.challenger ?? '—' }) }} · {{ p.rule_version }}
          </li>
        </ul>
      </div>

      <EmptyState v-if="!hasMetrics" icon="target" :title="t('customers.eval.noMetricsTitle')" :description="t('customers.eval.noMetricsText')" compact data-test="no-metrics" />

      <div v-if="probRows.length" data-test="probability-metrics">
        <h3 class="section-title mb-1">{{ t('customers.eval.probabilityTitle') }}</h3>
        <p class="text-sm text-fg-muted mb-2">{{ t('customers.eval.probabilityText') }}</p>
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('customers.eval.probabilityTitle') }}</caption>
            <thead>
              <tr>
                <th scope="col">{{ t('customers.model') }}</th>
                <th scope="col" class="num">Brier</th>
                <th scope="col" class="num">Log loss</th>
                <th scope="col" class="num">PR-AUC</th>
                <th scope="col" class="num">{{ t('customers.eval.prAucVsRef') }}</th>
                <th scope="col" class="num">ECE</th>
                <th scope="col" class="num">{{ t('customers.eval.baseRate') }}</th>
                <th scope="col" class="num">n / {{ t('customers.eval.positives') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in probRows" :key="p.row.model_family">
                <td>{{ familyText(p.row) }}
                  <span v-if="p.row.is_baseline" class="badge ml-1">{{ t('accuracy.baseline') }}</span>
                  <span v-if="p.row.is_selected" class="badge badge-success ml-1">{{ t('customers.eval.served') }}</span>
                </td>
                <td class="num">{{ num(p.m.brier, 5) }}</td>
                <td class="num">{{ num(p.m.log_loss, 4) }}</td>
                <td class="num">{{ num(p.m.pr_auc, 4) }}</td>
                <td class="num">{{ p.row.is_baseline ? '—' : diff(p.m.pr_auc, reference?.m.pr_auc) }}</td>
                <td class="num">{{ num(p.m.ece, 4) }}</td>
                <td class="num">{{ p.m.base_rate != null ? formatPercent(p.m.base_rate, 2) : '—' }}</td>
                <td class="num">{{ formatInteger(p.m.n) }} / {{ formatInteger(p.m.n_positive) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="served?.m.brier_skill_vs_climatology != null" class="hint mt-1" data-test="brier-skill">{{ t('customers.eval.brierSkill', { value: formatChange(served.m.brier_skill_vs_climatology, 2) }) }}</p>

        <h3 class="section-title mt-5 mb-1">{{ t('customers.eval.calibrationTitle') }}</h3>
        <p class="text-sm text-fg-muted mb-2">{{ t('customers.eval.calibrationText') }}</p>
        <div v-if="probRows.length > 1" class="mb-2">
          <label class="label" for="ce-curve">{{ t('customers.eval.curveOf') }}</label>
          <select id="ce-curve" v-model="curveFamily" class="select !w-auto">
            <option v-for="p in probRows" :key="p.row.model_family" :value="p.row.model_family">{{ familyText(p.row) }}</option>
          </select>
        </div>
        <CalibrationChart :bins="curve" :title="t('customers.eval.calibrationTitle')" />
      </div>

      <div v-if="ranking" data-test="ranking-metrics">
        <h3 class="section-title mb-1">{{ t('customers.eval.rankingTitle') }}</h3>
        <p class="text-sm text-fg-muted mb-2">{{ ranking.definition ?? t('customers.eval.rankingText') }}</p>
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('customers.eval.rankingTitle') }}</caption>
            <thead><tr><th scope="col">{{ t('customers.eval.source') }}</th><th v-for="k in RANKING_KS" :key="`p${k}`" scope="col" class="num">precision@{{ k }}</th><th v-for="k in RANKING_KS" :key="`r${k}`" scope="col" class="num">recall@{{ k }}</th></tr></thead>
            <tbody>
              <tr v-for="c in rankingColumns" :key="c.id">
                <td>{{ c.label }}</td>
                <td v-for="k in RANKING_KS" :key="`p${k}`" class="num">{{ rankingAtK(c.m, 'precision', k) != null ? formatPercent(rankingAtK(c.m, 'precision', k), 1) : '—' }}</td>
                <td v-for="k in RANKING_KS" :key="`r${k}`" class="num">{{ rankingAtK(c.m, 'recall', k) != null ? formatPercent(rankingAtK(c.m, 'recall', k), 1) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="ranking.selection" class="hint mt-1" data-test="ranking-decision">{{ t('customers.eval.rankingDecision', { source: t(`rankingSources.${ranking.selection.source}`), reason: ranking.selection.reason }) }}</p>
      </div>

      <div v-for="b in quantityBlocks" :key="b.k" :data-test="`qty-${b.k}`">
        <h3 class="section-title mb-1">{{ t(`customers.eval.quantity.${b.k}.title`) }}</h3>
        <p class="text-sm text-fg-muted mb-2">{{ t(`customers.eval.quantity.${b.k}.text`) }}</p>
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t(`customers.eval.quantity.${b.k}.title`) }}</caption>
            <thead><tr><th scope="col">{{ t('customers.model') }}</th><th scope="col" class="num">MAE</th><th scope="col" class="num">{{ t('accuracy.bias') }}</th><th scope="col" class="num">n</th></tr></thead>
            <tbody>
              <tr v-for="r in b.rows" :key="r.model_family">
                <td>{{ familyText(r) }}<span v-if="r.is_baseline" class="badge ml-1">{{ t('accuracy.baseline') }}</span><span v-if="r.is_selected" class="badge badge-success ml-1">{{ t('customers.eval.served') }}</span></td>
                <td class="num">{{ num(maeOf(r), 3) }}</td>
                <td class="num">{{ formatChange(biasOf(r)) }}</td>
                <td class="num">{{ formatInteger(r.n_obs) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="b.k === 'expected_amount'" class="hint mt-1">{{ t('customers.amountAssumption') }}</p>
      </div>

      <div v-if="inactivity" data-test="inactivity-metrics">
        <h3 class="section-title mb-1">{{ t('customers.eval.inactivityTitle') }}</h3>
        <p class="text-sm text-fg-muted mb-2">{{ inactivity.definition }}</p>
        <p class="text-sm m-0">{{ t('customers.eval.inactivityBase', { rate: typeof inactivity.base_no_purchase_rate === 'number' ? formatPercent(inactivity.base_no_purchase_rate, 1) : '—', auc: num(inactivity.auc_own_pattern_risk, 2), aucNaive: num(inactivity.auc_days_since_last_purchase, 2) }) }}</p>
      </div>
      <div v-if="frequency" data-test="frequency-metrics">
        <h3 class="section-title mb-1">{{ t('customers.eval.frequencyTitle') }}</h3>
        <p class="text-sm text-fg-muted m-0">{{ frequency.definition }}</p>
      </div>

      <ul v-if="data.notes?.length" class="list-disc pl-5 m-0 text-sm text-fg-muted flex flex-col gap-1">
        <li v-for="(n, i) in data.notes" :key="i">{{ n }}</li>
      </ul>
      <EnvelopePanel :envelope="envelope" />
    </template>
  </section>
</template>
