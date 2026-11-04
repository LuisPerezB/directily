<script setup lang="ts">
/** Etiqueta del estado del workflow de decisiones, del estado heredado de la agenda o de la prioridad (textos en `decisions.status`, `radar.state`). */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ domain: 'workflow' | 'agenda' | 'attribution'; value: string | null | undefined }>()
const { t, te } = useI18n()

const TONES: Record<string, string> = {
  suggested: '', assigned: 'badge-info', reviewed: 'badge-info', approved: 'badge-success', rejected: 'badge-danger', in_progress: 'badge-brand',
  executed: 'badge-success', measured: 'badge-info', closed: '', expired: 'badge-warning',
  pendiente: '', contactado: 'badge-brand', interesado: 'badge-brand', pedido_recibido: 'badge-success', pospuesto: 'badge-warning', descartado: 'badge-danger',
  potential_unproven: 'badge-warning', incremental_estimated: 'badge-info', causally_attributable: 'badge-success',
}
const label = computed(() => {
  if (!props.value) return '—'
  const key = props.domain === 'workflow' ? `decisions.status.${props.value}` : props.domain === 'agenda' ? `radar.state.${props.value}` : `enterprise.attribution.${props.value}`
  return te(key) ? t(key) : props.value
})
const tone = computed(() => (props.value ? TONES[props.value] ?? '' : ''))
</script>

<template>
  <span class="badge" :class="tone" :data-value="value ?? undefined">{{ label }}</span>
</template>
