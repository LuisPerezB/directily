import { onScopeDispose, ref, shallowRef, watch, type Ref, type WatchSource } from 'vue'

export interface AsyncState<T> {
  data: Ref<T | null>
  error: Ref<unknown>
  loading: Ref<boolean>
  /** true cuando la última carga terminó sin error. */
  loaded: Ref<boolean>
  execute: () => Promise<T | null>
}

/**
 * Ejecuta una función asíncrona con estados de carga/error. Ignora respuestas obsoletas
 * cuando hay llamadas superpuestas (p. ej. filtros que cambian rápido).
 */
export function useAsync<T>(
  fn: () => Promise<T>,
  options: { immediate?: boolean; watch?: WatchSource[] | WatchSource; debounce?: number } = {},
): AsyncState<T> {
  const data = shallowRef<T | null>(null) as Ref<T | null>
  const error = ref<unknown>(null)
  const loading = ref(false)
  const loaded = ref(false)
  let seq = 0
  let timer: ReturnType<typeof setTimeout> | null = null

  async function execute(): Promise<T | null> {
    const current = ++seq
    loading.value = true
    error.value = null
    try {
      const result = await fn()
      if (current === seq) {
        data.value = result
        loaded.value = true
      }
      return result
    } catch (e) {
      if (current === seq) {
        error.value = e
        loaded.value = false
      }
      return null
    } finally {
      if (current === seq) loading.value = false
    }
  }

  if (options.watch) {
    watch(options.watch, () => {
      if (options.debounce) {
        if (timer) clearTimeout(timer)
        timer = setTimeout(() => void execute(), options.debounce)
      } else {
        void execute()
      }
    }, { deep: true })
  }
  onScopeDispose(() => {
    if (timer) clearTimeout(timer)
  })
  if (options.immediate !== false) void execute()

  return { data, error, loading, loaded, execute }
}
