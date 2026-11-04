<script setup lang="ts">
/**
 * Lista de oportunidades del Revenue Radar. Seller-facing: NO muestra el grupo del experimento (la API solo lo entrega a quien aprueba y la interfaz lo
 * reserva al detalle del Centro de decisiones). Sin envíos: «Registrar seguimiento» anota lo que una persona hizo.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import CustomerWindowCell from '@/components/customers/CustomerWindowCell.vue'
import OpportunityValue from './OpportunityValue.vue'
import InventoryCell from './InventoryCell.vue'
import WorkflowBadge from '@/components/enterprise/WorkflowBadge.vue'
import type { RadarOpportunity } from '@/api/types'
import { useAbility } from '@/composables/useAbility'
import { formatNumber, formatProbability } from '@/utils/format'
import { canFollowUp } from '@/utils/radar'

const props = defineProps<{ rows: RadarOpportunity[]; loading?: boolean; currency?: string | null; caption: string }>()
const emit = defineEmits<{ open: [id: number]; feedback: [id: number] }>()
const { t } = useI18n()
const { can } = useAbility()

const columns = computed<Column<RadarOpportunity>[]>(() => [
  { key: 'opportunity', label: t('radar.cols.opportunity') },
  { key: 'signal', label: t('radar.cols.signal'), class: 'min-w-[9rem]' },
  { key: 'value', label: t('radar.cols.value'), class: 'min-w-[11rem]' },
  { key: 'inventory', label: t('radar.cols.inventory') },
  { key: 'state', label: t('radar.cols.state'), class: 'min-w-[10rem]' },
  { key: 'actions', label: t('enterprise.actionsHeader') },
])

function followUp(row: RadarOpportunity): boolean {
  return canFollowUp(row, can)
}
</script>

<template>
  <DataTable :columns="columns" :rows="props.rows" :row-key="(r) => r.id" :loading="loading" :caption="caption" :empty-text="t('radar.noRows')">
    <template #cell-opportunity="{ row }">
      <RouterLink :to="{ name: 'customer-detail', params: { id: String(row.customer.id) } }" class="font-medium" data-test="customer-link">{{ row.customer.code ?? `#${row.customer.id}` }}</RouterLink>
      <span class="block text-sm">{{ row.product ? (row.product.name ?? `#${row.product.id}`) : t('radar.wholeCustomer') }}</span>
      <span v-if="row.product?.sku" class="block text-xs text-fg-muted">{{ row.product.sku }}</span>
      <span class="block text-xs text-fg-muted">{{ t(`decisions.kind.${row.kind}`) }} · {{ t('common.days', { n: row.horizon_days }) }} · {{ t('radar.priority', { n: row.priority }) }}</span>
    </template>
    <template #cell-signal="{ row }">
      <template v-if="row.entity_type === 'customer'">
        <span class="text-fg-muted text-sm" data-test="no-probability">{{ t('radar.customerLevel') }}</span>
      </template>
      <template v-else>
        <span v-if="row.probability === null" class="text-fg-muted text-sm" data-test="no-probability">{{ t('customers.noProbability') }}</span>
        <span v-else class="font-semibold" data-test="probability">{{ formatProbability(row.probability) }}</span>
        <span v-if="row.expected_orders !== null" class="block text-xs text-fg-muted">{{ t('radar.expectedOrders', { n: formatNumber(row.expected_orders, 2) }) }}</span>
        <span v-if="row.expected_units !== null" class="block text-xs text-fg-muted">{{ t('radar.expectedUnits', { n: formatNumber(row.expected_units, 1), unit: row.product?.unit ?? '' }) }}</span>
        <div class="mt-2"><span class="block text-xs text-fg-muted">{{ t('customers.window.title') }}</span><CustomerWindowCell :window="row.window" /></div>
      </template>
    </template>
    <template #cell-value="{ row }"><OpportunityValue :opportunity="row" :currency="currency" /></template>
    <template #cell-inventory="{ row }"><InventoryCell :inventory="row.inventory" :unit="row.product?.unit" /></template>
    <template #cell-state="{ row }">
      <WorkflowBadge domain="agenda" :value="row.state" />
      <span class="block mt-1"><WorkflowBadge domain="workflow" :value="row.status" /></span>
      <span v-if="row.assigned_user" class="block text-xs text-fg-muted mt-1">{{ t('radar.assignedTo', { name: row.assigned_user.name ?? `#${row.assigned_user.id}` }) }}</span>
      <span v-if="row.follow_up.snoozed_until" class="block text-xs text-fg-muted">{{ t('radar.snoozedUntil', { date: row.follow_up.snoozed_until }) }}</span>
      <p v-if="row.next_step" class="m-0 mt-2 text-xs"><span class="font-semibold">{{ t('radar.cols.next') }}:</span> {{ row.next_step }}</p>
    </template>
    <template #cell-actions="{ row }">
      <div class="flex flex-col gap-1 items-start">
        <button type="button" class="btn btn-ghost btn-sm" data-test="open-detail" @click="emit('open', row.id)">{{ t('radar.detail') }}</button>
        <button v-if="followUp(row)" type="button" class="btn btn-secondary btn-sm !whitespace-normal text-left" data-test="open-feedback" @click="emit('feedback', row.id)">{{ t('radar.feedback.open') }}</button>
      </div>
    </template>
  </DataTable>
</template>
