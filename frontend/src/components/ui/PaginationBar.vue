<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from './AppIcon.vue'
import type { PaginationMeta } from '@/api/types'
import { formatInteger } from '@/utils/format'

const props = defineProps<{ meta: PaginationMeta | null }>()
const page = defineModel<number>('page', { required: true })
const { t } = useI18n()
const last = computed(() => props.meta?.last_page ?? 1)
const range = computed(() => {
  if (!props.meta || props.meta.total === 0) return null
  const from = (props.meta.current_page - 1) * props.meta.per_page + 1
  const to = Math.min(props.meta.total, props.meta.current_page * props.meta.per_page)
  return { from, to, total: props.meta.total }
})
</script>

<template>
  <nav v-if="meta && meta.total > 0" class="flex flex-wrap items-center justify-between gap-2 px-1 py-3 text-sm text-fg-muted" :aria-label="t('table.pagination')">
    <span v-if="range">{{ t('table.range', { from: formatInteger(range.from), to: formatInteger(range.to), total: formatInteger(range.total) }) }}</span>
    <div class="flex items-center gap-1">
      <button type="button" class="btn btn-ghost btn-sm" :disabled="page <= 1" :aria-label="t('table.previous')" @click="page = page - 1">
        <AppIcon name="chevron-left" :size="16" />
      </button>
      <span class="px-2 tabular" aria-live="polite">{{ t('table.pageOf', { page, last }) }}</span>
      <button type="button" class="btn btn-ghost btn-sm" :disabled="page >= last" :aria-label="t('table.next')" @click="page = page + 1">
        <AppIcon name="chevron-right" :size="16" />
      </button>
    </div>
  </nav>
</template>
