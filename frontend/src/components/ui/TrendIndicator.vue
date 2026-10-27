<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from './AppIcon.vue'
import { formatChange } from '@/utils/format'
import type { Trend } from '@/api/types'

const props = defineProps<{ trend: Trend | null | undefined; change?: number | null }>()
const { t } = useI18n()
const icon = computed(() => (props.trend === 'up' ? 'trend-up' : props.trend === 'down' ? 'trend-down' : 'minus'))
const tone = computed(() => (props.trend === 'up' ? 'text-success' : props.trend === 'down' ? 'text-danger' : 'text-fg-muted'))
</script>

<template>
  <span class="inline-flex items-center gap-1 font-semibold tabular" :class="tone">
    <AppIcon :name="icon" :size="16" :label="t(`trend.${trend ?? 'flat'}`)" />
    <span v-if="change !== undefined">{{ formatChange(change ?? null) }}</span>
  </span>
</template>
