<script setup lang="ts">
import { useI18n } from 'vue-i18n'

withDefaults(defineProps<{ lines?: number; label?: string | null }>(), { lines: 3, label: null })
const { t } = useI18n()
</script>

<template>
  <div class="flex flex-col gap-3 py-6" role="status" aria-live="polite">
    <span class="sr-only">{{ label ?? t('common.loading') }}</span>
    <div v-for="i in lines" :key="i" class="h-4 rounded bg-surface-2 animate-pulse" :style="{ width: `${100 - i * 12}%` }" />
  </div>
</template>
