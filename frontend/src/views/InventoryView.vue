<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import KpiCard from '@/components/ui/KpiCard.vue'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import AppModal from '@/components/ui/AppModal.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { exportsApi, inventoryApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { Recommendation, RecommendationQuery, RecommendationStatus, RecommendationType, RiskLevel } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatCurrency, formatCurrencyCompact, formatDate, formatNumber, formatPercent, formatProbability } from '@/utils/format'

const { t, te } = useI18n()
const catalog = useCatalogStore()
const tenant = useTenantStore()
const ui = useUiStore()

const branchId = ref<number | null>(null)
const categoryId = ref<number | null>(null)
const riskLevel = ref<RiskLevel | null>(null)
const type = ref<RecommendationType | null>(null)
const search = ref('')
const sort = ref<string | null>('-stockout_probability')
const page = ref(1)
watch([branchId, categoryId, riskLevel, type, search, sort], () => (page.value = 1))

const query = computed<RecommendationQuery>(() => ({
  branch_id: branchId.value ?? undefined,
  category_id: categoryId.value ?? undefined,
  risk_level: riskLevel.value ?? undefined,
  type: type.value ?? undefined,
  search: search.value || undefined,
  sort: sort.value ?? undefined,
  page: page.value,
  per_page: 25,
}))

const summary = useAsync(() => inventoryApi.summary())
const recs = useAsync(() => inventoryApi.recommendations(query.value), { watch: [query], debounce: 200 })

const noData = computed(() => {
  const e = recs.error.value
  return (isApiError(e) && e.status === 404) || (recs.loaded.value && (recs.data.value?.meta?.total ?? 0) === 0 && !riskLevel.value && !type.value && !search.value && !branchId.value && !categoryId.value)
})
const hasSnapshot = computed(() => !!summary.data.value?.snapshot_date)

const RISKS: RiskLevel[] = ['critical', 'high', 'medium', 'low']
const TYPES: RecommendationType[] = ['reorder', 'stockout_risk', 'overstock', 'no_action']

const columns = computed<Column<Recommendation>[]>(() => [
  // Lo decisivo primero (riesgo, probabilidad, cobertura); el detalle del cálculo después.
  { key: 'product.name', label: t('common.product'), class: 'min-w-[13rem]' },
  { key: 'branch.name', label: t('common.branch'), class: 'min-w-[9rem]' },
  { key: 'risk_level', label: t('inventory.risk') },
  { key: 'stockout_probability', label: t('inventory.stockoutProbability'), align: 'right', sortable: true, format: (r) => formatProbability(r.stockout_probability) },
  { key: 'days_of_cover', label: t('inventory.daysOfCover'), align: 'right', sortable: true, format: (r) => formatNumber(r.days_of_cover, 1) },
  { key: 'suggested_qty', label: t('inventory.suggestedQty'), align: 'right', format: (r) => formatNumber(r.suggested_qty, 0) },
  { key: 'estimated_investment', label: t('inventory.investment'), align: 'right', sortable: true, format: (r) => formatCurrency(r.estimated_investment, tenant.currency, 0) },
  { key: 'available', label: t('inventory.available'), align: 'right', format: (r) => formatNumber(r.available, 0) },
  { key: 'reorder_point', label: t('inventory.reorderPoint'), align: 'right', format: (r) => formatNumber(r.reorder_point, 0) },
  { key: 'status', label: t('common.status') },
  { key: 'actions', label: '' },
])

const detail = ref<Recommendation | null>(null)
const updating = ref<number | null>(null)

async function setStatus(rec: Recommendation, status: RecommendationStatus): Promise<void> {
  updating.value = rec.id
  try {
    const updated = await inventoryApi.updateRecommendation(rec.id, status)
    Object.assign(rec, updated ?? { status })
    ui.notify(t('inventory.statusUpdated'), 'success')
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    updating.value = null
  }
}

