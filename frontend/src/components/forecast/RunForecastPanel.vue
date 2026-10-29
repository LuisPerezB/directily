<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import JobProgress from './JobProgress.vue'
import { useForecastJob } from '@/composables/useForecastJob'
import { useAbility } from '@/composables/useAbility'
import { errorMessage, isApiError } from '@/api/errors'
import { useUiStore } from '@/stores/ui'
import type { TrainingJob } from '@/api/types'

/** Botón "Generar pronóstico" + seguimiento del trabajo. Emite `finished` al terminar con éxito. */
withDefaults(defineProps<{ compact?: boolean; label?: string | null }>(), { compact: false, label: null })
const emit = defineEmits<{ finished: [job: TrainingJob] }>()
const { t } = useI18n()
const { can } = useAbility()
const ui = useUiStore()
const localError = ref<string | null>(null)

const tracker = useForecastJob((job) => {
  if (job.status === 'succeeded') {
    ui.notify(t('jobs.forecastReady'), 'success')
    emit('finished', job)
  } else {
    ui.notify(job.error_message || t('jobs.failed'), 'error', 9000)
  }
})

async function run(): Promise<void> {
  localError.value = null
  try {
    await tracker.request()
  } catch (error) {
    localError.value = isApiError(error) && error.status === 409 ? t('jobs.alreadyRunning') : errorMessage(error, t)
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div v-if="can('forecast.run')" class="flex flex-wrap items-center gap-2">
      <button type="button" class="btn btn-primary" :class="compact ? 'btn-sm' : ''" :disabled="tracker.requesting.value || tracker.isRunning.value" @click="run">
        <AppIcon name="play" :size="16" />
        {{ tracker.isRunning.value ? t('jobs.inProgress') : (label ?? t('jobs.runForecast')) }}
      </button>
      <span v-if="!compact" class="hint">{{ t('jobs.runHint') }}</span>
    </div>
    <p v-if="localError" class="field-error" role="alert">{{ localError }}</p>
    <JobProgress v-if="tracker.job.value" :job="tracker.job.value" />
  </div>
</template>
