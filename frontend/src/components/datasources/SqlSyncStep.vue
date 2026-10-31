<script setup lang="ts">
/** Paso 3: sincronización. Reutiliza el mismo flujo de importación que un archivo; si hay filas con error, queda "por revisar" y se enlaza a la importación. */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { sqlSourcesApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { SourceSync, SqlEntity } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { usePolling } from '@/composables/usePolling'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatDateTime, formatInteger } from '@/utils/format'
import { SQL_ENTITIES } from '@/utils/sqlSource'

const props = defineProps<{ sourceId: number }>()
const emit = defineEmits<{ synced: [] }>()
const { t } = useI18n()
const tenant = useTenantStore()
const ui = useUiStore()
const entity = ref<SqlEntity>('sales')
const mode = ref<'full' | 'incremental'>('incremental')
const syncs = useAsync(() => sqlSourcesApi.syncs(props.sourceId, { per_page: 20 }))
const starting = ref(false)
const message = ref<string | null>(null)
const TERMINAL = new Set(['completed', 'needs_review', 'failed'])
const current = ref<SourceSync | null>(null)

const polling = usePolling(() => sqlSourcesApi.syncById(current.value!.id), {
  interval: 2500,
  until: (s) => TERMINAL.has(s.status),
  onUpdate: (s) => {
    current.value = s
    if (TERMINAL.has(s.status)) {
      void syncs.execute()
      emit('synced')
      if (s.status === 'completed') ui.notify(t('sql.sync.completed'), 'success')
    }
  },
})

const running = computed(() => current.value !== null && !TERMINAL.has(current.value.status))

async function start(): Promise<void> {
  starting.value = true
  message.value = null
  try {
    current.value = await sqlSourcesApi.sync(props.sourceId, entity.value, mode.value)
    polling.start(false)
    void syncs.execute()
  } catch (e) {
    const code = isApiError(e) ? e.code : null
    message.value = code === 'mapping_not_approved' ? t('sql.sync.notApproved') : code === 'sync_in_progress' ? t('sql.sync.inProgress') : errorMessage(e, t)
  } finally {
    starting.value = false
  }
}

const columns = computed<Column<SourceSync>[]>(() => [
  { key: 'entity', label: t('sql.query.entity'), format: (s) => t(`sql.entities.${s.entity}`) },
  { key: 'mapping_version', label: t('sql.sync.version'), format: (s) => (s.mapping_version != null ? `v${s.mapping_version}` : '—') },
  { key: 'mode', label: t('sql.sync.mode'), format: (s) => t(`sql.sync.modes.${s.mode}`) },
  { key: 'status', label: t('common.status') },
  { key: 'rows_extracted', label: t('sql.sync.rows'), align: 'right', format: (s) => (s.rows_extracted != null ? formatInteger(s.rows_extracted) : '—') },
  { key: 'finished_at', label: t('sql.sync.finished'), format: (s) => formatDateTime(s.finished_at ?? s.started_at ?? s.created_at, tenant.timezone) },
])
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('sql.steps.sync')" data-test="sql-sync">
    <InlineNotice kind="info">{{ t('sql.sync.principle') }}</InlineNotice>
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="label" for="ss-entity">{{ t('sql.query.entity') }}</label>
        <select id="ss-entity" v-model="entity" class="select !w-auto min-w-[14rem]"><option v-for="e in SQL_ENTITIES" :key="e" :value="e">{{ t(`sql.entities.${e}`) }}</option></select>
      </div>
      <div>
        <label class="label" for="ss-mode">{{ t('sql.sync.mode') }}</label>
        <select id="ss-mode" v-model="mode" class="select !w-auto"><option value="incremental">{{ t('sql.sync.modes.incremental') }}</option><option value="full">{{ t('sql.sync.modes.full') }}</option></select>
      </div>
      <button type="button" class="btn btn-primary" :disabled="starting || running" data-test="start-sync" @click="start">{{ running ? t('sql.sync.running') : t('sql.sync.start') }}</button>
    </div>
    <p v-if="message" class="field-error" role="alert" data-test="sync-message">{{ message }}</p>

    <div v-if="current" class="rounded-xl border border-line p-4 flex flex-col gap-2" aria-live="polite" data-test="current-sync">
      <div class="flex items-center gap-2"><span class="font-semibold text-sm">{{ t('sql.sync.current', { id: current.id }) }}</span><StatusBadge domain="sync" :value="current.status" /></div>
      <p v-if="current.status === 'needs_review'" class="m-0 text-sm" data-test="needs-review">
        {{ t('sql.sync.needsReview') }}
        <RouterLink v-if="current.import_job_id" :to="{ name: 'import-detail', params: { id: String(current.import_job_id) } }" class="font-semibold">{{ t('sql.sync.openImport') }}</RouterLink>
      </p>
      <p v-else-if="current.status === 'failed'" class="m-0 text-sm text-danger">{{ current.error_message ?? t('jobs.failed') }}</p>
    </div>

    <ErrorState v-if="syncs.error.value" :error="syncs.error.value" compact @retry="syncs.execute" />
    <DataTable v-else :columns="columns" :rows="syncs.data.value?.data ?? []" :row-key="(s) => s.id" :loading="syncs.loading.value" :caption="t('sql.sync.history')" :empty-text="t('sql.sync.empty')">
      <template #cell-status="{ row }">
        <StatusBadge domain="sync" :value="row.status" />
        <p v-if="row.error_message" class="text-xs text-danger mt-1 max-w-xs">{{ row.error_message }}</p>
        <RouterLink v-if="row.status === 'needs_review' && row.import_job_id" :to="{ name: 'import-detail', params: { id: String(row.import_job_id) } }" class="block text-xs font-semibold">{{ t('sql.sync.openImport') }}</RouterLink>
      </template>
    </DataTable>
  </section>
</template>