async function exportCsv(): Promise<void> {
  try {
    await exportsApi.recommendations({ ...query.value, page: undefined, per_page: undefined })
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}

function leadSource(s: string): string {
  return te(`inventory.leadSources.${s}`) ? t(`inventory.leadSources.${s}`) : s
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <header class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
      <div>
        <h1 class="page-title">{{ t('inventory.title') }}</h1>
        <p class="text-fg-muted mt-1">{{ t('inventory.subtitle') }}</p>
      </div>
      <button v-if="tenant.can('data.export')" type="button" class="btn btn-secondary" :disabled="noData" @click="exportCsv"><AppIcon name="download" :size="16" /> {{ t('common.exportCsv') }}</button>
    </header>

    <InlineNotice kind="info" :title="t('inventory.noticeTitle')">{{ t('inventory.notice') }}</InlineNotice>

    <section v-if="summary.data.value" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <KpiCard :label="t('inventory.snapshot')" :value="formatDate(summary.data.value.snapshot_date)" :hint="t('inventory.productsWithStock', { n: formatNumber(summary.data.value.products_with_stock, 0) })" icon="clock" />
      <KpiCard :label="t('inventory.stockValue')" :value="formatCurrencyCompact(summary.data.value.total_on_hand_value, tenant.currency)" icon="warehouse" />
      <KpiCard
        :label="t('inventory.riskSummary')"
        :value="formatNumber((summary.data.value.risk_counts?.critical ?? 0) + (summary.data.value.risk_counts?.high ?? 0), 0)"
        :hint="t('inventory.riskBreakdown', { critical: summary.data.value.risk_counts?.critical ?? 0, high: summary.data.value.risk_counts?.high ?? 0, medium: summary.data.value.risk_counts?.medium ?? 0 })"
        icon="alert"
        tone="danger"
      />
      <KpiCard :label="t('inventory.purchaseInvestment')" :value="formatCurrencyCompact(summary.data.value.purchase_investment, tenant.currency)" :hint="t('inventory.coverageMedian', { n: formatNumber(summary.data.value.coverage_days_median, 0) })" icon="box" tone="warning" />
    </section>

    <section class="card p-4 sm:p-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-5" :aria-label="t('filters.title')">
      <div>
        <label class="label" for="i-branch">{{ t('filters.branch') }}</label>
        <select id="i-branch" v-model="branchId" class="select">
          <option :value="null">{{ t('filters.allBranches') }}</option>
          <option v-for="b in catalog.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="i-cat">{{ t('filters.category') }}</label>
        <select id="i-cat" v-model="categoryId" class="select">
          <option :value="null">{{ t('filters.allCategories') }}</option>
          <option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="i-risk">{{ t('inventory.risk') }}</label>
        <select id="i-risk" v-model="riskLevel" class="select">
          <option :value="null">{{ t('filters.all') }}</option>
          <option v-for="r in RISKS" :key="r" :value="r">{{ t(`status.risk.${r}`) }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="i-type">{{ t('inventory.type') }}</label>
        <select id="i-type" v-model="type" class="select">
          <option :value="null">{{ t('filters.all') }}</option>
          <option v-for="ty in TYPES" :key="ty" :value="ty">{{ t(`inventory.types.${ty}`) }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="i-search">{{ t('filters.search') }}</label>
        <input id="i-search" v-model.trim="search" type="search" class="input" :placeholder="t('filters.searchProduct')" />
      </div>
    </section>

    <section class="card p-2 sm:p-4">
      <ErrorState v-if="recs.error.value && !noData" :error="recs.error.value" @retry="recs.execute" />
      <EmptyState v-else-if="noData && !hasSnapshot" icon="warehouse" :title="t('inventory.noInventoryTitle')" :description="t('inventory.noInventoryText')">
        <RouterLink v-if="tenant.can('imports.manage')" :to="{ name: 'import-new', query: { entity: 'inventory' } }" class="btn btn-primary btn-sm">{{ t('inventory.importInventory') }}</RouterLink>
      </EmptyState>
      <EmptyState v-else-if="noData" icon="chart" :title="t('inventory.noRecsTitle')" :description="t('inventory.noRecsText')">
        <RouterLink :to="{ name: 'forecasts' }" class="btn btn-primary btn-sm">{{ t('products.goForecasts') }}</RouterLink>
      </EmptyState>
      <template v-else>
        <DataTable v-model:sort="sort" :columns="columns" :rows="recs.data.value?.data ?? []" :row-key="(r) => r.id" :loading="recs.loading.value" :caption="t('inventory.title')">
          <template #cell-product_name="{ row }">
            <button type="button" class="text-left font-medium hover:text-brand-strong underline-offset-2 hover:underline" @click="detail = row">{{ row.product?.name ?? `#${row.product_id}` }}</button>
            <span class="block text-xs text-fg-muted">{{ row.product?.sku }} · {{ t(`inventory.types.${row.type}`) }}</span>
          </template>
          <template #cell-branch_name="{ row }">{{ row.branch?.name ?? catalog.branchName(row.branch_id) }}</template>
          <template #cell-risk_level="{ row }"><StatusBadge domain="risk" :value="row.risk_level" /></template>
          <template #cell-status="{ row }"><StatusBadge domain="recommendation" :value="row.status" /></template>
          <template #cell-actions="{ row }">
            <div v-if="tenant.can('alerts.manage') && row.status === 'open'" class="flex gap-1">
              <button type="button" class="btn btn-ghost btn-sm" :disabled="updating === row.id" :title="t('inventory.markOrdered')" @click="setStatus(row, 'ordered')"><AppIcon name="check" :size="15" /><span class="sr-only">{{ t('inventory.markOrdered') }}</span></button>
              <button type="button" class="btn btn-ghost btn-sm" :disabled="updating === row.id" :title="t('inventory.dismiss')" @click="setStatus(row, 'dismissed')"><AppIcon name="x" :size="15" /><span class="sr-only">{{ t('inventory.dismiss') }}</span></button>
            </div>
          </template>
        </DataTable>
        <PaginationBar v-model:page="page" :meta="recs.data.value?.meta ?? null" />
      </template>
    </section>

    <AppModal :open="detail !== null" :title="detail?.product?.name ?? t('inventory.detailTitle')" size="lg" @close="detail = null">
      <template v-if="detail">
        <p class="text-sm text-fg-muted mb-4">{{ detail.branch?.name ?? catalog.branchName(detail.branch_id) }} · {{ t('inventory.snapshotOn', { date: formatDate(detail.inventory_snapshot_date) }) }}</p>
        <dl class="grid sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.onHand') }}</dt><dd class="m-0 tabular font-semibold">{{ formatNumber(detail.on_hand, 1) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.reserved') }}</dt><dd class="m-0 tabular">{{ formatNumber(detail.reserved, 1) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.inTransit') }}</dt><dd class="m-0 tabular">{{ formatNumber(detail.in_transit, 1) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.available') }}</dt><dd class="m-0 tabular font-semibold">{{ formatNumber(detail.available, 1) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.leadTime') }}</dt><dd class="m-0 tabular">{{ t('common.daysValue', { n: detail.lead_time_days }) }} ({{ leadSource(detail.lead_time_source) }})</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.reviewPeriod') }}</dt><dd class="m-0 tabular">{{ t('common.daysValue', { n: detail.review_period_days }) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.demandLeadTime') }}</dt><dd class="m-0 tabular">{{ formatNumber(detail.demand_lead_time, 1) }}<template v-if="detail.demand_lead_time_std != null"> ± {{ formatNumber(detail.demand_lead_time_std, 1) }}</template></dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.serviceLevel') }}</dt><dd class="m-0 tabular">{{ formatPercent(detail.service_level, 0) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.safetyStock') }}</dt><dd class="m-0 tabular">{{ formatNumber(detail.safety_stock, 1) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.reorderPoint') }}</dt><dd class="m-0 tabular font-semibold">{{ formatNumber(detail.reorder_point, 1) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.suggestedQty') }}</dt><dd class="m-0 tabular font-semibold">{{ formatNumber(detail.suggested_qty, 0) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.investment') }}</dt><dd class="m-0 tabular">{{ formatCurrency(detail.estimated_investment, tenant.currency) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.daysOfCover') }}</dt><dd class="m-0 tabular">{{ formatNumber(detail.days_of_cover, 1) }}</dd></div>
          <div class="flex justify-between gap-2"><dt class="text-fg-muted">{{ t('inventory.stockoutDate') }}</dt><dd class="m-0 tabular">{{ formatDate(detail.stockout_date_estimate) }}</dd></div>
        </dl>
        <div class="mt-5 rounded-xl bg-surface-2 p-4 text-sm">
          <p class="font-semibold mb-1">{{ t('inventory.howCalculated') }}</p>
          <ul class="list-disc pl-5 m-0 flex flex-col gap-1 text-fg-muted">
            <li>{{ t('inventory.explainDemand', { days: detail.lead_time_days + detail.review_period_days, value: formatNumber(detail.demand_lead_time, 1) }) }}</li>
            <li>{{ t('inventory.explainSafety', { level: formatPercent(detail.service_level, 0), value: formatNumber(detail.safety_stock, 1) }) }}</li>
            <li>{{ t('inventory.explainSuggested', { rop: formatNumber(detail.reorder_point, 1), available: formatNumber(detail.available, 1), qty: formatNumber(detail.suggested_qty, 0) }) }}</li>
            <li>{{ t('inventory.explainProbability') }}</li>
          </ul>
        </div>
        <details v-if="Object.keys(detail.assumptions ?? {}).length" class="mt-3 text-sm">
          <summary class="cursor-pointer text-fg-muted">{{ t('inventory.assumptions') }}</summary>
          <ul class="mt-2 list-none p-0 flex flex-col gap-1">
            <li v-for="(value, key) in detail.assumptions" :key="key"><span class="text-fg-muted">{{ key }}:</span> {{ typeof value === 'object' ? JSON.stringify(value) : value }}</li>
          </ul>
        </details>
      </template>
      <template #footer>
        <template v-if="detail && tenant.can('alerts.manage') && detail.status === 'open'">
          <button type="button" class="btn btn-secondary" @click="setStatus(detail, 'acknowledged')">{{ t('inventory.acknowledge') }}</button>
          <button type="button" class="btn btn-primary" @click="setStatus(detail, 'ordered')">{{ t('inventory.markOrdered') }}</button>
        </template>
        <button type="button" class="btn btn-ghost" @click="detail = null">{{ t('common.close') }}</button>
      </template>
    </AppModal>
  </div>
</template>
