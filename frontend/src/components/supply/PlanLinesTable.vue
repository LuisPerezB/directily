<script setup lang="ts">
/**
 * Líneas de un plan de compras, paginadas y filtradas en el servidor (un plan real tiene miles). Filtros: sucursal, búsqueda de
 * producto y «solo lo que se emite en la fecha de corte». La insignia de las líneas del día de corte dice «Emitir ya» solo si ese
 * corte es hoy (zona horaria de la empresa); si el plan se calculó con datos anteriores, dice «Emitir al corte».
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { supplyApi } from '@/api'
import type { PlanBranch, PlanLine, PlanLinesQuery, PlanListSummary } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatCurrency, formatDate, formatNumber } from '@/utils/format'
import type { PlanCutoff } from '@/utils/supply'

const PER_PAGE = 25
const props = defineProps<{ runId: number; summary: PlanListSummary; branches: PlanBranch[]; cutoff: PlanCutoff; currency?: string | null }>()
const { t, te } = useI18n()
const tenant = useTenantStore()

const branchId = ref<number | null>(null)
const search = ref('')
const onlyNow = ref(false)
const sort = ref<string | null>(null)
const page = ref(1)
watch([branchId, search, onlyNow, sort], () => (page.value = 1))

const query = computed<PlanLinesQuery>(() => ({
  page: page.value,
  per_page: PER_PAGE,
  branch_id: branchId.value ?? undefined,
  search: search.value.trim() || undefined,
  actionable_now: onlyNow.value || undefined,
  sort: sort.value ?? undefined,
}))
const result = useAsync(() => supplyApi.planLines(props.runId, query.value), { watch: [query], debounce: 250, immediate: props.summary.total > 0 })
const rows = computed(() => result.data.value?.data ?? [])
const filtered = computed(() => branchId.value !== null || search.value.trim() !== '' || onlyNow.value)
const money = (v: number | null | undefined, d = 2): string => formatCurrency(v, props.currency ?? tenant.currency, d)
const familyText = (f: string): string => (te(`supply.families.${f}`) ? t(`supply.families.${f}`) : f)

const countText = computed(() =>
  props.cutoff.isToday
    ? t('supply.plan.linesCountNow', { n: formatNumber(props.summary.total, 0), now: formatNumber(props.summary.actionable_now ?? 0, 0) })
    : t('supply.plan.linesCountCutoff', { n: formatNumber(props.summary.total, 0), now: formatNumber(props.summary.actionable_now ?? 0, 0) }),
)
const onlyNowLabel = computed(() => (props.cutoff.isToday ? t('supply.plan.onlyNow') : t('supply.plan.onlyAtCutoff')))
const issueBadge = computed(() => (props.cutoff.isToday ? t('supply.plan.issueNow') : t('supply.plan.issueAtCutoff')))
const issueHint = computed<string | undefined>(() => {
  if (props.cutoff.isToday) return undefined
  return props.cutoff.asOf ? t('supply.plan.issueAtCutoffHint', { date: formatDate(props.cutoff.asOf, 'long') }) : t('supply.plan.issueAtCutoffHintNoDate')
})

function clearFilters(): void {
  branchId.value = null
  search.value = ''
  onlyNow.value = false
}

const columns = computed<Column<PlanLine>[]>(() => [
  { key: 'product', label: t('common.product'), sortable: true, class: 'min-w-[12rem]' },
  { key: 'branch', label: t('common.branch'), sortable: true },
  { key: 'supplier', label: t('supply.plan.supplier'), sortable: true },
  { key: 'quantity', label: t('supply.plan.quantity'), align: 'right', sortable: true },
  { key: 'order_date', label: t('supply.plan.issueDate'), sortable: true },
  { key: 'expected_receipt_date', label: t('supply.plan.receiptDate'), sortable: true },
  { key: 'unit_cost', label: t('supply.plan.unitCost'), align: 'right', sortable: true },
  { key: 'line_cost', label: t('supply.plan.lineCost'), align: 'right', sortable: true },
  { key: 'constraints', label: t('supply.plan.constraints') },
])
</script>

<template>
  <div data-test="plan-lines-section">
    <h3 class="section-title mb-2">{{ t('supply.plan.linesTitle') }} <span class="text-sm font-normal text-fg-muted" data-test="lines-count">({{ countText }})</span></h3>
    <p v-if="summary.total === 0" class="text-sm text-fg-muted m-0" data-test="no-lines">{{ t('supply.plan.noLines') }}</p>
    <template v-else>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 items-end mb-3" role="search" :aria-label="t('supply.plan.filtersLabel')" data-test="lines-filters">
        <div>
          <label class="label" for="pl-lines-branch">{{ t('filters.branch') }}</label>
          <select id="pl-lines-branch" v-model="branchId" class="select" data-test="filter-branch">
            <option :value="null">{{ t('filters.allBranches') }}</option>
            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="pl-lines-search">{{ t('filters.search') }}</label>
          <input id="pl-lines-search" v-model="search" type="search" class="input" :placeholder="t('filters.searchProduct')" maxlength="100" data-test="filter-search" />
        </div>
        <label class="flex items-center gap-2 text-sm pb-2"><input v-model="onlyNow" type="checkbox" data-test="filter-only-now" /> {{ onlyNowLabel }}</label>
        <div class="pb-1"><button v-if="filtered" type="button" class="btn btn-ghost btn-sm" data-test="clear-filters" @click="clearFilters">{{ t('supply.plan.clearFilters') }}</button></div>
      </div>

      <ErrorState v-if="result.error.value" :error="result.error.value" compact @retry="result.execute" />
      <template v-else>
        <DataTable
          v-model:sort="sort" :columns="columns" :rows="rows" :row-key="(r) => r.id ?? `${r.branch_id}-${r.product_id}-${r.order_date}`" :loading="result.loading.value"
          :caption="t('supply.plan.linesTitle')" :empty-text="filtered ? t('supply.plan.noLinesMatch') : t('supply.plan.noLines')" data-test="plan-lines"
        >
          <template #cell-product="{ row }"><span class="font-medium block">{{ row.product?.name ?? `#${row.product_id}` }}</span><span class="text-xs text-fg-muted">{{ row.product?.sku }}</span><span v-if="row.line_type === 'production'" class="badge ml-1">{{ t('supply.plan.production') }}</span></template>
          <template #cell-branch="{ row }">{{ row.branch?.name ?? '—' }}</template>
          <template #cell-supplier="{ row }">{{ row.supplier?.name ?? '—' }}</template>
          <template #cell-quantity="{ row }">{{ formatNumber(row.quantity, 2) }} <span class="text-xs text-fg-muted">{{ row.unit }}</span><span v-if="row.offer_quantity != null" class="block text-xs text-fg-muted">{{ formatNumber(row.offer_quantity, 2) }} {{ row.offer_unit }}</span></template>
          <template #cell-order_date="{ row }">{{ formatDate(row.order_date) }}<span v-if="row.actionable_now" class="badge block w-fit mt-1" :class="cutoff.isToday ? 'badge-success' : 'badge-info'" :title="issueHint" :data-kind="cutoff.isToday ? 'now' : 'cutoff'" data-test="actionable-now">{{ issueBadge }}</span></template>
          <template #cell-expected_receipt_date="{ row }">{{ formatDate(row.expected_receipt_date) }}</template>
          <template #cell-unit_cost="{ row }">{{ row.unit_cost != null ? money(row.unit_cost, 4) : '—' }}</template>
          <template #cell-line_cost="{ row }">{{ row.line_cost != null ? money(row.line_cost, 2) : '—' }}</template>
          <template #cell-constraints="{ row }">
            <span v-for="(b, k) in row.binding_constraints ?? []" :key="k" class="badge badge-warning mr-1" :title="`${formatNumber(b.used, 2)} / ${formatNumber(b.limit, 2)}`" data-test="binding">{{ familyText(b.family) }}</span>
            <span v-if="!(row.binding_constraints ?? []).length" class="text-fg-muted">—</span>
            <details v-if="row.justification" class="text-xs mt-1"><summary class="cursor-pointer text-fg-muted">{{ t('supply.plan.why') }}</summary><p class="m-0 max-w-xs">{{ row.justification }}</p></details>
          </template>
        </DataTable>
        <PaginationBar v-model:page="page" :meta="result.data.value?.meta ?? null" />
      </template>
    </template>
  </div>
</template>
