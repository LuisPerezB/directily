<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from './AppIcon.vue'
import { errorMessage, isApiError } from '@/api/errors'

const props = withDefaults(defineProps<{ error: unknown; title?: string | null; compact?: boolean }>(), { title: null, compact: false })
const emit = defineEmits<{ retry: [] }>()
const { t } = useI18n()

const message = computed(() => errorMessage(props.error, t))
const isForbidden = computed(() => isApiError(props.error) && props.error.status === 403)
</script>

<template>
  <div class="flex flex-col items-center text-center gap-3" :class="compact ? 'py-5 px-4' : 'py-10 px-6'" role="alert">
    <span class="rounded-2xl bg-danger-soft text-danger p-3"><AppIcon :name="isForbidden ? 'shield' : 'alert'" :size="24" /></span>
    <h3 class="text-base font-semibold">{{ title ?? (isForbidden ? t('errors.forbiddenTitle') : t('errors.loadTitle')) }}</h3>
    <p class="max-w-md text-sm text-fg-muted">{{ message }}</p>
    <button v-if="!isForbidden" type="button" class="btn btn-secondary btn-sm" @click="emit('retry')">
      <AppIcon name="refresh" :size="16" /> {{ t('common.retry') }}
    </button>
  </div>
</template>
