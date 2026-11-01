import { computed, ref } from 'vue'
import { forecastApi } from '@/api'
import type { TrainingJob } from '@/api/types'
import { usePolling } from './usePolling'

const TERMINAL = new Set(['succeeded', 'failed', 'cancelled'])

/**
 * Solicita una ejecución asíncrona (Customer Core, plan de compras, costos, simulación) y sigue su trabajo.
 * Muestra solo lo que reporta la API (estado, etapa, avance, error).
 */
export function useRunJob<R extends { training_job?: TrainingJob | null }>(onFinished?: (job: TrainingJob, run: R | null) => void) {
  const job = ref<TrainingJob | null>(null)
  const run = ref<R | null>(null)
  const requesting = ref(false)
  const requestError = ref<unknown>(null)

  const polling = usePolling(() => forecastApi.job(job.value!.id), {
    interval: 2500,
    until: (j) => TERMINAL.has(j.status),
    onUpdate: (j) => {
      job.value = j
      if (TERMINAL.has(j.status)) onFinished?.(j, run.value)
    },
  })

  const isRunning = computed(() => job.value !== null && !TERMINAL.has(job.value.status))

  async function start(request: () => Promise<R>): Promise<R> {
    requesting.value = true
    requestError.value = null
    try {
      const created = await request()
      run.value = created
      if (created.training_job) {
        job.value = created.training_job
        if (TERMINAL.has(created.training_job.status)) onFinished?.(created.training_job, created)
        else polling.start(false)
      }
      return created
    } catch (e) {
      requestError.value = e
      throw e
    } finally {
      requesting.value = false
    }
  }

  function track(existing: TrainingJob, current: R | null = null): void {
    run.value = current
    job.value = existing
    if (!TERMINAL.has(existing.status)) polling.start(false)
  }

  function reset(): void {
    polling.stop()
    job.value = null
    run.value = null
    requestError.value = null
  }

  return { job, run, requesting, requestError, isRunning, start, track, reset }
}
