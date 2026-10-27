<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ProgressBar from '@/components/ui/ProgressBar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import type { TrainingJob } from '@/api/types'
import { formatDuration } from '@/utils/format'

/** Progreso de un trabajo del motor (estado, etapa, avance, error). Sin animaciones engañosas: muestra lo que reporta la API. */
const props = defineProps<{ job: TrainingJob }>()
const { t, te } = useI18n()
const stageLabel = computed(() => {
  if (!props.job.stage) return t('jobs.waiting')
  const key = `jobs.stages.${props.job.stage}`
  return te(key) ? t(key) : props.job.stage
})
const terminal = computed(() => ['succeeded', 'failed', 'cancelled'].includes(props.job.status))
</script>

<template>
  <div class="rounded-xl border border-line p-4 flex flex-col gap-3" aria-live="polite">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-sm font-semibold">{{ t('jobs.title', { id: job.id }) }}</p>
      <StatusBadge domain="job" :value="job.status" />
    </div>
    <template v-if="!terminal">
      <ProgressBar :value="job.progress" :label="t('jobs.progressLabel')" :indeterminate="job.status === 'queued'" />
      <p class="text-sm text-fg-muted">
        {{ job.status === 'queued' ? t('jobs.queued') : stageLabel }}
        <span v-if="job.status === 'running'" class="tabular"> · {{ job.progress }} %</span>
      </p>
      <p class="hint">{{ t('jobs.backgroundHint') }}</p>
    </template>
    <p v-else-if="job.status === 'succeeded'" class="text-sm text-success">
      {{ t('jobs.succeeded') }} <span v-if="job.duration_ms" class="text-fg-muted">({{ formatDuration(job.duration_ms) }})</span>
    </p>
    <p v-else class="text-sm text-danger">{{ job.error_message || t('jobs.failed') }}</p>
  </div>
</template>
