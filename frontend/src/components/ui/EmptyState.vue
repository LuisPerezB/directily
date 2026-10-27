<script setup lang="ts">
import AppIcon, { type IconName } from './AppIcon.vue'

withDefaults(defineProps<{ title: string; description?: string | null; icon?: IconName; compact?: boolean }>(), {
  description: null,
  icon: 'layers',
  compact: false,
})
</script>

<template>
  <div class="flex flex-col items-center text-center gap-3" :class="compact ? 'py-6 px-4' : 'py-12 px-6'" role="status">
    <span class="rounded-2xl bg-brand-soft text-brand-strong p-3"><AppIcon :name="icon" :size="compact ? 22 : 28" /></span>
    <h3 class="text-base font-semibold">{{ title }}</h3>
    <p v-if="description" class="max-w-md text-sm text-fg-muted">{{ description }}</p>
    <div v-if="$slots.default" class="flex flex-wrap justify-center gap-2 mt-1"><slot /></div>
  </div>
</template>
