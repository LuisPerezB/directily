<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'

const props = withDefaults(defineProps<{ kind?: 'info' | 'warning' | 'danger' | 'success'; title?: string | null }>(), { kind: 'info', title: null })
const styles = computed(() => ({
  info: 'bg-info-soft text-info',
  warning: 'bg-warning-soft text-warning',
  danger: 'bg-danger-soft text-danger',
  success: 'bg-success-soft text-success',
})[props.kind])
const icon = computed(() => (props.kind === 'success' ? 'check' : props.kind === 'info' ? 'info' : 'alert'))
</script>

<template>
  <div class="rounded-xl px-4 py-3 flex gap-3 items-start" :class="styles" :role="kind === 'danger' ? 'alert' : 'note'">
    <AppIcon :name="icon" :size="18" class="mt-0.5 shrink-0" />
    <div class="text-sm text-fg min-w-0">
      <p v-if="title" class="font-semibold mb-0.5" :class="styles.split(' ')[1]">{{ title }}</p>
      <slot />
    </div>
  </div>
</template>
