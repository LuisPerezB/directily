<script setup lang="ts">
/** Crear un plan de compras (horizonte, objetivo, presupuesto vía configuración), seguir el trabajo y revisar sus resultados. */
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import FormField from '@/components/ui/FormField.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import JobProgress from '@/components/forecast/JobProgress.vue'
import PlanResults from './PlanResults.vue'
import { supplyApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { SupplyRun } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useRunJob } from '@/composables/useRunJob'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/utils/format'
import { DEFAULT_PLAN_FORM, planRequestFromForm, validatePlanForm, type PlanForm } from '@/utils/supply'

const { t, te } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()
const ui = useUiStore()
const form = reactive<PlanForm>({ ...DEFAULT_PLAN_FORM })
const serverErrors = ref<Record<string, string>>({})
const message = ref<string | null>(null)
const selected = ref<number | null>(null)
const plans = useAsync(() => supplyApi.plans({ per_page: 10 }))
const errors = computed(() => validatePlanForm(form))
const hasErrors = computed(() => Object.keys(errors.value).length > 0)
const errText = (key: keyof typeof errors.value): string | undefined => {
  const code = errors.value[key]
  return serverErrors.value[key] ?? (code ? t(`supply.plan.errors.${code}`) : undefined)
}

const tracker = useRunJob<SupplyRun>((job, run) => {
  void plans.execute()
  if (job.status === 'succeeded') {
    ui.notify(t('supply.plan.ready'), 'success')
    if (run) selected.value = run.id
  } else {
    ui.notify(job.error_message || t('jobs.failed'), 'error', 9000)
  }
})

watch(() => plans.data.value, (page) => {
  if (selected.value === null) selected.value = page?.data.find((r) => r.status === 'completed')?.id ?? null
})

async function create(): Promise<void> {
  if (hasErrors.value) return
  message.value = null
  serverErrors.value = {}
  try {
    await tracker.start(() => supplyApi.createPlan(planRequestFromForm(form)))
    void plans.execute()
  } catch (e) {
    if (isApiError(e) && e.isValidation) {
      serverErrors.value = Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k.replace(/^config\./, ''), v[0] ?? '']))
      message.value = e.message
    } else {
      message.value = isApiError(e) && e.code === 'supply_run_in_progress' ? t('supply.plan.inProgress') : errorMessage(e, t)
    }
  }
}

const columns = computed<Column<SupplyRun>[]>(() => [
  { key: 'version', label: t('forecasts.version'), format: (r) => `v${r.version}` },
  { key: 'status', label: t('common.status') },
  { key: 'solver', label: t('supply.plan.solverTitle') },
  { key: 'created_at', label: t('forecasts.generatedAt'), format: (r) => formatDateTime(r.completed_at ?? r.created_at, tenant.timezone) },
  { key: 'open', label: '' },
])
const solverOf = (r: SupplyRun): string | null => (r.summary?.solver_status as string | undefined) ?? null
</script>

