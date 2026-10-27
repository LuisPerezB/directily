<script setup lang="ts">
withDefaults(defineProps<{ value: number; label: string; indeterminate?: boolean }>(), { indeterminate: false })
</script>

<template>
  <div>
    <div
      class="h-2.5 w-full rounded-full bg-surface-2 overflow-hidden"
      role="progressbar"
      :aria-label="label"
      aria-valuemin="0"
      aria-valuemax="100"
      :aria-valuenow="indeterminate ? undefined : Math.round(value)"
    >
      <div class="h-full rounded-full bg-brand transition-all" :class="indeterminate ? 'w-1/3 animate-pulse' : ''" :style="indeterminate ? undefined : { width: `${Math.min(100, Math.max(0, value))}%` }" />
    </div>
  </div>
</template>
