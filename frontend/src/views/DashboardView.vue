<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import KpiCard from '@/components/ui/KpiCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ForecastChart from '@/components/charts/ForecastChart.vue'
import RunForecastPanel from '@/components/forecast/RunForecastPanel.vue'
import { forecastApi, inventoryApi } from '@/api'
import type { Horizon } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { useCatalogStore } from '@/stores/catalog'
import { formatCurrencyCompact, formatDate, formatDateTime, formatNumber, formatPercent, formatChange, formatProbability } from '@/utils/format'
import { HORIZONS } from '@/utils/constants'

const { t } = useI18n()
const tenant = useTenantStore()
const catalog = useCatalogStore()

const defaultHorizon = (tenant.tenant?.settings?.default_horizon_days ?? 30) as Horizon
const horizon = ref<Horizon>(HORIZONS.includes(defaultHorizon) ? defaultHorizon : 30)
const branchId = ref<number | null>(null)

const summary = useAsync(() => forecastApi.dashboard({ horizon: horizon.value, branch_id: branchId.value ?? undefined }), { watch: [horizon, branchId] })
const series = useAsync(
  () => forecastApi.series({ horizon: horizon.value, branch_id: branchId.value ?? undefined, granularity: 'day', history_days: 90 }),
  { watch: [horizon, branchId] },
)
const inventory = useAsync(() => inventoryApi.summary())

const s = computed(() => summary.data.value)
const k = computed(() => s.value?.kpis ?? null)
const status = computed(() => s.value?.data_status ?? null)
const hasForecast = computed(() => !!status.value?.has_forecast && !!s.value?.run)
const horizonOptions = computed(() => HORIZONS.map((h) => ({ value: h, label: t('common.days', { n: h }) })))
const currency = computed(() => tenant.currency)

const intervalHint = computed(() => {
  if (!k.value || k.value.forecast_units_lo_80 == null || k.value.forecast_units_hi_80 == null) return null
  return t('dashboard.range80', { lo: formatNumber(k.value.forecast_units_lo_80, 0), hi: formatNumber(k.value.forecast_units_hi_80, 0) })
})
const reliabilityHint = computed(() => {
  if (!k.value) return null
  const parts: string[] = []
  if (k.value.baseline_wape != null) parts.push(t('dashboard.baselineError', { value: formatPercent(k.value.baseline_wape) }))
  if (k.value.realized_wape != null) parts.push(t('dashboard.realizedError', { value: formatPercent(k.value.realized_wape) }))
  return parts.join(' · ') || null
})
const improvement = computed(() => {
  if (!k.value || k.value.backtest_wape == null || k.value.baseline_wape == null || k.value.baseline_wape === 0) return null
  return (k.value.backtest_wape - k.value.baseline_wape) / k.value.baseline_wape
})

