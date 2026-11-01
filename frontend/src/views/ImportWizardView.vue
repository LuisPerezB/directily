<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import ImportStepper from '@/components/imports/ImportStepper.vue'
import ImportUploadStep from '@/components/imports/ImportUploadStep.vue'
import ImportMappingStep from '@/components/imports/ImportMappingStep.vue'
import ImportReviewStep from '@/components/imports/ImportReviewStep.vue'
import ProgressBar from '@/components/ui/ProgressBar.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import RunForecastPanel from '@/components/forecast/RunForecastPanel.vue'
import { importsApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { ColumnMapping, ImportEntityType, ImportField, ImportJob, ImportOptions } from '@/api/types'
import { usePolling } from '@/composables/usePolling'
import { useCatalogStore } from '@/stores/catalog'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { FALLBACK_FIELDS, isImportBusy, stepForStatus } from '@/utils/importMapping'
import { formatInteger } from '@/utils/format'

const props = defineProps<{ id?: string }>()
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const catalog = useCatalogStore()
const tenant = useTenantStore()
const ui = useUiStore()

const ENTITIES: ImportEntityType[] = ['sales', 'products', 'inventory', 'lead_times', 'branches', 'suppliers', 'promotions', 'commitments', 'calendar_events', 'unit_conversions', 'warehouses']
const initialEntity = ENTITIES.includes(route.query.entity as ImportEntityType) ? (route.query.entity as ImportEntityType) : 'sales'
const entity = ref<ImportEntityType>(initialEntity)
const job = ref<ImportJob | null>(null)
const loading = ref(false)
const loadError = ref<unknown>(null)
const fields = ref<ImportField[]>(FALLBACK_FIELDS[initialEntity])
const uploading = ref(false)
const uploadProgress = ref(0)
const actionError = ref<string | null>(null)
const acting = ref(false)
const editingMapping = ref(false)
const confirmReject = ref(false)

const step = computed(() => {
  if (!job.value) return 'upload' as const
  if (editingMapping.value) return 'mapping' as const
  return stepForStatus(job.value.status)
})
const canManage = computed(() => tenant.can('imports.manage'))

async function loadFields(e: ImportEntityType): Promise<void> {
  try {
    const list = await importsApi.fields(e)
    fields.value = list.length ? list : FALLBACK_FIELDS[e]
  } catch {
    fields.value = FALLBACK_FIELDS[e]
  }
}
watch(entity, (e) => void loadFields(e), { immediate: true })

const polling = usePolling(() => importsApi.get(job.value!.id), {
  interval: 2000,
  until: (j) => !isImportBusy(j.status),
  onUpdate: (j) => {
    job.value = j
    if (j.status === 'completed') {
      ui.notify(t('imports.completedToast'), 'success')
      void catalog.ensureLoaded(tenant.currentUuid, true)
      void tenant.load().catch(() => undefined)
    }
  },
})

async function loadJob(id: number): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    job.value = await importsApi.get(id)
    entity.value = job.value.entity_type
    if (isImportBusy(job.value.status)) polling.start(false)
  } catch (e) {
    loadError.value = e
  } finally {
    loading.value = false
  }
}

async function upload(file: File): Promise<void> {
  uploading.value = true
  uploadProgress.value = 0
  actionError.value = null
  try {
    const created = await importsApi.upload(file, entity.value, (p) => (uploadProgress.value = p))
    job.value = created
    await router.replace({ name: 'import-detail', params: { id: String(created.id) } })
    if (isImportBusy(created.status)) polling.start(false)
  } catch (e) {
    actionError.value = errorMessage(e, t)
  } finally {
    uploading.value = false
  }
}

async function saveMapping(mapping: ColumnMapping, options: ImportOptions): Promise<void> {
  if (!job.value) return
  acting.value = true
  actionError.value = null
  try {
    job.value = await importsApi.saveMapping(job.value.id, mapping, options)
    editingMapping.value = false
    polling.start(false)
  } catch (e) {
    actionError.value = errorMessage(e, t)
  } finally {
    acting.value = false
  }
}

async function confirm(): Promise<void> {
  if (!job.value) return
  acting.value = true
  try {
    job.value = await importsApi.confirm(job.value.id)
    polling.start(false)
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    acting.value = false
  }
}

async function reject(): Promise<void> {
  if (!job.value) return
  confirmReject.value = false
  acting.value = true
  try {
    job.value = await importsApi.reject(job.value.id)
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    acting.value = false
  }
}

function startOver(): void {
  polling.stop()
  job.value = null
  editingMapping.value = false
  void router.push({ name: 'import-new', query: { entity: entity.value } })
}

watch(
  () => props.id,
  (id) => {
    if (id) void loadJob(Number(id))
    else if (job.value) {
      job.value = null
      editingMapping.value = false
    }
  },
)
onMounted(() => {
  if (props.id) void loadJob(Number(props.id))
})
</script>

