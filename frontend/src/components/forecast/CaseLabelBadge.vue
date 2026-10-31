<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { caseTone } from '@/utils/envelope'

/** Caso especial de la serie (producto nuevo con respaldo por categoría, descontinuado, sucursal cerrada...). */
const props = defineProps<{ label: string | null | undefined }>()
const { t, te } = useI18n()
const text = computed(() => (props.label ? (te(`caseLabels.${props.label}`) ? t(`caseLabels.${props.label}`) : props.label) : ''))
const hint = computed(() => (props.label && te(`caseLabelHints.${props.label}`) ? t(`caseLabelHints.${props.label}`) : undefined))
const classes = computed(() => ({ warning: 'badge-warning', danger: 'badge-danger', info: 'badge-info', success: 'badge-success', neutral: '' })[caseTone(props.label)])
</script>

<template>
  <span v-if="label" class="badge" :class="classes" :title="hint" data-test="case-label">{{ text }}</span>
</template>
