<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import TabsNav from '@/components/ui/TabsNav.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import TrendIndicator from '@/components/ui/TrendIndicator.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { catalogApi, exportsApi, forecastApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { CategoryForecastRow, Horizon, Product, ProductForecastRow } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatCurrency, formatDate, formatNumber, formatPercent } from '@/utils/format'
import { HORIZONS } from '@/utils/constants'

const { t, te } = useI18n()
const catalog = useCatalogStore()
const tenant = useTenantStore()
const ui = useUiStore()

const tab = ref<'products' | 'categories'>('products')
const horizon = ref<Horizon>(30)
const branchId = ref<number | null>(null)
const categoryId = ref<number | null>(null)
const search = ref('')
const sort = ref<string | null>('-forecast_units')
const page = ref(1)
const catSort = ref<string | null>('-forecast_units')

watch([horizon, branchId, categoryId, search, sort], () => (page.value = 1))

const products = useAsync(
  () => forecastApi.products({ horizon: horizon.value, branch_id: branchId.value ?? undefined, category_id: categoryId.value ?? undefined, search: search.value || undefined, sort: sort.value ?? undefined, page: page.value, per_page: 25 }),
  { watch: [horizon, branchId, categoryId, search, sort, page], debounce: 250 },
)
const categories = useAsync(
  () => forecastApi.categories({ horizon: horizon.value, branch_id: branchId.value ?? undefined, sort: catSort.value ?? undefined, per_page: 100 }),
  { watch: [horizon, branchId, catSort], immediate: false },
)
watch(tab, (v) => {
  if (v === 'categories' && !categories.data.value) void categories.execute()
})

const noForecast = computed(() => {
  const e = products.error.value
  return (isApiError(e) && e.status === 404) || (products.loaded.value && (products.data.value?.meta?.total ?? 0) === 0 && !search.value && !categoryId.value)
})
const catalogPage = ref(1)
const catalogList = useAsync(() => catalogApi.products({ page: catalogPage.value, per_page: 25, search: search.value || undefined }), { watch: [catalogPage], immediate: false })
watch(noForecast, (v) => v && void catalogList.execute(), { immediate: true })

const horizonOptions = computed(() => HORIZONS.map((h) => ({ value: h, label: t('common.days', { n: h }) })))

function modelLabel(f: string | null | undefined): string {
  if (!f) return '—'
  return te(`models.${f}`) ? t(`models.${f}`) : f
}
function classLabel(c: string | null | undefined): string {
  if (!c) return '—'
  return te(`demandClass.${c}`) ? t(`demandClass.${c}`) : c
}

const productColumns = computed<Column<ProductForecastRow>[]>(() => [
  { key: 'product.name', label: t('common.product'), sortable: true, sortKey: 'name' },
  { key: 'product.category.name', label: t('common.category') },
  { key: 'history_units', label: t('products.historyUnits', { n: horizon.value }), align: 'right', format: (r) => formatNumber(r.history_units, 0) },
  { key: 'forecast_units', label: t('products.forecastUnits', { n: horizon.value }), align: 'right', sortable: true },
  { key: 'change_pct', label: t('products.change'), align: 'right', sortable: true },
  { key: 'forecast_revenue', label: t('products.revenue'), align: 'right', sortable: true, sortKey: 'revenue', format: (r) => formatCurrency(r.forecast_revenue, tenant.currency, 0) },
  { key: 'model_family', label: t('products.model'), format: (r) => modelLabel(r.model_family) },
  { key: 'demand_class', label: t('products.demandClass'), format: (r) => classLabel(r.demand_class) },
  { key: 'wape', label: t('products.error'), align: 'right', format: (r) => formatPercent(r.wape) },
])
const categoryColumns = computed<Column<CategoryForecastRow>[]>(() => [
  { key: 'category.name', label: t('common.category'), format: (r) => r.category?.name ?? t('products.noCategory') },
  { key: 'history_units', label: t('products.historyUnits', { n: horizon.value }), align: 'right', format: (r) => formatNumber(r.history_units, 0) },
  { key: 'forecast_units', label: t('products.forecastUnits', { n: horizon.value }), align: 'right', sortable: true },
  { key: 'change_pct', label: t('products.change'), align: 'right', sortable: true },
  { key: 'forecast_revenue', label: t('products.revenue'), align: 'right', sortable: true, sortKey: 'revenue', format: (r) => formatCurrency(r.forecast_revenue, tenant.currency, 0) },
])
const catalogColumns = computed<Column<Product>[]>(() => [
  { key: 'sku', label: t('products.sku') },
  { key: 'name', label: t('common.product') },
  { key: 'category.name', label: t('common.category') },
  { key: 'stats.units_90d', label: t('products.units90'), align: 'right', format: (r) => formatNumber(r.stats?.units_90d, 0) },
  { key: 'stats.revenue_90d', label: t('products.revenue90'), align: 'right', format: (r) => formatCurrency(r.stats?.revenue_90d, tenant.currency, 0) },
  { key: 'stats.last_sale_date', label: t('products.lastSale'), format: (r) => formatDate(r.stats?.last_sale_date) },
])

