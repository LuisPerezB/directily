<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import ForecastChart from '@/components/charts/ForecastChart.vue'
import RunForecastPanel from '@/components/forecast/RunForecastPanel.vue'
import ProductPicker from '@/components/forecast/ProductPicker.vue'
import { exportsApi, forecastApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { Granularity, Horizon, Product } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatDateTime, formatNumber, formatCurrency } from '@/utils/format'
import { GRANULARITIES, HORIZONS } from '@/utils/constants'
import type { Metric } from '@/utils/series'

const { t, te } = useI18n()
const catalog = useCatalogStore()
const tenant = useTenantStore()
const ui = useUiStore()

const branchId = ref<number | null>(null)
const categoryId = ref<number | null>(null)
const product = ref<Product | null>(null)
const granularity = ref<Granularity>('day')
const horizon = ref<Horizon>(30)
const historyDays = ref(90)
const metric = ref<Metric>('units')
const runId = ref<number | null>(null)

const runs = useAsync(() => forecastApi.runs({ per_page: 20 }))
const completedRuns = computed(() => (runs.data.value?.data ?? []).filter((r) => r.status === 'completed'))

const series = useAsync(
  () =>
    forecastApi.series({
      run_id: runId.value ?? undefined,
      branch_id: branchId.value ?? undefined,
      category_id: product.value ? undefined : categoryId.value ?? undefined,
      product_id: product.value?.id,
      granularity: granularity.value,
      horizon: horizon.value,
      history_days: historyDays.value,
    }),
  { watch: [branchId, categoryId, product, granularity, horizon, historyDays, runId] },
)

watch(categoryId, () => {
  if (product.value && categoryId.value && product.value.category?.id !== categoryId.value) product.value = null
})

const noForecast = computed(() => {
  const e = series.error.value
  return (isApiError(e) && e.status === 404) || (series.data.value && !series.data.value.meta && series.data.value.forecast.length === 0)
})
const data = computed(() => series.data.value)
const totals = computed(() => {
  const f = data.value?.forecast ?? []
  return {
    units: f.reduce((a, p) => a + (p.yhat ?? 0), 0),
    revenue: f.reduce((a, p) => a + (p.revenue ?? 0), 0),
    hasRevenue: f.some((p) => p.revenue !== null),
  }
})
const granularityOptions = computed(() => GRANULARITIES.map((g) => ({ value: g, label: t(`granularity.${g}`) })))
const horizonOptions = computed(() => HORIZONS.map((h) => ({ value: h, label: t('common.days', { n: h }) })))
const metricOptions = computed(() => [
  { value: 'units' as Metric, label: t('forecasts.metricUnits') },
  { value: 'revenue' as Metric, label: t('forecasts.metricRevenue') },
])

function modelLabel(family: string | null | undefined): string {
  if (!family) return '—'
  const key = `models.${family}`
  return te(key) ? t(key) : family
}

const exporting = ref(false)
async function exportCsv(): Promise<void> {
  exporting.value = true
  try {
    await exportsApi.forecasts({
      run_id: runId.value ?? undefined,
      horizon: horizon.value,
      branch_id: branchId.value ?? undefined,
      category_id: categoryId.value ?? undefined,
      granularity: granularity.value,
    })
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    exporting.value = false
  }
}

