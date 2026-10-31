<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { qualityTone, type Tone } from '@/utils/envelope'

/**
 * Aptitud estadística del resultado (`quality_status` de la envoltura). Distinta del estado técnico del trabajo.
 * Un estado desconocido o ausente se muestra como "Sin evaluar", nunca como "Correcto".
 */
const props = defineProps<{ status: string | null | undefined }>()
const { t, te } = useI18n()

const CLASSES: Record<Tone, string> = {
  success: 'badge-success',
  info: 'badge-info',
  warning: 'badge-warning',
  danger: 'badge-danger',
  neutral: '',
}
const label = computed(() => (props.status && te(`quality.${props.status}`) ? t(`quality.${props.status}`) : t('quality.unknown')))
const classes = computed(() => CLASSES[qualityTone(props.status)])
</script>

<template>
  <span class="badge" :class="classes" data-test="quality-badge" :data-status="status ?? 'unknown'">{{ label }}</span>
</template>
