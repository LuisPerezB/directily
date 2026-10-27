<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import KpiCard from '@/components/ui/KpiCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ErrorBarsChart from '@/components/charts/ErrorBarsChart.vue'
import ComparisonChart from '@/components/charts/ComparisonChart.vue'
import ProductPicker from '@/components/forecast/ProductPicker.vue'
import { accuracyApi, exportsApi, forecastApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { AccuracyByGroup, Product } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatNumber, formatPercent, formatChange } from '@/utils/format'

const { t, te } = useI18n()
const catalog = useCatalogStore()
const tenant = useTenantStore()
const ui = useUiStore()

const runId = ref<number | null>(null)
const runs = useAsync(() => forecastApi.runs({ per_page: 30 }))
const completedRuns = computed(() => (runs.data.value?.data ?? []).filter((r) => r.status === 'completed'))
const summary = useAsync(() => accuracyApi.summary({ run_id: runId.value ?? undefined }), { watch: [runId] })
const s = computed(() => summary.data.value)
const noData = computed(() => (isApiError(summary.error.value) && summary.error.value.status === 404) || (summary.loaded.value && !s.value?.run))

const selected = computed(() => s.value?.backtest?.selected ?? null)
const baseline = computed(() => s.value?.backtest?.baseline ?? null)
const improvement = computed(() => {
  if (selected.value?.wape == null || baseline.value?.wape == null || baseline.value.wape === 0) return null
  return (selected.value.wape - baseline.value.wape) / baseline.value.wape
})
const byHorizon = computed(() => s.value?.backtest?.by_horizon ?? [])

function modelLabel(f: string | null | undefined): string {
  if (!f) return '—'
  return te(`models.${f}`) ? t(`models.${f}`) : f
}
function groupLabel(g: AccuracyByGroup, kind: 'class' | 'branch'): string {
  const key = g.label ?? g.level_key ?? g.key ?? '—'
  if (kind === 'class') return te(`demandClass.${key}`) ? t(`demandClass.${key}`) : String(key)
  return String(key)
}

// Comparación real vs pronosticado de una ejecución pasada
const compareRunId = ref<number | null>(null)
const compareBranch = ref<number | null>(null)
const compareProduct = ref<Product | null>(null)
watch(completedRuns, (list) => {
  if (compareRunId.value === null && list.length) {
    // Por defecto: la ejecución más antigua con fechas ya observadas (la más reciente suele no tener reales aún).
    compareRunId.value = (list.find((r) => !r.is_current) ?? list[0])!.id
  }
})
const comparison = useAsync(
  async () => (compareRunId.value ? accuracyApi.comparison({ run_id: compareRunId.value, branch_id: compareBranch.value ?? undefined, product_id: compareProduct.value?.id }) : null),
  { watch: [compareRunId, compareBranch, compareProduct], immediate: false },
)
const observedPoints = computed(() => comparison.data.value?.points.filter((p) => p.actual !== null).length ?? 0)

