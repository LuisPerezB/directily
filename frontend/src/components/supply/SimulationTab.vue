<script setup lang="ts">
/**
 * Simulación histórica de operación: re-juega políticas de reposición con la información disponible a cada fecha. NO
 * reemplaza la validación con datos reales: la demanda es la venta observada, censurada por quiebres, así que el faltante
 * queda subestimado. Se muestran siempre las advertencias (`caveats`) junto a la tabla.
 */
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import JobProgress from '@/components/forecast/JobProgress.vue'
import { supplyApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { SupplyRun } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useRunJob } from '@/composables/useRunJob'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatCurrency, formatDateTime, formatNumber, formatPercent } from '@/utils/format'

const { t, te } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()
const ui = useUiStore()
const form = reactive({ days: 120, review_days: 7 })
const message = ref<string | null>(null)
const selected = ref<number | null>(null)
const runs = useAsync(() => supplyApi.simulations({ page: 1 }))
const detail = useAsync(async () => (selected.value ? supplyApi.simulation(selected.value) : null), { watch: [selected], immediate: false })
watch(selected, (id) => { if (id) void detail.execute() })
watch(() => runs.data.value, (page) => { if (selected.value === null) selected.value = page?.data.find((r) => r.status === 'completed')?.id ?? null })

const tracker = useRunJob<SupplyRun>((job, run) => {
  void runs.execute()
  if (job.status === 'succeeded') {
    ui.notify(t('supply.simulation.ready'), 'success')
    if (run) selected.value = run.id
  } else ui.notify(job.error_message || t('jobs.failed'), 'error', 9000)
})
async function start(): Promise<void> {
  message.value = null
  try {
    await tracker.start(() => supplyApi.startSimulation({ days: form.days, review_days: form.review_days }))
  } catch (e) {
    message.value = isApiError(e) && e.status === 409 ? t('supply.simulation.inProgress') : errorMessage(e, t)
  }
}