<template>
  <div class="flex flex-col gap-6">
    <header class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
      <div>
        <h1 class="page-title">{{ t('imports.title') }}</h1>
        <p class="text-fg-muted mt-1">{{ t('imports.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <StatusBadge v-if="job" domain="import" :value="job.status" />
        <RouterLink :to="{ name: 'data-sources' }" class="btn btn-ghost btn-sm">{{ t('imports.history') }}</RouterLink>
      </div>
    </header>

    <ImportStepper :step="step" />

    <section class="card p-4 sm:p-6">
      <LoadingState v-if="loading" :lines="5" />
      <ErrorState v-else-if="loadError" :error="loadError" @retry="loadJob(Number(props.id))" />

      <ImportUploadStep v-else-if="step === 'upload'" v-model:entity="entity" :fields="fields" :uploading="uploading" :progress="uploadProgress" :error="actionError" @upload="upload" />

      <template v-else-if="job && step === 'mapping'">
        <InlineNotice v-if="!canManage" kind="info" class="mb-4">{{ t('imports.readOnly') }}</InlineNotice>
        <ImportMappingStep :job="job" :fields="fields" :branches="catalog.branches" :saving="acting" :error="actionError" @submit="saveMapping" @cancel="editingMapping ? (editingMapping = false) : (confirmReject = true)" />
      </template>

      <div v-else-if="job && (step === 'validating' || step === 'importing')" class="flex flex-col gap-4 py-6 max-w-xl" aria-live="polite">
        <h2 class="section-title">{{ step === 'validating' ? t('imports.validatingTitle') : t('imports.importingTitle') }}</h2>
        <ProgressBar :value="job.progress" :label="t('imports.progressLabel')" :indeterminate="!job.progress" />
        <p class="text-sm text-fg-muted">{{ step === 'validating' ? t('imports.validatingText') : t('imports.importingText') }} <span v-if="job.progress" class="tabular">({{ job.progress }} %)</span></p>
        <p class="hint">{{ t('imports.canLeave') }}</p>
      </div>

      <ImportReviewStep v-else-if="job && step === 'review'" :job="job" :busy="acting" :can-manage="canManage" @confirm="confirm" @reject="confirmReject = true" @edit="editingMapping = true" />

      <div v-else-if="job && step === 'done'" class="flex flex-col gap-5">
        <EmptyState icon="check" :title="t('imports.doneTitle')" :description="t('imports.doneText')" compact />
        <dl v-if="job.entity_type === 'sales'" class="grid gap-3 sm:grid-cols-3 text-center">
          <div class="rounded-xl bg-surface-2 p-4"><dt class="text-sm text-fg-muted">{{ t('imports.stats.documents_new') }}</dt><dd class="m-0 text-2xl font-bold tabular">{{ formatInteger(Number(job.stats?.documents_new ?? 0)) }}</dd></div>
          <div class="rounded-xl bg-surface-2 p-4"><dt class="text-sm text-fg-muted">{{ t('imports.stats.documents_updated') }}</dt><dd class="m-0 text-2xl font-bold tabular">{{ formatInteger(Number(job.stats?.documents_updated ?? 0)) }}</dd></div>
          <div class="rounded-xl bg-surface-2 p-4"><dt class="text-sm text-fg-muted">{{ t('imports.stats.documents_unchanged') }}</dt><dd class="m-0 text-2xl font-bold tabular">{{ formatInteger(Number(job.stats?.documents_unchanged ?? 0)) }}</dd></div>
        </dl>
        <InlineNotice kind="success">{{ t('imports.idempotencyDone') }}</InlineNotice>
        <div v-if="job.entity_type === 'sales' || job.entity_type === 'inventory'" class="rounded-xl border border-line p-4">
          <p class="font-semibold mb-2">{{ t('imports.nextForecast') }}</p>
          <RunForecastPanel />
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="btn btn-secondary" @click="startOver">{{ t('imports.importAnother') }}</button>
          <RouterLink :to="{ name: 'dashboard' }" class="btn btn-ghost">{{ t('imports.goDashboard') }}</RouterLink>
        </div>
      </div>

      <div v-else-if="job && step === 'rejected'">
        <EmptyState icon="x" :title="t('imports.rejectedTitle')" :description="t('imports.rejectedText')" compact>
          <button type="button" class="btn btn-primary btn-sm" @click="startOver">{{ t('imports.importAnother') }}</button>
        </EmptyState>
      </div>
    </section>

    <ConfirmDialog :open="confirmReject" :title="t('imports.rejectTitle')" :message="t('imports.rejectText')" danger :confirm-label="t('imports.reject')" @confirm="reject" @cancel="confirmReject = false" />
  </div>
</template>
