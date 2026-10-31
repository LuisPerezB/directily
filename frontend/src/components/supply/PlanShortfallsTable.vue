<script setup lang="ts">
/** Demanda no atendida de un plan, paginada y filtrada en el servidor (sucursal y producto), con la causa de cada faltante. */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { supplyApi } from '@/api'
import type { PlanBranch, PlanListSummary, PlanShortfall, PlanShortfallsQuery } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { formatDate, formatNumber, formatPercent } from '@/utils/format'

const PER_PAGE = 25
const props = defineProps<{ runId: number; summary: PlanListSummary; branches: PlanBranch[] }>()
const { t, te } = useI18n()

const branchId = ref<number | null>(null)
const search = ref('')
const sort = ref<string | null>(null)
const page = ref(1)
watch([branchId, search, sort], () => (page.value = 1))

const query = computed<PlanShortfallsQuery>(() => ({
  page: page.value,
  per_page: PER_PAGE,
  branch_id: branchId.value ?? undefined,
  search: search.value.trim() || undefined,
  sort: sort.value ?? undefined,
}))
const result = useAsync(() => supplyApi.planShortfalls(props.runId, query.value), { watch: [query], debounce: 250 })
const rows = computed(() => result.data.value?.data ?? [])
const filtered = computed(() => branchId.value !== null || search.value.trim() !== '')
const reasonText = (r: string | null | undefined): string => (r && te(`supply.shortfall.${r}`) ? t(`supply.shortfall.${r}`) : (r ?? t('supply.shortfall.unknown')))
// Totales de TODO el plan (los suma el servidor): con paginación no se pueden sumar las filas de la página.
const share = computed(() => ((props.summary.demand_units ?? 0) > 0 ? (props.summary.unmet_units ?? 0) / (props.summary.demand_units ?? 1) : null))

function clearFilters(): void {
  branchId.value = null
  search.value = ''
}

const columns = computed<Column<PlanShortfall>[]>(() => [
  { key: 'product', label: t('common.product'), sortable: true, class: 'min-w-[12rem]' },
  { key: 'branch', label: t('common.branch'), sortable: true },
  { key: 'period_start', label: t('supply.plan.period'), sortable: true },
  { key: 'demand', label: t('supply.plan.demand'), align: 'right', sortable: true },
  { key: 'unmet', label: t('supply.plan.unmet'), align: 'right', sortable: true },
  { key: 'reason', label: t('supply.plan.reason'), sortable: true },
])
</script>

<template>
  <div>
    <p class="text-sm text-fg-muted mb-2" data-test="shortfalls-summary">{{ t('supply.plan.shortfallsText', { units: formatNumber(summary.unmet_units ?? 0, 0), pct: share != null ? formatPercent(share, 1) : '—' }) }}</p>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 items-end mb-3" role="search" :aria-label="t('supply.plan.shortfallFiltersLabel')" data-test="shortfalls-filters">
      <div>
        <label class="label" for="pl-short-branch">{{ t('filters.branch') }}</label>
        <select id="pl-short-branch" v-model="branchId" class="select" data-test="shortfall-filter-branch">
          <option :value="null">{{ t('filters.allBranches') }}</option>
          <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="pl-short-search">{{ t('filters.search') }}</label>
        <input id="pl-short-search" v-model="search" type="search" class="input" :placeholder="t('filters.searchProduct')" maxlength="100" data-test="shortfall-filter-search" />
      </div>
      <div class="pb-1"><button v-if="filtered" type="button" class="btn btn-ghost btn-sm" data-test="shortfall-clear-filters" @click="clearFilters">{{ t('supply.plan.clearFilters') }}</button></div>
    </div>
    <ErrorState v-if="result.error.value" :error="result.error.value" compact @retry="result.execute" />
    <template v-else>
      <DataTable
        v-model:sort="sort" :columns="columns" :rows="rows" :row-key="(r) => r.id ?? `${r.branch_id}-${r.product_id}-${r.period_start}-${r.reason}`" :loading="result.loading.value"
        :caption="t('supply.plan.shortfallsTitle')" :empty-text="filtered ? t('supply.plan.noShortfallsMatch') : t('supply.plan.noShortfalls')" data-test="shortfalls-table"
      >
        <template #cell-product="{ row }">{{ row.product?.name ?? `#${row.product_id}` }}<span v-if="row.product?.sku" class="block text-xs text-fg-muted">{{ row.product.sku }}</span></template>
        <template #cell-branch="{ row }">{{ row.branch?.name ?? '—' }}</template>
        <template #cell-period_start="{ row }">{{ formatDate(row.period_start, 'short') }} – {{ formatDate(row.period_end, 'short') }}</template>
        <template #cell-demand="{ row }">{{ formatNumber(row.demand, 1) }}</template>
        <template #cell-unmet="{ row }">{{ formatNumber(row.unmet, 1) }}</template>
        <template #cell-reason="{ row }"><span class="badge badge-warning" data-test="shortfall-reason">{{ reasonText(row.reason) }}</span></template>
      </DataTable>
      <PaginationBar v-model:page="page" :meta="result.data.value?.meta ?? null" />
    </template>
  </div>
</template>