const res = computed(() => detail.data.value?.data.results ?? null)
const envelope = computed(() => detail.data.value?.envelope ?? null)
const policies = computed(() => Object.entries(res.value?.policies ?? {}))
const policyName = (k: string): string => (te(`supply.simulation.policies.${k}`) ? t(`supply.simulation.policies.${k}`) : k)
const cur = computed(() => (envelope.value?.currency ?? tenant.currency))
const money = (v: unknown): string => (typeof v === 'number' ? formatCurrency(v, cur.value, 0) : '—')
const rowsDef = computed(() => [
  { key: 'fill_rate', label: t('supply.simulation.fillRate'), fmt: (v: unknown) => (typeof v === 'number' ? formatPercent(v, 1) : '—') },
  { key: 'unmet_units', label: t('supply.simulation.unmet'), fmt: (v: unknown) => (typeof v === 'number' ? formatNumber(v, 0) : '—') },
  { key: 'avg_inventory_value', label: t('supply.simulation.inventory'), fmt: money },
  { key: 'days_of_inventory', label: t('supply.simulation.daysInventory'), fmt: (v: unknown) => (typeof v === 'number' ? formatNumber(v, 1) : '—') },
  { key: 'holding_cost', label: t('supply.simulation.holding'), fmt: money },
  { key: 'lost_margin', label: t('supply.simulation.lostMargin'), fmt: money },
  { key: 'operating_cost_proxy', label: t('supply.simulation.operatingProxy'), fmt: money },
  { key: 'avg_capital_committed', label: t('supply.simulation.capital'), fmt: money },
  { key: 'purchase_outlay', label: t('supply.simulation.outlay'), fmt: money },
])
const columns = computed<Column<SupplyRun>[]>(() => [
  { key: 'version', label: t('forecasts.version'), format: (r) => `v${r.version}` },
  { key: 'status', label: t('common.status') },
  { key: 'created_at', label: t('forecasts.generatedAt'), format: (r) => formatDateTime(r.completed_at ?? r.created_at, tenant.timezone) },
  { key: 'open', label: '' },
])
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('supply.tabs.simulation')" data-test="simulation-tab">
    <InlineNotice kind="warning" :title="t('supply.simulation.notRealTitle')">{{ t('supply.simulation.notReal') }}</InlineNotice>
    <form v-if="can('forecast.run')" class="flex flex-wrap items-end gap-3" @submit.prevent="start">
      <div><label class="label" for="si-days">{{ t('supply.simulation.days') }}</label><input id="si-days" v-model.number="form.days" type="number" min="28" max="365" class="input !w-28" /></div>
      <div><label class="label" for="si-review">{{ t('supply.simulation.reviewDays') }}</label><input id="si-review" v-model.number="form.review_days" type="number" min="1" max="60" class="input !w-28" /></div>
      <button type="submit" class="btn btn-primary" :disabled="tracker.requesting.value || tracker.isRunning.value" data-test="start-simulation"><AppIcon name="play" :size="16" /> {{ t('supply.simulation.start') }}</button>
    </form>
    <InlineNotice v-else kind="info">{{ t('supply.needsRun') }}</InlineNotice>
    <p v-if="message" class="field-error" role="alert">{{ message }}</p>
    <JobProgress v-if="tracker.job.value" :job="tracker.job.value" />

    <ErrorState v-if="runs.error.value" :error="runs.error.value" compact @retry="runs.execute" />
    <DataTable v-else :columns="columns" :rows="runs.data.value?.data ?? []" :row-key="(r) => r.id" :loading="runs.loading.value" :caption="t('supply.simulation.history')" :empty-text="t('supply.simulation.empty')">
      <template #cell-status="{ row }"><StatusBadge domain="run" :value="row.status" /> <QualityBadge v-if="row.status === 'completed'" :status="row.quality_status" /></template>
      <template #cell-open="{ row }"><button v-if="row.status === 'completed'" type="button" class="btn btn-ghost btn-sm" :aria-pressed="selected === row.id" @click="selected = row.id">{{ t('supply.plan.open') }}</button></template>
    </DataTable>

    <LoadingState v-if="detail.loading.value && !res" />
    <template v-if="res">
      <p class="text-sm m-0">{{ t('supply.simulation.window', { from: res.window?.start ?? '—', to: res.window?.end ?? '—', series: res.window?.series ?? '—' }) }} <span class="text-fg-muted">· {{ t('supply.simulation.basis', { basis: res.demand_basis ?? '—' }) }}</span></p>
      <div class="table-wrap">
        <table class="table" data-test="policy-table">
          <caption class="sr-only">{{ t('supply.simulation.results') }}</caption>
          <thead><tr><th scope="col">{{ t('supply.scenarios.measure') }}</th><th v-for="[k] in policies" :key="k" scope="col" class="num">{{ policyName(k) }}</th></tr></thead>
          <tbody>
            <tr v-for="r in rowsDef" :key="r.key"><td>{{ r.label }}</td><td v-for="[k, p] in policies" :key="k" class="num">{{ r.fmt((p as Record<string, unknown>)[r.key]) }}</td></tr>
            <tr><td>{{ t('supply.simulation.solverStatuses') }}</td><td v-for="[k, p] in policies" :key="k" class="num text-xs">{{ p.solver ? Object.entries(p.solver.statuses).map(([s, n]) => `${te(`supply.solver.${s}.label`) ? t(`supply.solver.${s}.label`) : s}: ${n}`).join(', ') : '—' }}</td></tr>
          </tbody>
        </table>
      </div>
      <p v-if="res.budget_note" class="hint m-0">{{ res.budget_note }}</p>
      <InlineNotice v-if="res.caveats?.length" kind="warning" :title="t('supply.simulation.caveats')" data-test="caveats">
        <ul class="list-disc pl-5 m-0"><li v-for="(c, i) in res.caveats" :key="i">{{ c }}</li></ul>
      </InlineNotice>
      <EnvelopePanel :envelope="envelope" />
    </template>
  </section>
</template>
