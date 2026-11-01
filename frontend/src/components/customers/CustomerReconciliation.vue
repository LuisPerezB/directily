<script setup lang="ts">
/**
 * Conciliación por producto: demanda agregada = compradores modelados + comprometido fuera del modelo + residual.
 * Las dos vistas no se suman. Si compradores + comprometido superan el agregado, la fila se destaca como inconsistente
 * (residual 0 y exceso informado) y las probabilidades individuales no se alteran.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import { customerApi } from '@/api'
import type { CustomerHorizon, ReconciliationStatus } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { horizonNotAvailable, reconciliationParts, RECONCILIATION_STATUSES, sortReconciliation } from '@/utils/customers'
import { formatNumber, formatPercent } from '@/utils/format'

const props = defineProps<{ horizon: CustomerHorizon }>()
const { t } = useI18n()
const status = ref<ReconciliationStatus | ''>('')
const page = ref(1)
watch([() => props.horizon, status], () => (page.value = 1))

const result = useAsync(
  () => customerApi.reconciliation({ horizon: props.horizon, status: status.value || undefined, page: page.value, per_page: 25 }),
  { watch: [() => props.horizon, status, page] },
)
const unavailable = computed(() => horizonNotAvailable(result.error.value))
const rows = computed(() => sortReconciliation(result.data.value?.data ?? []))
const counts = computed(() => result.data.value?.summary?.status_counts ?? {})
const inconsistent = computed(() => counts.value.inconsistent ?? 0)
const n = (v: number | null | undefined): string => (v == null ? '—' : formatNumber(v, 1))
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('customers.tabs.reconciliation')" data-test="customer-reconciliation">
    <p class="text-sm text-fg-muted m-0">{{ t('customers.reconciliation.intro') }}</p>
    <ErrorState v-if="result.error.value && !unavailable" :error="result.error.value" @retry="result.execute" />
    <EmptyState v-else-if="unavailable" icon="layers" :title="t('customers.horizonUnavailableTitle', { n: horizon })" :description="t('customers.horizonUnavailableText')" />
    <template v-else>
      <InlineNotice v-if="inconsistent > 0" kind="danger" :title="t('customers.reconciliation.inconsistentTitle', { n: inconsistent })" data-test="inconsistent-banner">{{ t('customers.reconciliation.inconsistentText') }}</InlineNotice>
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="label" for="rc-status">{{ t('common.status') }}</label>
          <select id="rc-status" v-model="status" class="select !w-auto">
            <option value="">{{ t('customers.reconciliation.allStatuses') }}</option>
            <option v-for="s in RECONCILIATION_STATUSES" :key="s" :value="s">{{ t(`customers.reconciliation.status.${s}`) }} ({{ counts[s] ?? 0 }})</option>
          </select>
        </div>
      </div>
      <EmptyState v-if="result.loaded.value && rows.length === 0" icon="layers" :title="t('customers.reconciliation.emptyTitle')" :description="t('customers.reconciliation.emptyText')" compact />
      <div v-else class="table-wrap">
        <table class="table">
          <caption class="sr-only">{{ t('customers.tabs.reconciliation') }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('common.product') }}</th>
              <th scope="col">{{ t('common.status') }}</th>
              <th scope="col" class="num">{{ t('customers.reconciliation.aggregate') }}</th>
              <th scope="col" class="num">{{ t('customers.reconciliation.buyers') }}</th>
              <th scope="col" class="num">{{ t('customers.reconciliation.committedOutside') }}</th>
              <th scope="col" class="num">{{ t('customers.reconciliation.residual') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rows" :key="r.product.id" :class="r.status === 'inconsistent' ? 'bg-danger-soft' : ''" :data-status="r.status">
              <td><span class="font-medium block">{{ r.product.name ?? `#${r.product.id}` }}</span><span class="text-xs text-fg-muted">{{ r.product.sku }} · {{ r.product.unit }}</span></td>
              <td>
                <span class="badge" :class="r.status === 'inconsistent' ? 'badge-danger' : r.status === 'ok' ? 'badge-success' : 'badge-warning'">{{ t(`customers.reconciliation.status.${r.status}`) }}</span>
                <span v-if="r.status === 'inconsistent' && r.severity" class="block text-xs text-danger">{{ t(`customers.reconciliation.severity.${r.severity}`) }}</span>
              </td>
              <td class="num">{{ n(reconciliationParts(r).aggregate) }}<span v-if="r.aggregate_days != null" class="block text-xs text-fg-muted">{{ t('customers.reconciliation.days', { n: r.aggregate_days }) }}</span></td>
              <td class="num">
                {{ n(reconciliationParts(r).buyers) }}
                <span v-if="reconciliationParts(r).decomposable && r.buyers_committed_quantity != null" class="block text-xs text-fg-muted">{{ t('customers.reconciliation.buyersSplit', { committed: n(r.buyers_committed_quantity), incremental: n(r.buyers_incremental_quantity) }) }}</span>
              </td>
              <td class="num">{{ n(reconciliationParts(r).committedOutside) }}</td>
              <td class="num">
                <template v-if="r.status === 'inconsistent'">
                  0 <span class="block text-xs text-danger" data-test="excess">{{ t('customers.reconciliation.excess', { qty: n(r.excess_quantity), pct: formatPercent(r.excess_ratio, 0) }) }}</span>
                </template>
                <template v-else-if="reconciliationParts(r).decomposable">{{ n(reconciliationParts(r).residual) }}<span v-if="r.residual_share != null" class="block text-xs text-fg-muted">{{ formatPercent(r.residual_share, 0) }}</span></template>
                <span v-else class="text-fg-muted" :title="t(`customers.reconciliation.hint.${r.status}`)">{{ t('customers.reconciliation.notComputed') }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-model:page="page" :meta="result.data.value?.meta ?? null" />
      <p v-if="result.data.value?.policy" class="hint m-0" data-test="reconciliation-policy">{{ result.data.value.policy }}</p>
      <EnvelopePanel :envelope="result.data.value?.envelope" />
    </template>
  </section>
</template>
