<script setup lang="ts">
/**
 * Pronóstico de costos unitarios de compra (no de cantidades). Se compara el método servido con la referencia simple
 * («último valor») en un holdout temporal; si ningún método la mejora, se sirve la referencia y se dice. Menos de 6 períodos
 * observados es una abstención explícita. Los intervalos desconocidos se muestran como desconocidos.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import JobProgress from '@/components/forecast/JobProgress.vue'
import { supplyApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { CostForecast, SupplyRun } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useRunJob } from '@/composables/useRunJob'
import { useUiStore } from '@/stores/ui'
import { noteTexts } from '@/utils/envelope'
import { formatDate, formatNumber } from '@/utils/format'

const { t, te } = useI18n()
const { can } = useAbility()
const ui = useUiStore()
const policy = ref<'strict' | 'assume_valid_from'>('strict')
const page = ref(1)
const message = ref<string | null>(null)
const result = useAsync(() => supplyApi.costForecasts({ page: page.value, per_page: 25 }), { watch: [page] })
const rows = computed(() => result.data.value?.data ?? [])
const meta = computed(() => {
  const m = result.data.value?.meta
  return m && 'current_page' in m ? m : null
})
const open = ref<number | null>(null)

const tracker = useRunJob<SupplyRun>((job) => {
  if (job.status === 'succeeded') ui.notify(t('supply.costs.ready'), 'success')
  else ui.notify(job.error_message || t('jobs.failed'), 'error', 9000)
  void result.execute()
})
async function request(): Promise<void> {
  message.value = null
  try {
    await tracker.start(() => supplyApi.requestCostForecast({ null_known_at_policy: policy.value }))
  } catch (e) {
    message.value = isApiError(e) && e.status === 409 ? t('supply.costs.inProgress') : errorMessage(e, t)
  }
}

const methodText = (m: string | null): string => (m && te(`supply.costs.methods.${m}`) ? t(`supply.costs.methods.${m}`) : (m ?? '—'))
const holdoutMae = (c: CostForecast, method: string | null): number | null => {
  const v = method ? c.metrics?.[method]?.holdout?.mae : null
  return typeof v === 'number' ? v : null
}
/** Se sirvió la referencia: ningún método la mejoró lo suficiente (o no hubo datos para compararlos). */
const servedReference = (c: CostForecast): boolean => !!c.method && c.method === c.reference_method
const lastPoint = (c: CostForecast) => c.points?.at(-1) ?? null