async function exportCsv(): Promise<void> {
  try {
    await exportsApi.forecasts({ horizon: horizon.value, branch_id: branchId.value ?? undefined, category_id: categoryId.value ?? undefined, search: search.value || undefined, sort: sort.value ?? undefined })
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <header class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
      <div>
        <h1 class="page-title">{{ t('products.title') }}</h1>
        <p class="text-fg-muted mt-1">{{ t('products.subtitle') }}</p>
      </div>
      <button v-if="tenant.can('data.export')" type="button" class="btn btn-secondary" :disabled="noForecast" @click="exportCsv"><AppIcon name="download" :size="16" /> {{ t('common.exportCsv') }}</button>
    </header>

    <TabsNav v-model="tab" :tabs="[{ key: 'products', label: t('products.tabProducts') }, { key: 'categories', label: t('products.tabCategories') }]" :label="t('products.title')" id-prefix="prod" />

    <section class="card p-4 sm:p-5 flex flex-wrap items-end gap-4" :aria-label="t('filters.title')">
      <SegmentedControl v-model="horizon" :options="horizonOptions" :label="t('common.horizon')" />
      <div class="min-w-[10rem]">
        <label class="label" for="p-branch">{{ t('filters.branch') }}</label>
        <select id="p-branch" v-model="branchId" class="select">
          <option :value="null">{{ t('filters.allBranches') }}</option>
          <option v-for="b in catalog.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>
      <template v-if="tab === 'products'">
        <div class="min-w-[10rem]">
          <label class="label" for="p-cat">{{ t('filters.category') }}</label>
          <select id="p-cat" v-model="categoryId" class="select">
            <option :value="null">{{ t('filters.allCategories') }}</option>
            <option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div class="flex-1 min-w-[12rem]">
          <label class="label" for="p-search">{{ t('filters.search') }}</label>
          <input id="p-search" v-model.trim="search" type="search" class="input" :placeholder="t('filters.searchProduct')" />
        </div>
      </template>
    </section>

    <section v-if="tab === 'products'" id="prod-panel" role="tabpanel" aria-labelledby="prod-products" class="card p-2 sm:p-4">
      <ErrorState v-if="products.error.value && !noForecast" :error="products.error.value" @retry="products.execute" />
      <template v-else-if="noForecast">
        <EmptyState icon="chart" :title="t('products.noForecastTitle')" :description="t('products.noForecastText')" compact>
          <RouterLink :to="{ name: 'forecasts' }" class="btn btn-primary btn-sm">{{ t('products.goForecasts') }}</RouterLink>
        </EmptyState>
        <h2 class="section-title px-2 mt-2 mb-2">{{ t('products.catalogTitle') }}</h2>
        <DataTable :columns="catalogColumns" :rows="catalogList.data.value?.data ?? []" :row-key="(r) => r.id" :loading="catalogList.loading.value" :caption="t('products.catalogTitle')" :empty-text="t('products.noProducts')" />
        <PaginationBar v-model:page="catalogPage" :meta="catalogList.data.value?.meta ?? null" />
      </template>
      <template v-else>
        <DataTable v-model:sort="sort" :columns="productColumns" :rows="products.data.value?.data ?? []" :row-key="(r) => r.product.id" :loading="products.loading.value" :caption="t('products.tabProducts')">
          <template #cell-product_name="{ row }">
            <span class="font-medium block">{{ row.product.name }}</span>
            <span class="text-xs text-fg-muted">{{ row.product.sku }}</span>
          </template>
          <template #cell-forecast_units="{ row }">
            <span class="font-semibold">{{ formatNumber(row.forecast_units, 0) }}</span>
            <span v-if="row.lo_80 != null && row.hi_80 != null" class="block text-xs text-fg-muted">{{ formatNumber(row.lo_80, 0) }}–{{ formatNumber(row.hi_80, 0) }}</span>
          </template>
          <template #cell-change_pct="{ row }">
            <TrendIndicator :trend="row.trend" :change="row.change_pct" />
            <span v-if="row.low_base" class="block text-xs text-fg-muted" :title="t('products.lowBaseHint')">{{ t('products.lowBase') }}</span>
          </template>
        </DataTable>
        <PaginationBar v-model:page="page" :meta="products.data.value?.meta ?? null" />
        <p class="hint px-2 pb-2">{{ t('products.tableNote') }}</p>
      </template>
    </section>

    <section v-else id="prod-panel" role="tabpanel" aria-labelledby="prod-categories" class="card p-2 sm:p-4">
      <ErrorState v-if="categories.error.value" :error="categories.error.value" @retry="categories.execute" />
      <DataTable v-else v-model:sort="catSort" :columns="categoryColumns" :rows="categories.data.value?.data ?? []" :row-key="(r) => r.category?.id ?? 0" :loading="categories.loading.value" :caption="t('products.tabCategories')" :empty-text="t('products.noForecastTitle')">
        <template #cell-forecast_units="{ row }">
          <span class="font-semibold">{{ formatNumber(row.forecast_units, 0) }}</span>
          <span v-if="row.lo_80 != null && row.hi_80 != null" class="block text-xs text-fg-muted">{{ formatNumber(row.lo_80, 0) }}–{{ formatNumber(row.hi_80, 0) }}</span>
        </template>
        <template #cell-change_pct="{ row }"><TrendIndicator :trend="row.trend" :change="row.change_pct" /></template>
      </DataTable>
    </section>
  </div>
</template>
