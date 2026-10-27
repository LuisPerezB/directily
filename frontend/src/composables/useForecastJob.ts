import { computed, ref } from 'vue'
import { forecastApi } from '@/api'
import type { ForecastRun, Horizon, TrainingJob } from '@/api/types'
import { usePolling } from './usePolling'

const TERMINAL = new Set(['succeeded', 'failed', 'cancelled'])

/** Solicita una ejecución de pronóstico y sigue el progreso del trabajo asociado. */
export function useForecastJob(onFinished?: (job: TrainingJob) => void) {
  const job = ref<TrainingJob | null>(null)
  const run = ref<ForecastRun | null>(null)
  const requesting = ref(false)
  const requestError = ref<unknown>(null)

  const polling = usePolling(() => forecastApi.job(job.value!.id), {
    interval: 2500,
    until: (j) => TERMINAL.has(j.status),
    onUpdate: (j) => {
      job.value = j
      if (TERMINAL.has(j.status)) onFinished?.(j)
    },
  })

  const isRunning = computed(() => job.value !== null && !TERMINAL.has(job.value.status))

  async function request(horizon: Horizon = 90, cutoffDate: string | null = null): Promise<void> {
    requesting.value = true
    requestError.value = null
    try {
      run.value = await forecastApi.createRun({ horizon_days: horizon, cutoff_date: cutoffDate })
      if (run.value.training_job) {
        job.value = run.value.training_job
        polling.start(false)
      }
    } catch (e) {
      requestError.value = e
      throw e
    } finally {
      requesting.value = false
    }
  }

  function track(existing: TrainingJob): void {
    job.value = existing
    if (!TERMINAL.has(existing.status)) polling.start(false)
  }

  function reset(): void {
    polling.stop()
    job.value = null
    run.value = null
  }

  return { job, run, requesting, requestError, isRunning, request, track, reset }
}
