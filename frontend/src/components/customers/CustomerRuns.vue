<script setup lang="ts">
/**
 * Entrenar y predecir son operaciones distintas. Predecir recalcula las variables a la fecha con los modelos en producción
 * (la recencia cambia cada día aunque no haya ventas nuevas) y no entrena. El progreso es el que reporta el trabajo.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import JobProgress from '@/components/forecast/JobProgress.vue'
import { customerApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { CustomerHorizon, CustomerOperation, CustomerRun } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useRunJob } from '@/composables/useRunJob'
import { useHorizonSelection } from '@/composables/useHorizons'
import { useUiStore } from '@/stores/ui'
import { CUSTOMER_HORIZONS } from '@/utils/constants'
import { formatDate, formatDateTime } from '@/utils/format'
import { useTenantStore } from '@/stores/tenant'

const emit = defineEmits<{ finished: [] }>()
const { t } = useI18n()
const { can } = useAbility()
const ui = useUiStore()
const tenant = useTenantStore()
const { allowed } = useHorizonSelection<CustomerHorizon>(CUSTOMER_HORIZONS, 30)
const chosen = ref<CustomerHorizon[] | null>(null)
const horizons = computed<CustomerHorizon[]>(() => (chosen.value ?? allowed.value).filter((h) => allowed.value.includes(h)))
const runs = useAsync(() => customerApi.runs({ per_page: 10 }))
const message = ref<string | null>(null)
const lastOperation = ref<CustomerOperation | null>(null)

const tracker = useRunJob<CustomerRun>((job) => {
  if (job.status === 'succeeded') {
    ui.notify(t(lastOperation.value === 'train' ? 'customers.runs.trainDone' : 'customers.runs.predictDone'), 'success')
    emit('finished')
  } else {
    ui.notify(job.error_message || t('jobs.failed'), 'error', 9000)
  }
  void runs.execute()
})

function toggle(h: CustomerHorizon): void {
  const current = new Set(horizons.value)
  if (current.has(h)) current.delete(h)
  else current.add(h)
  chosen.value = [...current].sort((a, b) => a - b) as CustomerHorizon[]
}

async function start(operation: CustomerOperation): Promise<void> {
  message.value = null
  lastOperation.value = operation
  try {
    await tracker.start(() => customerApi.startRun({ operation, horizons: horizons.value }))
    void runs.execute()
  } catch (e) {
    message.value = isApiError(e) && e.status === 409 ? t('customers.runs.inProgress') : isApiError(e) && e.isPlanLimit ? t('errors.planLimit') : errorMessage(e, t)
  }
}

const columns = computed<Column<CustomerRun>[]>(() => [
  { key: 'operation', label: t('customers.runs.operation'), format: (r) => t(`customers.runs.operations.${r.operation}`) },
  { key: 'status', label: t('common.status') },
  { key: 'quality_status', label: t('envelope.quality') },
  { key: 'as_of', label: t('customers.runs.asOf'), format: (r) => formatDate(r.as_of) },
  { key: 'horizons', label: t('common.horizon'), format: (r) => (r.horizons ?? []).map((h) => t('common.days', { n: h })).join(', ') || '—' },
  { key: 'completed_at', label: t('forecasts.generatedAt'), format: (r) => formatDateTime(r.completed_at ?? r.created_at, tenant.timezone) },
])
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('customers.tabs.runs')" data-test="customer-runs">
    <InlineNotice kind="info" :title="t('customers.runs.differenceTitle')">{{ t('customers.runs.difference') }}</InlineNotice>
    <InlineNotice v-if="!can('forecast.run')" kind="info">{{ t('customers.runs.needsAbility') }}</InlineNotice>
    <div v-else class="flex flex-col gap-3">
      <fieldset class="border-0 p-0 m-0 flex flex-wrap items-center gap-4">
        <legend class="label">{{ t('customers.runs.horizonsToUse') }}</legend>
        <label v-for="h in allowed" :key="h" class="flex items-center gap-2 text-sm">
          <input type="checkbox" :checked="horizons.includes(h)" @change="toggle(h)" /> {{ t('common.days', { n: h }) }}
        </label>
        <span v-if="!allowed.length" class="text-sm text-fg-muted">{{ t('horizonSupport.noneInPlan') }}</span>
      </fieldset>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary" :disabled="tracker.requesting.value || tracker.isRunning.value || !horizons.length" data-test="train" @click="start('train')">
          <AppIcon name="play" :size="16" /> {{ t('customers.runs.train') }}
        </button>
        <button type="button" class="btn btn-secondary" :disabled="tracker.requesting.value || tracker.isRunning.value || !horizons.length" data-test="predict" @click="start('predict')">
          <AppIcon name="refresh" :size="16" /> {{ t('customers.runs.predict') }}
        </button>
      </div>
      <p v-if="message" class="field-error" role="alert">{{ message }}</p>
      <JobProgress v-if="tracker.job.value" :job="tracker.job.value" />
    </div>

    <h3 class="section-title m-0">{{ t('customers.runs.history') }}</h3>
    <ErrorState v-if="runs.error.value" :error="runs.error.value" compact @retry="runs.execute" />
    <DataTable v-else :columns="columns" :rows="runs.data.value?.data ?? []" :row-key="(r) => r.id" :loading="runs.loading.value" :caption="t('customers.runs.history')" :empty-text="t('customers.runs.empty')">
      <template #cell-status="{ row }"><StatusBadge domain="run" :value="row.status" /><span v-if="row.is_current" class="badge badge-brand ml-1">{{ t('forecasts.current') }}</span><p v-if="row.error_message" class="text-xs text-danger mt-1 max-w-xs">{{ row.error_message }}</p></template>
      <template #cell-quality_status="{ row }"><QualityBadge :status="row.status === 'completed' ? row.quality_status : null" /></template>
    </DataTable>
  </section>
</template>