function reload(): void {
  void summary.execute()
  void series.execute()
  void inventory.execute()
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <header class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
      <div>
        <h1 class="page-title">{{ t('dashboard.title') }}</h1>
        <p class="text-fg-muted mt-1">
          <template v-if="s?.run">
            {{ t('dashboard.runInfo', { version: s.run.version, date: formatDateTime(s.run.generated_at, tenant.timezone), cutoff: formatDate(s.run.cutoff_date) }) }}
          </template>
          <template v-else>{{ t('dashboard.subtitle') }}</template>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <SegmentedControl v-model="horizon" :options="horizonOptions" :label="t('common.horizon')" />
        <label class="sr-only" for="dash-branch">{{ t('filters.branch') }}</label>
        <select id="dash-branch" v-model="branchId" class="select !w-auto min-w-[11rem]">
          <option :value="null">{{ t('filters.allBranches') }}</option>
          <option v-for="b in catalog.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>
    </header>

    <LoadingState v-if="summary.loading.value && !s" :lines="4" />
    <div v-else-if="summary.error.value" class="card"><ErrorState :error="summary.error.value" @retry="reload" /></div>

    <template v-else-if="s">
      <InlineNotice v-for="(w, i) in status?.warnings ?? []" :key="i" kind="warning">{{ w }}</InlineNotice>

      <div v-if="!status?.has_sales" class="card">
        <EmptyState icon="upload" :title="t('dashboard.noSalesTitle')" :description="t('dashboard.noSalesText')">
          <RouterLink v-if="tenant.can('imports.manage')" :to="{ name: 'import-new', query: { entity: 'sales' } }" class="btn btn-primary">{{ t('dashboard.importSales') }}</RouterLink>
          <RouterLink :to="{ name: 'onboarding' }" class="btn btn-secondary">{{ t('dashboard.goOnboarding') }}</RouterLink>
        </EmptyState>
      </div>

      <div v-else-if="!hasForecast" class="card p-6">
        <EmptyState icon="chart" :title="t('dashboard.noForecastTitle')" :description="t('dashboard.noForecastText')" compact />
        <div class="max-w-xl mx-auto"><RunForecastPanel @finished="reload" /></div>
      </div>

      <template v-else>
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" :aria-label="t('dashboard.kpis')">
          <KpiCard
            :question="t('dashboard.q1')"
            :label="t('dashboard.forecastUnits', { n: horizon })"
            :value="`${formatNumber(k?.forecast_units, 0)} ${t('chart.units')}`"
            :hint="intervalHint"
            :delta="k?.change_pct ?? null"
            :delta-label="t('dashboard.vsPrevious')"
            icon="chart"
            tone="brand"
          />
          <KpiCard
            :question="t('dashboard.q1b')"
            :label="t('dashboard.forecastRevenue')"
            :value="formatCurrencyCompact(k?.forecast_revenue, currency)"
            :hint="k?.previous_period_revenue != null ? t('dashboard.previousRevenue', { value: formatCurrencyCompact(k.previous_period_revenue, currency) }) : t('dashboard.priceAssumption')"
            icon="trend-up"
          />
          <KpiCard
            :question="t('dashboard.q6')"
            :label="t('dashboard.modelError')"
            :value="formatPercent(k?.backtest_wape)"
            :hint="reliabilityHint"
            :delta="improvement"
            :delta-label="t('dashboard.vsBaseline')"
            :up-is-good="false"
            icon="target"
          >
            <RouterLink :to="{ name: 'accuracy' }" class="text-sm font-semibold text-brand-strong">{{ t('dashboard.seeAccuracy') }}</RouterLink>
          </KpiCard>
          <KpiCard
            :question="t('dashboard.q3')"
            :label="t('dashboard.stockoutRisk')"
            :value="formatNumber(k?.stockout_risk_products, 0)"
            :hint="status?.has_inventory ? t('dashboard.stockoutHint') : t('dashboard.noInventory')"
            icon="alert"
            :tone="(k?.stockout_risk_products ?? 0) > 0 ? 'danger' : 'default'"
          />
          <KpiCard
            :question="t('dashboard.q4')"
            :label="t('dashboard.coverage')"
            :value="inventory.data.value?.coverage_days_median != null ? t('common.daysValue', { n: formatNumber(inventory.data.value.coverage_days_median, 0) }) : '—'"
            :hint="inventory.data.value ? t('dashboard.riskCounts', { high: (inventory.data.value.risk_counts?.high ?? 0) + (inventory.data.value.risk_counts?.critical ?? 0), medium: inventory.data.value.risk_counts?.medium ?? 0 }) : t('dashboard.noInventory')"
            icon="warehouse"
          />
          <KpiCard
            :question="t('dashboard.q5')"
            :label="t('dashboard.purchaseInvestment')"
            :value="formatCurrencyCompact(k?.purchase_investment, currency)"
            :hint="t('dashboard.purchaseHint')"
            icon="box"
            tone="warning"
          >
            <RouterLink :to="{ name: 'inventory' }" class="text-sm font-semibold text-brand-strong">{{ t('dashboard.seeInventory') }}</RouterLink>
          </KpiCard>
        </section>

        <section class="card p-4 sm:p-6">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <h2 class="section-title">{{ t('dashboard.chartTitle') }}</h2>
            <RouterLink :to="{ name: 'forecasts' }" class="btn btn-ghost btn-sm">{{ t('dashboard.seeForecasts') }}</RouterLink>
          </div>
          <LoadingState v-if="series.loading.value && !series.data.value" />
          <ErrorState v-else-if="series.error.value" :error="series.error.value" compact @retry="series.execute" />
          <ForecastChart v-else-if="series.data.value" :history="series.data.value.history" :forecast="series.data.value.forecast" :title="t('dashboard.chartTitle')" :currency="currency" />
          <p v-if="series.data.value?.meta?.price_assumption" class="hint mt-2">{{ t('forecasts.priceAssumption', { value: series.data.value.meta.price_assumption }) }}</p>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
          <article class="card p-4 sm:p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-strong">{{ t('dashboard.q2') }}</p>
            <h2 class="section-title mb-3">{{ t('dashboard.topIncreasing') }}</h2>
            <ul v-if="s.top_increasing.length" class="flex flex-col divide-y divide-line list-none p-0 m-0">
              <li v-for="p in s.top_increasing" :key="p.product_id" class="py-2 flex items-center justify-between gap-3 text-sm">
                <span class="min-w-0"><span class="font-medium block truncate">{{ p.name }}</span><span class="text-xs text-fg-muted">{{ p.sku }} · {{ t('dashboard.unitsForecast', { n: formatNumber(p.forecast_units, 0) }) }}</span></span>
                <span class="font-semibold text-success tabular">{{ formatChange(p.change_pct) }}</span>
              </li>
            </ul>
            <p v-else class="text-sm text-fg-muted">{{ t('dashboard.noChanges') }}</p>
          </article>
          <article class="card p-4 sm:p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-strong">{{ t('dashboard.q2') }}</p>
            <h2 class="section-title mb-3">{{ t('dashboard.topDecreasing') }}</h2>
            <ul v-if="s.top_decreasing.length" class="flex flex-col divide-y divide-line list-none p-0 m-0">
              <li v-for="p in s.top_decreasing" :key="p.product_id" class="py-2 flex items-center justify-between gap-3 text-sm">
                <span class="min-w-0"><span class="font-medium block truncate">{{ p.name }}</span><span class="text-xs text-fg-muted">{{ p.sku }} · {{ t('dashboard.unitsForecast', { n: formatNumber(p.forecast_units, 0) }) }}</span></span>
                <span class="font-semibold text-danger tabular">{{ formatChange(p.change_pct) }}</span>
              </li>
            </ul>
            <p v-else class="text-sm text-fg-muted">{{ t('dashboard.noChanges') }}</p>
          </article>
        </section>

        <section class="card p-4 sm:p-6">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-brand-strong">{{ t('dashboard.q3') }}</p>
              <h2 class="section-title">{{ t('dashboard.stockoutTitle') }}</h2>
            </div>
            <RouterLink :to="{ name: 'inventory' }" class="btn btn-ghost btn-sm">{{ t('dashboard.seeInventory') }}</RouterLink>
          </div>
          <p v-if="!status?.has_inventory" class="text-sm text-fg-muted">{{ t('dashboard.noInventoryLong') }}</p>
          <div v-else-if="s.stockout_risks.length" class="table-wrap">
            <table class="table">
              <caption class="sr-only">{{ t('dashboard.stockoutTitle') }}</caption>
              <thead>
                <tr>
                  <th scope="col">{{ t('common.product') }}</th>
                  <th scope="col">{{ t('common.branch') }}</th>
                  <th scope="col" class="num">{{ t('inventory.daysOfCover') }}</th>
                  <th scope="col" class="num">{{ t('inventory.stockoutProbability') }}</th>
                  <th scope="col" class="num">{{ t('inventory.suggestedQty') }}</th>
                  <th scope="col">{{ t('inventory.risk') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in s.stockout_risks" :key="`${r.branch_id}-${r.product_id}`">
                  <td>{{ r.product_name }}</td>
                  <td>{{ r.branch_name }}</td>
                  <td class="num">{{ formatNumber(r.days_of_cover, 1) }}</td>
                  <td class="num">{{ formatProbability(r.stockout_probability) }}</td>
                  <td class="num">{{ formatNumber(r.suggested_qty, 0) }}</td>
                  <td><StatusBadge domain="risk" :value="r.risk_level" /></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-sm text-fg-muted">{{ t('dashboard.noStockoutRisks') }}</p>
        </section>

        <section v-if="s.alerts.length" class="card p-4 sm:p-6">
          <h2 class="section-title mb-3">{{ t('dashboard.alerts') }}</h2>
          <ul class="flex flex-col gap-2 list-none p-0 m-0">
            <li v-for="a in s.alerts" :key="a.id" class="flex items-start gap-3 text-sm">
              <StatusBadge domain="severity" :value="a.severity" />
              <span><span class="font-semibold">{{ a.title }}</span> — {{ a.message }}</span>
            </li>
          </ul>
        </section>

        <section class="card p-4 sm:p-6">
          <h2 class="section-title mb-1">{{ t('dashboard.regenerateTitle') }}</h2>
          <p class="text-sm text-fg-muted mb-3">{{ t('dashboard.regenerateText') }}</p>
          <RunForecastPanel @finished="reload" />
        </section>
        <p class="hint">{{ t('dashboard.footnote') }}</p>
      </template>
    </template>
  </div>
</template>