<template>
  <section class="flex flex-col gap-5" :aria-label="t('supply.tabs.plan')" data-test="plan-tab">
    <InlineNotice kind="warning" :title="t('supply.plan.suggestionTitle')">{{ t('supply.plan.suggestion') }}</InlineNotice>

    <form v-if="can('forecast.run')" class="rounded-xl border border-line p-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="plan-form" @submit.prevent="create">
      <h3 class="section-title m-0 sm:col-span-2 lg:col-span-4">{{ t('supply.plan.newPlan') }}</h3>
      <FormField id="pl-objective" :label="t('supply.plan.objective')" :hint="t('supply.plan.objectiveHint')">
        <template #default><select id="pl-objective" v-model="form.objective" class="select"><option value="min_cost">{{ t('supply.plan.objectives.min_cost') }}</option><option value="max_margin">{{ t('supply.plan.objectives.max_margin') }}</option></select></template>
      </FormField>
      <FormField id="pl-coverage" :label="t('supply.plan.coverage')" :hint="t(`supply.plan.coverageHints.${form.coverage_mode}`)">
        <template #default><select id="pl-coverage" v-model="form.coverage_mode" class="select"><option value="soft">{{ t('supply.plan.coverageModes.soft') }}</option><option value="hard">{{ t('supply.plan.coverageModes.hard') }}</option></select></template>
      </FormField>
      <FormField id="pl-horizon" :label="t('supply.plan.horizonDays')" :hint="t('supply.plan.horizonHint')" :error="errText('horizon_days') ?? null">
        <template #default="{ describedby, invalid }"><input id="pl-horizon" v-model.number="form.horizon_days" type="number" min="14" max="365" class="input" :aria-describedby="describedby" :aria-invalid="invalid" /></template>
      </FormField>
      <FormField id="pl-period" :label="t('supply.plan.periodDays')">
        <template #default><select id="pl-period" v-model.number="form.period_days" class="select"><option v-for="p in [1, 7, 14, 30]" :key="p" :value="p">{{ t('common.days', { n: p }) }}</option></select></template>
      </FormField>
      <p class="hint m-0 sm:col-span-2 lg:col-span-4" data-test="plan-data-hint">
        {{ t('supply.plan.dataHint') }}
        <RouterLink :to="{ name: 'supply', params: { tab: 'configuracion' } }" class="font-semibold">{{ t('supply.plan.dataHintLink') }}</RouterLink>
      </p>
      <details class="sm:col-span-2 lg:col-span-4">
        <summary class="cursor-pointer text-sm text-fg-muted hover:text-fg">{{ t('supply.plan.advanced') }}</summary>
        <p class="hint mt-2">{{ t('supply.plan.advancedHint') }}</p>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 mt-2">
          <FormField id="pl-pen" :label="t('supply.plan.shortagePenaltyMultiplier')" :hint="t('supply.plan.shortagePenaltyHint')" :error="errText('shortage_penalty_multiplier') ?? null">
            <template #default="{ describedby, invalid }"><input id="pl-pen" v-model.number="form.shortage_penalty_multiplier" type="number" step="0.1" class="input" :placeholder="t('supply.plan.default')" :aria-describedby="describedby" :aria-invalid="invalid" /></template>
          </FormField>
          <FormField id="pl-ssw" :label="t('supply.plan.safetyWeight')" :hint="t('supply.plan.safetyWeightHint')" :error="errText('safety_stock_weight') ?? null">
            <template #default="{ describedby, invalid }"><input id="pl-ssw" v-model.number="form.safety_stock_weight" type="number" step="0.05" class="input" :placeholder="t('supply.plan.default')" :aria-describedby="describedby" :aria-invalid="invalid" /></template>
          </FormField>
          <FormField id="pl-hold" :label="t('supply.plan.holdingRate')" :hint="t('supply.plan.holdingRateHint')" :error="errText('holding_cost_rate_annual') ?? null">
            <template #default="{ describedby, invalid }"><input id="pl-hold" v-model.number="form.holding_cost_rate_annual" type="number" step="0.01" class="input" :placeholder="t('supply.plan.default')" :aria-describedby="describedby" :aria-invalid="invalid" /></template>
          </FormField>
          <FormField id="pl-time" :label="t('supply.plan.timeLimit')" :error="errText('solver_time_limit_seconds') ?? null">
            <template #default="{ describedby, invalid }"><input id="pl-time" v-model.number="form.solver_time_limit_seconds" type="number" min="1" max="300" class="input" :placeholder="t('supply.plan.default')" :aria-describedby="describedby" :aria-invalid="invalid" /></template>
          </FormField>
          <label class="flex items-center gap-2 text-sm sm:col-span-2"><input v-model="form.use_implicit_offers" type="checkbox" /> {{ t('supply.plan.implicitOffers') }}</label>
        </div>
      </details>
      <div class="sm:col-span-2 lg:col-span-4 flex flex-wrap items-center gap-3">
        <button type="submit" class="btn btn-primary" :disabled="tracker.requesting.value || tracker.isRunning.value || hasErrors" data-test="create-plan"><AppIcon name="play" :size="16" /> {{ tracker.isRunning.value ? t('jobs.inProgress') : t('supply.plan.create') }}</button>
        <span class="hint m-0">{{ t('supply.plan.createHint') }}</span>
      </div>
      <p v-if="message" class="field-error sm:col-span-2 lg:col-span-4" role="alert" data-test="plan-message">{{ message }}</p>
      <JobProgress v-if="tracker.job.value" :job="tracker.job.value" class="sm:col-span-2 lg:col-span-4" />
    </form>
    <InlineNotice v-else kind="info">{{ t('supply.needsRun') }}</InlineNotice>

    <div>
      <h3 class="section-title mb-2">{{ t('supply.plan.history') }}</h3>
      <ErrorState v-if="plans.error.value" :error="plans.error.value" compact @retry="plans.execute" />
      <DataTable v-else :columns="columns" :rows="plans.data.value?.data ?? []" :row-key="(r) => r.id" :loading="plans.loading.value" :caption="t('supply.plan.history')" :empty-text="t('supply.plan.empty')">
        <template #cell-status="{ row }"><StatusBadge domain="run" :value="row.status" /> <QualityBadge v-if="row.status === 'completed'" :status="row.quality_status" /><p v-if="row.error_message" class="text-xs text-danger mt-1 max-w-xs">{{ row.error_message }}</p></template>
        <template #cell-solver="{ row }"><span v-if="solverOf(row)" class="text-sm">{{ te(`supply.solver.${solverOf(row)}.label`) ? t(`supply.solver.${solverOf(row)}.label`) : solverOf(row) }}</span><span v-else class="text-fg-muted">—</span></template>
        <template #cell-open="{ row }"><button v-if="row.status === 'completed'" type="button" class="btn btn-ghost btn-sm" :aria-pressed="selected === row.id" @click="selected = row.id">{{ t('supply.plan.open') }}</button></template>
      </DataTable>
    </div>

    <PlanResults v-if="selected !== null" :run-id="selected" :key="selected" />
  </section>
</template>
