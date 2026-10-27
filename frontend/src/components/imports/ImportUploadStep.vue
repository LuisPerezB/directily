<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ProgressBar from '@/components/ui/ProgressBar.vue'
import { exportsApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { ImportEntityType, ImportField } from '@/api/types'
import { checkUploadFile, MAX_UPLOAD_BYTES } from '@/utils/importMapping'
import { formatBytes } from '@/utils/format'
import { useUiStore } from '@/stores/ui'

const entity = defineModel<ImportEntityType>('entity', { required: true })
defineProps<{ fields: ImportField[]; uploading: boolean; progress: number; error: string | null }>()
const emit = defineEmits<{ upload: [file: File] }>()
const { t } = useI18n()
const ui = useUiStore()
const file = ref<File | null>(null)
const fileError = ref<string | null>(null)
const dragging = ref(false)
const ENTITIES: ImportEntityType[] = ['sales', 'products', 'inventory', 'lead_times']

function pick(f: File | null | undefined): void {
  fileError.value = null
  if (!f) return
  const check = checkUploadFile(f)
  if (check !== 'ok') {
    fileError.value = t(`imports.fileErrors.${check}`, { max: formatBytes(MAX_UPLOAD_BYTES) })
    file.value = null
    return
  }
  file.value = f
}

function onDrop(e: DragEvent): void {
  dragging.value = false
  pick(e.dataTransfer?.files?.[0])
}

async function template(): Promise<void> {
  try {
    await exportsApi.importTemplate(entity.value)
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-[1fr_minmax(0,380px)]">
    <form class="flex flex-col gap-5" @submit.prevent="file && emit('upload', file)">
      <div>
        <label class="label" for="imp-entity">{{ t('imports.entityLabel') }}</label>
        <select id="imp-entity" v-model="entity" class="select">
          <option v-for="e in ENTITIES" :key="e" :value="e">{{ t(`imports.entities.${e}`) }}</option>
        </select>
        <p class="hint mt-1">{{ t(`imports.entityHints.${entity}`) }}</p>
      </div>
      <div
        class="rounded-2xl border-2 border-dashed p-8 text-center transition-colors"
        :class="dragging ? 'border-brand bg-brand-soft' : 'border-line-strong bg-surface-2'"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
      >
        <AppIcon name="upload" :size="30" class="mx-auto text-brand-strong" />
        <p class="font-semibold mt-2 mb-1">{{ t('imports.dropTitle') }}</p>
        <p class="text-sm text-fg-muted mb-3">{{ t('imports.dropHint', { max: formatBytes(MAX_UPLOAD_BYTES) }) }}</p>
        <label class="btn btn-secondary btn-sm cursor-pointer">
          {{ t('imports.chooseFile') }}
          <input type="file" class="sr-only" accept=".csv,.txt,.xlsx" @change="pick(($event.target as HTMLInputElement).files?.[0])" />
        </label>
        <p v-if="file" class="mt-3 text-sm"><AppIcon name="file" :size="15" class="inline -mt-0.5" /> {{ file.name }} · {{ formatBytes(file.size) }}</p>
        <p v-if="fileError" class="field-error" role="alert">{{ fileError }}</p>
      </div>
      <InlineNotice kind="info">{{ t('imports.idempotencyNote') }}</InlineNotice>
      <div v-if="uploading" class="flex flex-col gap-2">
        <ProgressBar :value="progress" :label="t('imports.uploading')" />
        <p class="hint">{{ t('imports.uploadingText', { pct: progress }) }}</p>
      </div>
      <p v-if="error" class="field-error" role="alert">{{ error }}</p>
      <div>
        <button type="submit" class="btn btn-primary" :disabled="!file || uploading"><AppIcon name="upload" :size="16" /> {{ t('imports.uploadAndAnalyze') }}</button>
      </div>
    </form>

    <aside class="rounded-2xl border border-line p-5 bg-surface-2/50">
      <h2 class="section-title mb-1">{{ t('imports.fieldsTitle') }}</h2>
      <p class="text-sm text-fg-muted mb-3">{{ t('imports.fieldsText') }}</p>
      <ul class="flex flex-col gap-2 list-none p-0 m-0 text-sm max-h-96 overflow-y-auto">
        <li v-for="f in fields" :key="f.key">
          <span class="font-semibold">{{ f.label }}</span>
          <span v-if="f.required" class="badge badge-danger ml-1">{{ t('common.required') }}</span>
          <code class="ml-1 text-xs text-fg-muted">{{ f.key }}</code>
          <p v-if="f.description" class="text-xs text-fg-muted m-0">{{ f.description }}</p>
        </li>
      </ul>
      <button type="button" class="btn btn-ghost btn-sm mt-3" @click="template"><AppIcon name="download" :size="15" /> {{ t('imports.downloadTemplate') }}</button>
    </aside>
  </div>
</template>
