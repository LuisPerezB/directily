<script setup lang="ts">
/**
 * Califica una SUMA PARCIAL: las oportunidades que no entran en ella (sin costo confiable o sin valor estimado) se cuentan aparte. Una suma que deja fuera
 * oportunidades nunca se presenta como el total de todas (V25-UIX-009; M2 de la revisión). No muestra nada si no queda ninguna fuera.
 */
import { useI18n } from 'vue-i18n'

defineProps<{ n: number | null | undefined; reason: 'cost' | 'value' }>()
const { t } = useI18n()
</script>

<template>
  <span v-if="(n ?? 0) > 0" class="block text-xs text-fg-muted" data-test="partial-sum" :data-reason="reason">{{ t(`enterprise.partial.${reason}`, { n }) }}</span>
</template>
