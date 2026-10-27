<script setup lang="ts">
import { computed } from 'vue'
import AppIcon, { type IconName } from './AppIcon.vue'
import { formatChange } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    label: string
    value: string
    hint?: string | null
    question?: string | null
    icon?: IconName
    /** Variación como proporción (0.05 = +5 %). */
    delta?: number | null
    deltaLabel?: string | null
    /** Si una subida es buena (ventas) o mala (error, riesgo). */
    upIsGood?: boolean
    loading?: boolean
    tone?: 'default' | 'brand' | 'warning' | 'danger'
  }>(),
  { hint: null, question: null, icon: undefined, delta: null, deltaLabel: null, upIsGood: true, loading: false, tone: 'default' },
)

const deltaClass = computed(() => {
  if (props.delta === null || props.delta === undefined || Math.abs(props.delta) < 0.0005) return 'text-fg-muted'
  const good = props.delta > 0 === props.upIsGood
  return good ? 'text-success' : 'text-danger'
})
const iconBg = computed(() => ({
  default: 'bg-surface-2 text-fg-muted',
  brand: 'bg-brand-soft text-brand-strong',
  warning: 'bg-warning-soft text-warning',
  danger: 'bg-danger-soft text-danger',
})[props.tone])
</script>

<template>
  <article class="card p-4 sm:p-5 flex flex-col gap-2 min-w-0" :aria-busy="loading">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <p v-if="question" class="text-xs font-semibold uppercase tracking-wide text-brand-strong">{{ question }}</p>
        <h3 class="text-sm font-medium text-fg-muted">{{ label }}</h3>
      </div>
      <span v-if="icon" class="rounded-lg p-2 shrink-0" :class="iconBg"><AppIcon :name="icon" :size="18" /></span>
    </div>
    <div v-if="loading" class="h-8 w-32 rounded bg-surface-2 animate-pulse" />
    <p v-else class="text-2xl font-bold tabular tracking-tight break-words">{{ value }}</p>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
      <span v-if="!loading && delta !== null && delta !== undefined" class="font-semibold tabular" :class="deltaClass">{{ formatChange(delta) }}</span>
      <span v-if="deltaLabel && delta !== null" class="text-fg-muted">{{ deltaLabel }}</span>
      <span v-if="hint" class="text-fg-muted">{{ hint }}</span>
    </div>
    <slot />
  </article>
</template>
