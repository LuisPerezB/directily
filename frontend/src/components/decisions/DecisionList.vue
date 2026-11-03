<script setup lang="ts">
/** Lista de recomendaciones con filtros, orden y paginado del servidor. Las del grupo de control no llegan aquí salvo `include_control` (que esta lista nunca pide). */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import WorkflowBadge from '@/components/enterprise/WorkflowBadge.vue'
import { decisionApi } from '@/api'
import type { DecisionQuery, DecisionRecommendation } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatDate, formatNumber } from '@/utils/format'
import { isKnown } from '@/utils/enterprise'

const props = defineProps<{ selectedId?: number | null; refreshKey?: number }>()
const emit = defineEmits<{ select: [id: number] }>()
const { t } = useI18n()
const tenant = useTenantStore()

const IN_PROGRESS = 'suggested,assigned,reviewed,approved,in_progress'
const statusFilter = ref<string>(IN_PROGRESS)
const kind = ref('')
const mine = ref(false)
const approval = ref<'' | 'yes' | 'no'>('')
const sort = ref<NonNullable<DecisionQuery['sort']>>('-score')
const page = ref(1)
watch([statusFilter, kind, mine, approval, sort], () => (page.value = 1))

const query = computed<DecisionQuery>(() => ({
  status: statusFilter.value || undefined,
  kind: kind.value || undefined,
  mine: mine.value || undefined,
  requires_approval: approval.value === '' ? undefined : approval.value === 'yes',
  sort: sort.value,
  page: page.value,
  per_page: 20,
}))
const list = useAsync(() => decisionApi.list(query.value), { watch: [query, () => props.refreshKey], scope: () => tenant.currentUuid })
const rows = computed(() => list.data.value?.data ?? [])

function entityOf(r: DecisionRecommendation): string {
  const parts: string[] = []
  if (r.customer) parts.push(r.customer.external_code ?? `#${r.customer.id}`)
  if (r.product) parts.push(r.product.name ?? r.product.sku ?? `#${r.product.id}`)
  if (r.branch) parts.push(r.branch.name ?? r.branch.code ?? `#${r.branch.id}`)
  return parts.join(' · ') || '—'
}
const allColumns = computed<Column<DecisionRecommendation>[]>(() => [
  { key: 'recommendation', label: t('decisions.cols.recommendation') },
  { key: 'as_of', label: t('decisions.cols.cutoff') },
  { key: 'status', label: t('decisions.cols.status') },
  { key: 'value', label: t('decisions.cols.value') },
  { key: 'score', label: t('decisions.cols.score'), align: 'right' },
  { key: 'expires_at', label: t('decisions.cols.expires') },
  { key: 'actions', label: t('enterprise.actionsHeader') },
])
// Con una recomendación abierta la lista es una columna estrecha: se dejan solo las columnas que identifican y ordenan el trabajo.
const columns = computed(() => (props.selectedId ? allColumns.value.filter((c) => ['recommendation', 'status', 'actions'].includes(c.key)) : allColumns.value))
const statusOptions = ['suggested', 'assigned', 'reviewed', 'approved', 'in_progress', 'executed', 'measured', 'closed', 'rejected', 'expired']
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('decisions.list.title')" data-test="decision-list">
    <div class="grid gap-3 sm:grid-cols-2 items-end">
      <div>
        <label class="label" for="dl-status">{{ t('decisions.filters.status') }}</label>
        <select id="dl-status" v-model="statusFilter" class="select">
          <option :value="IN_PROGRESS">{{ t('decisions.filters.inProgress') }}</option>
          <option value="">{{ t('decisions.filters.allStatuses') }}</option>
          <option v-for="s in statusOptions" :key="s" :value="s">{{ t(`decisions.status.${s}`) }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="dl-kind">{{ t('radar.filters.kind') }}</label>
        <select id="dl-kind" v-model="kind" class="select">
          <option value="">{{ t('radar.filters.all') }}</option>
          <option v-for="k in ['contact', 'recovery', 'customer_risk', 'replenishment', 'inventory_mismatch', 'budget_constraint', 'scenario']" :key="k" :value="k">{{ t(`decisions.kind.${k}`) }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="dl-approval">{{ t('decisions.filters.approval') }}</label>
        <select id="dl-approval" v-model="approval" class="select">
          <option value="">{{ t('radar.filters.all') }}</option>
          <option value="yes">{{ t('decisions.filters.requiresApproval') }}</option>
          <option value="no">{{ t('decisions.filters.noApproval') }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="dl-sort">{{ t('customers.orderBy') }}</label>
        <select id="dl-sort" v-model="sort" class="select">
          <option v-for="s in ['-score', '-priority', 'priority', 'expires_at', '-created_at', '-as_of']" :key="s" :value="s">{{ t(`decisions.sort.${s.replace('-', 'desc_')}`) }}</option>
        </select>
      </div>
      <label class="inline-flex items-center gap-2 text-sm pb-2 sm:col-span-2"><input v-model="mine" type="checkbox" data-test="filter-mine"> {{ t('decisions.filters.mine') }}</label>
    </div>

    <ErrorState v-if="list.error.value" :error="list.error.value" compact @retry="list.execute" />
    <template v-else>
      <DataTable :columns="columns" :rows="rows" :row-key="(r) => r.id" :loading="list.loading.value" :caption="t('decisions.list.title')" :empty-text="t('decisions.list.empty')">
        <template #cell-recommendation="{ row }">
          <span class="font-medium block">{{ t(`decisions.kind.${row.kind}`) }} #{{ row.id }}</span>
          <span class="text-sm block">{{ entityOf(row) }}</span>
          <span class="text-xs text-fg-muted">{{ t('radar.priority', { n: row.priority }) }} · {{ t('common.days', { n: row.horizon_days }) }}</span>
        </template>
        <template #cell-as_of="{ row }">{{ formatDate(row.as_of) }}</template>
        <template #cell-status="{ row }">
          <WorkflowBadge domain="workflow" :value="row.status" />
          <span v-if="row.requires_approval" class="block text-xs text-fg-muted mt-1">{{ t('decisions.filters.requiresApproval') }}</span>
        </template>
        <template #cell-value="{ row }">
          <span class="block text-xs text-fg-muted">{{ t('decisions.detail.contribution') }}</span>
          <MoneyFigure :value="row.expected_contribution" :currency="row.currency" :unknown-label="t('enterprise.notEstimatedF')" />
          <span class="block mt-1"><WorkflowBadge domain="attribution" :value="row.attribution_label" /></span>
        </template>
        <template #cell-score="{ row }">{{ isKnown(row.score) ? formatNumber(row.score, 2) : '—' }}</template>
        <template #cell-expires_at="{ row }">{{ row.expires_at ? formatDate(row.expires_at) : '—' }}</template>
        <template #cell-actions="{ row }">
          <button type="button" class="btn btn-sm" :class="row.id === selectedId ? 'btn-primary' : 'btn-ghost'" :aria-pressed="row.id === selectedId" data-test="select-decision" @click="emit('select', row.id)">{{ t('radar.detail') }}</button>
        </template>
      </DataTable>
      <PaginationBar v-model:page="page" :meta="list.data.value?.meta ?? null" />
    </template>
  </section>
</template>
