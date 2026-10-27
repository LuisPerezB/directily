<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import AppIcon from './AppIcon.vue'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
const { t } = useI18n()
const tone: Record<string, string> = {
  success: 'border-l-success',
  error: 'border-l-danger',
  warning: 'border-l-warning',
  info: 'border-l-info',
}
</script>

<template>
  <div class="fixed bottom-4 right-4 left-4 sm:left-auto z-[60] flex flex-col gap-2 sm:w-96" aria-live="polite" aria-atomic="false">
    <div v-for="toast in ui.toasts" :key="toast.id" class="card border-l-4 px-4 py-3 flex items-start gap-3" :class="tone[toast.kind]" :role="toast.kind === 'error' ? 'alert' : 'status'">
      <p class="text-sm flex-1">{{ toast.message }}</p>
      <button type="button" class="btn btn-ghost btn-sm -mr-2 -mt-1" :aria-label="t('common.close')" @click="ui.dismiss(toast.id)">
        <AppIcon name="x" :size="16" />
      </button>
    </div>
  </div>
</template>