async function exportCsv(): Promise<void> {
  try {
    await exportsApi.accuracy({ run_id: runId.value ?? undefined })
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}

const glossary = ['wape', 'mae', 'rmse', 'bias', 'coverage', 'baseline', 'backtest', 'realized']
</script>

<template>
  <div class="flex flex-col gap-6">
    <header class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
      <div>
        <h1 class="page-title">{{ t('accuracy.title') }}</h1>
        <p class="text-fg-muted mt-1">{{ t('accuracy.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="label" for="a-run">{{ t('forecasts.version') }}</label>
          <select id="a-run" v-model="runId" class="select !w-auto min-w-[12rem]">
            <option :value="null">{{ t('forecasts.currentVersion') }}</option>
            <option v-for="r in completedRuns" :key="r.id" :value="r.id">v{{ r.version }} · {{ formatDate(r.cutoff_date) }}</option>
          </select>
        </div>
        <button v-if="tenant.can('data.export')" type="button" class="btn btn-secondary" :disabled="noData" @click="exportCsv"><AppIcon name="download" :size="16" /> {{ t('common.exportCsv') }}</button>
      </div>
    </header>

    <LoadingState v-if="summary.loading.value && !s" :lines="4" />
    <div v-else-if="noData" class="card">
      <EmptyState icon="target" :title="t('accuracy.noDataTitle')" :description="t('accuracy.noDataText')">
        <RouterLink :to="{ name: 'forecasts' }" class="btn btn-primary btn-sm">{{ t('products.goForecasts') }}</RouterLink>
      </EmptyState>
    </div>
    <div v-else-if="summary.error.value" class="card"><ErrorState :error="summary.error.value" @retry="summary.execute" /></div>

    <template v-else-if="s">
      <InlineNotice kind="info" :title="t('accuracy.honestyTitle')">{{ t('accuracy.honesty') }}</InlineNotice>

      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard :label="t('accuracy.selectedError')" :value="formatPercent(selected?.wape)" :hint="t('accuracy.selectedErrorHint')" :delta="improvement" :delta-label="t('dashboard.vsBaseline')" :up-is-good="false" icon="target" tone="brand" />
        <KpiCard :label="t('accuracy.baselineError')" :value="formatPercent(baseline?.wape)" :hint="t('accuracy.baselineHint')" icon="layers" />
        <KpiCard :label="t('accuracy.bias')" :value="formatChange(selected?.bias ?? null)" :hint="(selected?.bias ?? 0) > 0 ? t('accuracy.biasOver') : (selected?.bias ?? 0) < 0 ? t('accuracy.biasUnder') : t('accuracy.biasNone')" icon="sliders" />
        <KpiCard :label="t('accuracy.coverage')" :value="formatPercent(selected?.coverage_80 ?? null, 0)" :hint="t('accuracy.coverageHint', { c95: formatPercent(selected?.coverage_95 ?? null, 0) })" icon="chart" />
      </section>

      <section v-if="s.explanation?.length" class="card p-4 sm:p-6">
        <h2 class="section-title mb-2">{{ t('accuracy.explanationTitle') }}</h2>
        <ul class="list-disc pl-5 m-0 flex flex-col gap-1.5 text-sm">
          <li v-for="(line, i) in s.explanation" :key="i">{{ line }}</li>
        </ul>
      </section>

      <section v-if="byHorizon.length" class="card p-4 sm:p-6">
        <h2 class="section-title mb-1">{{ t('accuracy.byHorizon') }}</h2>
        <p class="text-sm text-fg-muted mb-3">{{ t('accuracy.byHorizonText') }}</p>
        <ErrorBarsChart
          :categories="byHorizon.map((h) => t('accuracy.horizonLabel', { n: h.horizon_days }))"
          :selected="byHorizon.map((h) => h.wape)"
          :baseline="byHorizon.map((h) => h.baseline_wape)"
          :title="t('accuracy.byHorizon')"
        />
      </section>

      <section class="grid gap-4 lg:grid-cols-2">
        <article class="card p-4 sm:p-6">
          <h2 class="section-title mb-3">{{ t('accuracy.models') }}</h2>
          <div class="table-wrap">
            <table class="table">
              <caption class="sr-only">{{ t('accuracy.models') }}</caption>
              <thead><tr><th scope="col">{{ t('products.model') }}</th><th scope="col" class="num">WAPE</th><th scope="col">{{ t('accuracy.role') }}</th></tr></thead>
              <tbody>
                <tr v-for="m in s.backtest?.models ?? []" :key="m.model_family">
                  <td>{{ modelLabel(m.model_family) }}</td>
                  <td class="num">{{ formatPercent(m.wape) }}</td>
                  <td>
                    <span v-if="m.is_selected" class="badge badge-success">{{ t('accuracy.selected') }}</span>
                    <span v-if="m.is_baseline" class="badge ml-1">{{ t('accuracy.baseline') }}</span>
                  </td>
                </tr>
                <tr v-if="!(s.backtest?.models ?? []).length"><td colspan="3" class="text-center text-fg-muted">{{ t('table.empty') }}</td></tr>
              </tbody>
            </table>
          </div>
        </article>
        <article class="card p-4 sm:p-6">
          <h2 class="section-title mb-3">{{ t('accuracy.byClass') }}</h2>
          <div class="table-wrap">
            <table class="table">
              <caption class="sr-only">{{ t('accuracy.byClass') }}</caption>
              <thead><tr><th scope="col">{{ t('products.demandClass') }}</th><th scope="col" class="num">WAPE</th><th scope="col" class="num">{{ t('accuracy.baseline') }}</th><th scope="col">{{ t('products.model') }}</th></tr></thead>
              <tbody>
                <tr v-for="(g, i) in s.backtest?.by_demand_class ?? []" :key="i">
                  <td>{{ groupLabel(g, 'class') }}</td>
                  <td class="num">{{ formatPercent(g.wape) }}</td>
                  <td class="num">{{ formatPercent(g.baseline_wape ?? null) }}</td>
                  <td>{{ modelLabel(g.model_family) }}</td>
                </tr>
                <tr v-if="!(s.backtest?.by_demand_class ?? []).length"><td colspan="4" class="text-center text-fg-muted">{{ t('table.empty') }}</td></tr>
              </tbody>
            </table>
          </div>
          <p class="hint mt-2">{{ t('accuracy.classNote') }}</p>
        </article>
      </section>

      <section v-if="(s.backtest?.by_branch ?? []).length" class="card p-4 sm:p-6">
        <h2 class="section-title mb-3">{{ t('accuracy.byBranch') }}</h2>
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('accuracy.byBranch') }}</caption>
            <thead><tr><th scope="col">{{ t('common.branch') }}</th><th scope="col" class="num">WAPE</th><th scope="col" class="num">MAE</th><th scope="col" class="num">{{ t('accuracy.bias') }}</th></tr></thead>
            <tbody>
              <tr v-for="(g, i) in s.backtest?.by_branch ?? []" :key="i">
                <td>{{ groupLabel(g, 'branch') }}</td>
                <td class="num">{{ formatPercent(g.wape) }}</td>
                <td class="num">{{ formatNumber(g.mae ?? null, 2) }}</td>
                <td class="num">{{ formatChange(g.bias ?? null) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card p-4 sm:p-6">
        <h2 class="section-title mb-1">{{ t('accuracy.realizedTitle') }}</h2>
        <p class="text-sm text-fg-muted mb-3">{{ t('accuracy.realizedText') }}</p>
        <template v-if="s.realized && s.realized.n_obs > 0">
          <p class="text-sm mb-3">{{ t('accuracy.realizedSummary', { wape: formatPercent(s.realized.wape), n: formatNumber(s.realized.n_obs, 0), bias: formatChange(s.realized.bias) }) }}</p>
          <div class="table-wrap">
            <table class="table">
              <caption class="sr-only">{{ t('accuracy.realizedTitle') }}</caption>
              <thead><tr><th scope="col">{{ t('forecasts.version') }}</th><th scope="col" class="num">WAPE</th><th scope="col" class="num">{{ t('accuracy.observations') }}</th></tr></thead>
              <tbody>
                <tr v-for="r in s.realized.by_run" :key="r.run_id"><td>v{{ r.version }}</td><td class="num">{{ formatPercent(r.wape) }}</td><td class="num">{{ formatNumber(r.n_obs, 0) }}</td></tr>
              </tbody>
            </table>
          </div>
        </template>
        <p v-else class="text-sm text-fg-muted">{{ t('accuracy.noRealized') }}</p>
      </section>

      <section class="card p-4 sm:p-6">
        <h2 class="section-title mb-1">{{ t('accuracy.comparisonTitle') }}</h2>
        <p class="text-sm text-fg-muted mb-4">{{ t('accuracy.comparisonText') }}</p>
        <div class="grid gap-4 sm:grid-cols-3 mb-4">
          <div>
            <label class="label" for="c-run">{{ t('forecasts.version') }}</label>
            <select id="c-run" v-model="compareRunId" class="select">
              <option v-for="r in completedRuns" :key="r.id" :value="r.id">v{{ r.version }} · {{ t('accuracy.cutoffOn', { date: formatDate(r.cutoff_date) }) }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="c-branch">{{ t('filters.branch') }}</label>
            <select id="c-branch" v-model="compareBranch" class="select">
              <option :value="null">{{ t('filters.allBranches') }}</option>
              <option v-for="b in catalog.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="c-product">{{ t('filters.product') }}</label>
            <ProductPicker id="c-product" v-model="compareProduct" />
          </div>
        </div>
        <LoadingState v-if="comparison.loading.value" />
        <ErrorState v-else-if="comparison.error.value" :error="comparison.error.value" compact @retry="comparison.execute" />
        <template v-else-if="comparison.data.value">
          <p v-if="observedPoints === 0" class="text-sm text-fg-muted">{{ t('accuracy.noActualsYet') }}</p>
          <template v-else>
            <p class="text-sm mb-2">
              {{ t('accuracy.comparisonMetrics', { wape: formatPercent(comparison.data.value.metrics.wape), mae: formatNumber(comparison.data.value.metrics.mae, 1), n: comparison.data.value.metrics.n_obs }) }}
            </p>
            <ComparisonChart :points="comparison.data.value.points" :title="t('accuracy.comparisonTitle')" />
          </template>
        </template>
        <p v-else-if="!completedRuns.length" class="text-sm text-fg-muted">{{ t('forecasts.noRuns') }}</p>
      </section>
    </template>

    <section class="card p-4 sm:p-6">
      <h2 class="section-title mb-3">{{ t('accuracy.glossaryTitle') }}</h2>
      <dl class="grid md:grid-cols-2 gap-x-8 gap-y-3 text-sm">
        <div v-for="g in glossary" :key="g">
          <dt class="font-semibold">{{ t(`accuracy.glossary.${g}.term`) }}</dt>
          <dd class="m-0 text-fg-muted">{{ t(`accuracy.glossary.${g}.text`) }}</dd>
        </div>
      </dl>
    </section>
  </div>
</template>