function onFinished(): void {
  runId.value = null
  void runs.execute()
  void series.execute()
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <header class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
      <div>
        <h1 class="page-title">{{ t('forecasts.title') }}</h1>
        <p class="text-fg-muted mt-1">{{ t('forecasts.subtitle') }}</p>
      </div>
      <button v-if="tenant.can('data.export')" type="button" class="btn btn-secondary" :disabled="exporting || !!noForecast" @click="exportCsv">
        <AppIcon name="download" :size="16" /> {{ t('common.exportCsv') }}
      </button>
    </header>

    <section class="card p-4 sm:p-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4" :aria-label="t('filters.title')">
      <div>
        <label class="label" for="f-branch">{{ t('filters.branch') }}</label>
        <select id="f-branch" v-model="branchId" class="select">
          <option :value="null">{{ t('filters.allBranches') }}</option>
          <option v-for="b in catalog.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="f-category">{{ t('filters.category') }}</label>
        <select id="f-category" v-model="categoryId" class="select">
          <option :value="null">{{ t('filters.allCategories') }}</option>
          <option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="f-product">{{ t('filters.product') }}</label>
        <ProductPicker id="f-product" v-model="product" :category-id="categoryId" />
      </div>
      <div>
        <label class="label" for="f-run">{{ t('forecasts.version') }}</label>
        <select id="f-run" v-model="runId" class="select">
          <option :value="null">{{ t('forecasts.currentVersion') }}</option>
          <option v-for="r in completedRuns" :key="r.id" :value="r.id">
            v{{ r.version }} · {{ formatDate(r.cutoff_date) }}{{ r.run_type === 'historical' ? ` · ${t('forecasts.historical')}` : '' }}
          </option>
        </select>
      </div>
      <div class="md:col-span-2 xl:col-span-4 flex flex-wrap items-center gap-3">
        <SegmentedControl v-model="granularity" :options="granularityOptions" :label="t('common.granularity')" />
        <SegmentedControl v-model="horizon" :options="horizonOptions" :label="t('common.horizon')" />
        <SegmentedControl v-model="metric" :options="metricOptions" :label="t('forecasts.metric')" />
        <label class="flex items-center gap-2 text-sm">
          <span class="text-fg-muted">{{ t('forecasts.historyDays') }}</span>
          <select v-model.number="historyDays" class="select !w-auto !min-h-9 !py-1">
            <option v-for="d in [30, 90, 180, 365, 730]" :key="d" :value="d">{{ t('common.days', { n: d }) }}</option>
          </select>
        </label>
      </div>
    </section>

    <section class="card p-4 sm:p-6">
      <LoadingState v-if="series.loading.value && !data" :lines="5" />
      <div v-else-if="noForecast">
        <EmptyState icon="chart" :title="t('forecasts.noForecastTitle')" :description="t('forecasts.noForecastText')" compact />
        <div class="max-w-xl mx-auto"><RunForecastPanel @finished="onFinished" /></div>
      </div>
      <ErrorState v-else-if="series.error.value" :error="series.error.value" @retry="series.execute" />
      <template v-else-if="data">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
          <div>
            <h2 class="section-title">{{ data.node.label || t(`levels.${data.node.level}`) }}</h2>
            <p class="text-sm text-fg-muted">{{ t(`levels.${data.node.level}`) }}</p>
          </div>
          <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
            <dt class="text-fg-muted">{{ t('forecasts.totalForecast', { n: horizon }) }}</dt>
            <dd class="m-0 font-semibold tabular text-right">{{ formatNumber(totals.units, 0) }} {{ t('chart.units') }}</dd>
            <template v-if="totals.hasRevenue">
              <dt class="text-fg-muted">{{ t('forecasts.totalRevenue') }}</dt>
              <dd class="m-0 font-semibold tabular text-right">{{ formatCurrency(totals.revenue, tenant.currency, 0) }}</dd>
            </template>
          </dl>
        </div>
        <InlineNotice v-if="data.history.length === 0" kind="info" class="mb-3">{{ t('forecasts.noHistory') }}</InlineNotice>
        <InlineNotice v-if="data.meta && !data.meta.intervals_available" kind="info" class="mb-3">{{ t('forecasts.noIntervals') }}</InlineNotice>
        <ForecastChart :history="data.history" :forecast="data.forecast" :metric="metric" :granularity="granularity" :currency="tenant.currency" :title="data.node.label || t('forecasts.title')" :height="380" />
        <dl v-if="data.meta" class="mt-5 grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-4 text-sm border-t border-line pt-4">
          <div><dt class="text-fg-muted">{{ t('forecasts.version') }}</dt><dd class="m-0 font-semibold">v{{ data.meta.run_version }}</dd></div>
          <div><dt class="text-fg-muted">{{ t('forecasts.generatedAt') }}</dt><dd class="m-0 font-semibold">{{ formatDateTime(data.meta.generated_at, tenant.timezone) }}</dd></div>
          <div><dt class="text-fg-muted">{{ t('forecasts.cutoff') }}</dt><dd class="m-0 font-semibold">{{ formatDate(data.meta.cutoff_date) }}</dd></div>
          <div>
            <dt class="text-fg-muted">{{ t('forecasts.model') }}</dt>
            <dd class="m-0 font-semibold">{{ modelLabel(data.meta.model_family) }}</dd>
          </div>
          <div v-if="Object.keys(data.meta.models ?? {}).length > 1" class="sm:col-span-2 lg:col-span-4">
            <dt class="text-fg-muted">{{ t('forecasts.modelsUsed') }}</dt>
            <dd class="m-0 flex flex-wrap gap-1 mt-1">
              <span v-for="(count, family) in data.meta.models" :key="family" class="badge">{{ modelLabel(String(family)) }} · {{ t('forecasts.seriesCount', { n: count }) }}</span>
            </dd>
          </div>
          <div v-if="data.meta.price_assumption" class="sm:col-span-2 lg:col-span-4">
            <dt class="text-fg-muted">{{ t('forecasts.priceAssumptionLabel') }}</dt>
            <dd class="m-0">{{ data.meta.price_assumption }}</dd>
          </div>
        </dl>
        <p class="hint mt-3">{{ t('forecasts.howToRead') }}</p>
      </template>
    </section>

    <section class="card p-4 sm:p-6">
      <h2 class="section-title mb-1">{{ t('forecasts.runsTitle') }}</h2>
      <p class="text-sm text-fg-muted mb-4">{{ t('forecasts.runsText') }}</p>
      <RunForecastPanel class="mb-4" @finished="onFinished" />
      <LoadingState v-if="runs.loading.value && !runs.data.value" />
      <ErrorState v-else-if="runs.error.value" :error="runs.error.value" compact @retry="runs.execute" />
      <div v-else-if="runs.data.value?.data.length" class="table-wrap">
        <table class="table">
          <caption class="sr-only">{{ t('forecasts.runsTitle') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('forecasts.version') }}</th>
              <th scope="col">{{ t('common.status') }}</th>
              <th scope="col">{{ t('forecasts.cutoff') }}</th>
              <th scope="col">{{ t('forecasts.generatedAt') }}</th>
              <th scope="col">{{ t('forecasts.type') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in runs.data.value.data" :key="r.id">
              <td class="font-semibold">v{{ r.version }} <span v-if="r.is_current" class="badge badge-brand ml-1">{{ t('forecasts.current') }}</span></td>
              <td><StatusBadge domain="run" :value="r.status" /><p v-if="r.error_message" class="text-xs text-danger mt-1 max-w-xs">{{ r.error_message }}</p></td>
              <td>{{ formatDate(r.cutoff_date) }}</td>
              <td>{{ formatDateTime(r.completed_at ?? r.created_at, tenant.timezone) }}</td>
              <td>{{ r.run_type === 'historical' ? t('forecasts.historical') : t('forecasts.production') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else class="text-sm text-fg-muted">{{ t('forecasts.noRuns') }}</p>
    </section>
  </div>
</template>
