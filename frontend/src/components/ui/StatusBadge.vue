<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/** Etiqueta de estado: el texto sale de i18n (status.<dominio>.<valor>). */
const props = defineProps<{ domain: 'import' | 'job' | 'run' | 'risk' | 'recommendation' | 'alert' | 'severity' | 'source' | 'connector' | 'model'; value: string | null | undefined }>()
const { t, te } = useI18n()

const TONES: Record<string, string> = {
  completed: 'badge-success', succeeded: 'badge-success', validated: 'badge-info', active: 'badge-success', available: 'badge-success',
  production: 'badge-success', resolved: 'badge-success', ordered: 'badge-success', low: 'badge-success',
  running: 'badge-brand', validating: 'badge-brand', importing: 'badge-brand', queued: 'badge-info', uploaded: 'badge-info',
  awaiting_mapping: 'badge-warning', requires_credentials: 'badge-warning', medium: 'badge-warning', warning: 'badge-warning',
  open: 'badge-warning', candidate: 'badge-info', fallback: 'badge-info', acknowledged: 'badge-info', info: 'badge-info',
  failed: 'badge-danger', error: 'badge-danger', rejected: 'badge-danger', high: 'badge-danger', critical: 'badge-danger',
}

const label = computed(() => {
  if (!props.value) return '—'
  const key = `status.${props.domain}.${props.value}`
  return te(key) ? t(key) : props.value
})
const tone = computed(() => (props.value ? TONES[props.value] ?? '' : ''))
</script>

<template>
  <span class="badge" :class="tone">{{ label }}</span>
</template>
