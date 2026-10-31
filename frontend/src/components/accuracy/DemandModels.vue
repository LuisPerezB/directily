<script setup lang="ts">
/**
 * Modelos de la demanda: registro con estados (candidato, validado, producción, retirado, fallido) y papel, y la
 * reversión a la versión anterior (habilidad forecast.run) con confirmación. La reversión no reentrena: re-apunta
 * la ejecución vigente a una de producción anterior y restaura sus modelos.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ModelRegistryTable from '@/components/models/ModelRegistryTable.vue'
import RollbackDialog from '@/components/models/RollbackDialog.vue'
import { accuracyApi, modelsApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { DemandRollbackResult, ForecastRun } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useUiStore } from '@/stores/ui'
import { rollbackErrorKey } from '@/utils/envelope'
import { formatDate } from '@/utils/format'

const props = defineProps<{ runs: ForecastRun[] }>()
const emit = defineEmits<{ rolledBack: [] }>()
const { t } = useI18n()
const { can } = useAbility()
const ui = useUiStore()

const statusFilter = ref<string>('')
const models = useAsync(() => accuracyApi.models({ core: 'demand', status: statusFilter.value || undefined, per_page: 50 }), { watch: [statusFilter] })

const current = computed(() => props.runs.find((r) => r.is_current) ?? null)
/** Solo ejecuciones de producción completadas y anteriores a la vigente (regla de la API). */
const previous = computed(() =>
  current.value
    ? props.runs.filter((r) => r.run_type === 'production' && r.status === 'completed' && r.id < current.value!.id).sort((a, b) => b.id - a.id)
    : [],
)

const target = ref<ForecastRun | null>(null)
const busy = ref(false)
const errorText = ref<string | null>(null)
const result = ref<DemandRollbackResult | null>(null)

const details = computed(() =>
  target.value && current.value
    ? [
        { label: t('rollback.demand.from'), value: `v${current.value.version} · ${formatDate(current.value.cutoff_date)}` },
        { label: t('rollback.demand.to'), value: `v${target.value.version} · ${formatDate(target.value.cutoff_date)}` },
      ]
    : [],
)

function ask(run: ForecastRun): void {
  target.value = run
  errorText.value = null
}

async function confirm(): Promise<void> {
  if (!target.value) return
  busy.value = true
  errorText.value = null
  try {
    result.value = await modelsApi.rollbackDemand(target.value.id)
    target.value = null
    ui.notify(t('rollback.demand.done'), 'success')
    emit('rolledBack')
    void models.execute()
  } catch (e) {
    const key = isApiError(e) ? rollbackErrorKey(e.code) : null
    errorText.value = key ? t(key) : errorMessage(e, t)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('modelRegistry.title')" data-test="demand-models">
    <p class="text-sm text-fg-muted m-0">{{ t('modelRegistry.text') }}</p>
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label" for="mr-status">{{ t('modelRegistry.filter') }}</label>
        <select id="mr-status" v-model="statusFilter" class="select !w-auto">
          <option value="">{{ t('modelRegistry.allStatuses') }}</option>
          <option v-for="s in ['production', 'validated', 'retired', 'failed', 'candidate']" :key="s" :value="s">{{ t(`status.model.${s}`) }}</option>
        </select>
      </div>
    </div>
    <ErrorState v-if="models.error.value" :error="models.error.value" compact @retry="models.execute" />
    <ModelRegistryTable v-else :models="models.data.value?.data ?? []" :loading="models.loading.value" :caption="t('modelRegistry.title')" />

    <div class="rounded-xl border border-line p-4 flex flex-col gap-3" data-test="rollback-section">
      <h3 class="section-title m-0">{{ t('rollback.demand.title') }}</h3>
      <p class="text-sm text-fg-muted m-0">{{ t('rollback.demand.text') }}</p>
      <InlineNotice v-if="!can('forecast.run')" kind="info">{{ t('rollback.needsAbility') }}</InlineNotice>
      <p v-else-if="!current" class="text-sm text-fg-muted m-0">{{ t('rollback.demand.noCurrent') }}</p>
      <p v-else-if="!previous.length" class="text-sm text-fg-muted m-0" data-test="rollback-none">{{ t('rollback.demand.noPrevious') }}</p>
      <ul v-else class="list-none p-0 m-0 flex flex-col divide-y divide-line">
        <li v-for="r in previous" :key="r.id" class="py-2 flex flex-wrap items-center justify-between gap-2 text-sm">
          <span>v{{ r.version }} · {{ t('forecasts.cutoff') }} {{ formatDate(r.cutoff_date) }}</span>
          <button type="button" class="btn btn-secondary btn-sm" data-test="rollback-open" @click="ask(r)">{{ t('rollback.demand.action') }}</button>
        </li>
      </ul>
      <InlineNotice v-if="result" kind="success" data-test="rollback-result">
        <p class="m-0">{{ result.note }}</p>
        <p class="m-0 mt-1">{{ result.artifacts_verified === true ? t('rollback.verified') : t('rollback.notVerified') }}</p>
      </InlineNotice>
    </div>

    <RollbackDialog
      :open="target !== null"
      :title="t('rollback.demand.confirmTitle')"
      :message="t('rollback.demand.confirmText')"
      :details="details"
      :busy="busy"
      :error-text="errorText"
      @confirm="confirm"
      @cancel="target = null"
    />
  </section>
</template>
