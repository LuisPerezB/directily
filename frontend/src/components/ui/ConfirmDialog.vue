<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import AppModal from './AppModal.vue'

withDefaults(defineProps<{ open: boolean; title: string; message: string; confirmLabel?: string | null; danger?: boolean; busy?: boolean }>(), {
  confirmLabel: null,
  danger: false,
  busy: false,
})
const emit = defineEmits<{ confirm: []; cancel: [] }>()
const { t } = useI18n()
</script>

<template>
  <AppModal :open="open" :title="title" size="sm" @close="emit('cancel')">
    <p class="text-sm text-fg-muted">{{ message }}</p>
    <slot />
    <template #footer>
      <button type="button" class="btn btn-secondary" :disabled="busy" @click="emit('cancel')">{{ t('common.cancel') }}</button>
      <button type="button" class="btn" :class="danger ? 'btn-danger' : 'btn-primary'" :disabled="busy" @click="emit('confirm')">
        {{ confirmLabel ?? t('common.confirm') }}
      </button>
    </template>
  </AppModal>
</template>
