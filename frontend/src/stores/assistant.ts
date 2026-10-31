import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { assistantApi } from '@/api'
import type { AssistantStatus } from '@/api/types'

/**
 * Disponibilidad del asistente opcional. Si la consulta falla o indica `enabled=false`, el panel y su enlace se ocultan:
 * nada más en la aplicación depende de él.
 */
export const useAssistantStore = defineStore('assistant', () => {
  const status = ref<AssistantStatus | null>(null)
  const loadedFor = ref<string | null>(null)
  let pending: Promise<void> | null = null

  const enabled = computed(() => status.value?.enabled === true)

  async function ensureLoaded(tenantUuid: string | null, force = false): Promise<void> {
    if (!tenantUuid) return
    if (!force && loadedFor.value === tenantUuid) return
    pending ??= assistantApi
      .status()
      .then((s) => {
        status.value = s
      })
      .catch(() => {
        status.value = null
      })
      .finally(() => {
        loadedFor.value = tenantUuid
        pending = null
      })
    await pending
  }

  function reset(): void {
    status.value = null
    loadedFor.value = null
  }

  return { status, enabled, loadedFor, ensureLoaded, reset }
})