const columns = computed<Column<CostForecast>[]>(() => [
  { key: 'product', label: t('common.product'), format: (c) => c.product?.name ?? `#${c.product_id}` },
  { key: 'supplier', label: t('supply.plan.supplier'), format: (c) => c.supplier?.name ?? '—' },
  { key: 'method', label: t('supply.costs.servedMethod') },
  { key: 'holdout', label: t('supply.costs.holdout'), align: 'right' },
  { key: 'last', label: t('supply.costs.lastObserved'), align: 'right' },
  { key: 'forecast', label: t('supply.costs.lastPoint'), align: 'right' },
  { key: 'quality_status', label: t('envelope.quality') },
  { key: 'open', label: '' },
])
const detail = computed(() => rows.value.find((r) => r.id === open.value) ?? null)
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('supply.tabs.costs')" data-test="cost-tab">
    <p class="text-sm text-fg-muted m-0 max-w-3xl">{{ t('supply.costs.intro') }}</p>
    <div v-if="can('forecast.run')" class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label" for="cf-policy">{{ t('supply.costs.policy') }}</label>
        <select id="cf-policy" v-model="policy" class="select !w-auto"><option value="strict">{{ t('supply.costs.policies.strict') }}</option><option value="assume_valid_from">{{ t('supply.costs.policies.assume_valid_from') }}</option></select>
      </div>
      <button type="button" class="btn btn-primary" :disabled="tracker.requesting.value || tracker.isRunning.value" data-test="request-costs" @click="request"><AppIcon name="play" :size="16" /> {{ t('supply.costs.request') }}</button>
    </div>
    <p v-if="policy === 'assume_valid_from' && can('forecast.run')" class="hint m-0">{{ t('supply.costs.policies.assumeHint') }}</p>
    <p v-if="message" class="field-error" role="alert">{{ message }}</p>
    <JobProgress v-if="tracker.job.value" :job="tracker.job.value" />

    <ErrorState v-if="result.error.value" :error="result.error.value" compact @retry="result.execute" />
    <template v-else>
      <InlineNotice v-if="result.loaded.value && !rows.length" kind="info" data-test="costs-empty">{{ result.data.value?.envelope?.limitations?.length ? noteTexts(result.data.value.envelope.limitations).join(' ') : t('supply.costs.empty') }}</InlineNotice>
      <DataTable v-else :columns="columns" :rows="rows" :row-key="(c) => c.id" :loading="result.loading.value" :caption="t('supply.tabs.costs')">
        <template #cell-method="{ row }">
          <template v-if="row.quality_status === 'insufficient_data'"><span class="text-fg-muted" data-test="cost-abstention">{{ t('supply.costs.noMethod') }}</span></template>
          <template v-else>
            {{ methodText(row.method) }}
            <span v-if="servedReference(row)" class="badge badge-info ml-1" data-test="reference-served">{{ t('supply.costs.referenceServed') }}</span>
            <span v-else class="block text-xs text-fg-muted">{{ t('supply.costs.vsReference', { ref: methodText(row.reference_method) }) }}</span>
          </template>
        </template>
        <template #cell-holdout="{ row }">
          <span v-if="holdoutMae(row, row.method) != null">{{ formatNumber(holdoutMae(row, row.method), 4) }}</span><span v-else class="text-fg-muted">—</span>
          <span v-if="!servedReference(row) && holdoutMae(row, row.reference_method) != null" class="block text-xs text-fg-muted">{{ t('supply.costs.referenceMae', { v: formatNumber(holdoutMae(row, row.reference_method), 4) }) }}</span>
        </template>
        <template #cell-last="{ row }">{{ row.last_observed_cost != null ? `${formatNumber(row.last_observed_cost, 4)} ${row.currency ?? ''}` : '—' }}<span v-if="row.last_observed_on" class="block text-xs text-fg-muted">{{ formatDate(row.last_observed_on) }}</span></template>
        <template #cell-forecast="{ row }">
          <template v-if="lastPoint(row)?.yhat != null">
            {{ formatNumber(lastPoint(row)!.yhat, 4) }}
            <span v-if="lastPoint(row)!.lo != null && lastPoint(row)!.hi != null" class="block text-xs text-fg-muted">{{ formatNumber(lastPoint(row)!.lo, 4) }} – {{ formatNumber(lastPoint(row)!.hi, 4) }}</span>
            <span v-else class="block text-xs text-fg-muted" data-test="interval-unknown">{{ t('supply.costs.intervalUnknown') }}</span>
          </template>
          <span v-else class="text-fg-muted">—</span>
        </template>
        <template #cell-quality_status="{ row }"><QualityBadge :status="row.quality_status" /></template>
        <template #cell-open="{ row }"><button type="button" class="btn btn-ghost btn-sm" :aria-expanded="open === row.id" @click="open = open === row.id ? null : row.id">{{ t('supply.costs.detail') }}</button></template>
      </DataTable>
      <PaginationBar v-model:page="page" :meta="meta" />
      <div v-if="detail" class="rounded-xl border border-line p-4 flex flex-col gap-3" data-test="cost-detail">
        <h3 class="section-title m-0">{{ detail.product?.name }}</h3>
        <ul v-if="noteTexts(detail.limitations).length" class="list-disc pl-5 m-0 text-sm"><li v-for="(l, i) in noteTexts(detail.limitations)" :key="i">{{ l }}</li></ul>
        <div v-if="detail.points?.length" class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('supply.costs.points') }}</caption>
            <thead><tr><th scope="col">{{ t('supply.costs.step') }}</th><th scope="col">{{ t('chart.date') }}</th><th scope="col" class="num">{{ t('supply.costs.servedMethod') }}</th><th scope="col" class="num">{{ t('supply.costs.reference') }}</th><th scope="col" class="num">{{ t('supply.costs.range') }}</th></tr></thead>
            <tbody><tr v-for="p in detail.points" :key="p.step"><td>{{ p.step }}</td><td>{{ formatDate(p.target_date) }}</td><td class="num">{{ formatNumber(p.yhat, 4) }}</td><td class="num">{{ formatNumber(p.reference, 4) }}</td><td class="num">{{ p.lo != null && p.hi != null ? `${formatNumber(p.lo, 4)} – ${formatNumber(p.hi, 4)}` : '—' }}</td></tr></tbody>
          </table>
        </div>
      </div>
      <EnvelopePanel :envelope="result.data.value?.envelope" />
    </template>
  </section>
</template>
