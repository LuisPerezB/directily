import { onScopeDispose, ref } from 'vue'

/**
 * Consulta periódica hasta que `until` sea verdadero. Usa setTimeout encadenado
 * (no setInterval) para no acumular peticiones si la API tarda.
 */
export function usePolling<T>(fn: () => Promise<T>, options: { interval?: number; until: (value: T) => boolean; onUpdate?: (value: T) => void; maxErrors?: number }) {
  const active = ref(false)
  const lastError = ref<unknown>(null)
  let timer: ReturnType<typeof setTimeout> | null = null
  let errors = 0
  const interval = options.interval ?? 2000
  const maxErrors = options.maxErrors ?? 5

  async function tick(): Promise<void> {
    if (!active.value) return
    try {
      const value = await fn()
      errors = 0
      options.onUpdate?.(value)
      if (options.until(value)) {
        stop()
        return
      }
    } catch (e) {
      lastError.value = e
      if (++errors >= maxErrors) {
        stop()
        return
      }
    }
    if (active.value) timer = setTimeout(() => void tick(), interval)
  }

  function start(immediate = true): void {
    stop()
    active.value = true
    errors = 0
    if (immediate) void tick()
    else timer = setTimeout(() => void tick(), interval)
  }

  function stop(): void {
    active.value = false
    if (timer) clearTimeout(timer)
    timer = null
  }

  onScopeDispose(stop)
  return { active, lastError, start, stop }
}
