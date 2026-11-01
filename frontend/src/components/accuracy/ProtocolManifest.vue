<script setup lang="ts">
/**
 * Manifiesto del protocolo de evaluación de la demanda en lenguaje claro: de dónde a dónde se entrenó,
 * se eligió el modelo, se calibraron los rangos y se hizo la prueba final (que no interviene en ninguna decisión).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import type { DemandManifest } from '@/api/types'
import { demandChecksFailed, demandProtocolSteps, demandProtocolWarnings } from '@/utils/manifest'
import { formatDate, formatInteger } from '@/utils/format'

const props = defineProps<{ manifest: DemandManifest | null | undefined }>()
const { t } = useI18n()
const steps = computed(() => demandProtocolSteps(props.manifest))
const warnings = computed(() => demandProtocolWarnings(props.manifest))
const failed = computed(() => demandChecksFailed(props.manifest))
const holdoutRules = computed(() => {
  const h = props.manifest?.holdout
  if (!h?.present) return []
  return [
    { key: 'used_for_model_selection', value: h.used_for_model_selection },
    { key: 'used_for_hyperparameters', value: h.used_for_hyperparameters },
    { key: 'used_for_thresholds', value: h.used_for_thresholds },
    { key: 'used_for_interval_calibration', value: h.used_for_interval_calibration },
  ]
})
</script>

<template>
  <section class="flex flex-col gap-3" :aria-label="t('manifest.title')" data-test="protocol-manifest">
    <p v-if="!manifest" class="text-sm text-fg-muted m-0" data-test="manifest-missing">{{ t('manifest.missing') }}</p>
    <template v-else>
      <InlineNotice v-for="key in warnings" :key="key" kind="warning" :data-warning="key">
        {{ t(key) }}
        <template v-if="key === 'manifest.warnings.noHoldout' && manifest.holdout?.reason"> {{ manifest.holdout.reason }}</template>
        <template v-if="key === 'manifest.warnings.checksFailed'"> ({{ failed.join(', ') }})</template>
      </InlineNotice>
      <ol class="list-none p-0 m-0 flex flex-col gap-2">
        <li v-for="s in steps" :key="s.key" class="rounded-xl border border-line p-3" :class="s.missing ? 'border-dashed' : ''" :data-step="s.key">
          <p class="m-0 text-sm font-semibold">{{ t(`manifest.steps.${s.key}.title`) }}</p>
          <p class="m-0 text-sm" :data-test="`step-${s.key}-range`">
            <template v-if="s.missing">{{ t('manifest.holdoutMissing') }}</template>
            <template v-else-if="s.from && s.to">{{ t('manifest.range', { from: formatDate(s.from), to: formatDate(s.to) }) }}</template>
            <template v-else>—</template>
          </p>
          <p class="hint m-0">{{ t(`manifest.steps.${s.key}.text`) }}</p>
          <p v-if="s.key === 'calibration' && s.extraDate" class="hint m-0">{{ t('manifest.observableAt', { date: formatDate(s.extraDate) }) }}</p>
          <p v-if="s.key === 'holdout' && s.extraDate && !s.missing" class="hint m-0">{{ t('manifest.holdoutOrigin', { date: formatDate(s.extraDate) }) }}</p>
          <p v-if="s.key === 'selection' && s.count" class="hint m-0">{{ t('manifest.windows', { n: s.count }) }}</p>
        </li>
      </ol>
      <ul v-if="holdoutRules.length" class="list-none p-0 m-0 flex flex-wrap gap-2" data-test="holdout-rules">
        <li v-for="r in holdoutRules" :key="r.key" class="badge" :class="r.value === false ? 'badge-success' : 'badge-danger'">{{ t(`manifest.holdoutRules.${r.key}`) }}: {{ r.value === false ? t('common.no') : t('common.yes') }}</li>
      </ul>
      <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 m-0">
        <div v-if="manifest.label_maturity"><dt class="text-fg-muted">{{ t('manifest.labelMaturity') }}</dt><dd class="m-0">{{ manifest.label_maturity.rule }}</dd></div>
        <div v-if="manifest.overlap"><dt class="text-fg-muted">{{ t('manifest.overlap') }}</dt><dd class="m-0">{{ manifest.overlap.note }} <span class="text-fg-muted">({{ t('manifest.sharedDates', { n: formatInteger(manifest.overlap.shared_target_dates_between_selection_windows) }) }})</span></dd></div>
        <div v-if="manifest.selection?.criterion"><dt class="text-fg-muted">{{ t('manifest.criterion') }}</dt><dd class="m-0">{{ manifest.selection.criterion }}</dd></div>
        <div v-if="manifest.calibration?.production"><dt class="text-fg-muted">{{ t('manifest.productionModel') }}</dt><dd class="m-0">{{ manifest.calibration.production }}</dd></div>
      </dl>
    </template>
  </section>
</template>
