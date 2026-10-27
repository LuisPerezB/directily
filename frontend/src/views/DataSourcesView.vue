<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import AppModal from '@/components/ui/AppModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import { dataSourcesApi, importsApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { DataSource, DataSourceType, ImportJob } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatBytes, formatDateTime } from '@/utils/format'

const { t } = useI18n()
const tenant = useTenantStore()
const ui = useUiStore()
const canManage = computed(() => tenant.can('datasources.manage'))

const sources = useAsync(() => dataSourcesApi.list())
const connectors = useAsync(() => dataSourcesApi.connectors())
const importsPage = ref(1)
const imports = useAsync(() => importsApi.list({ page: importsPage.value, per_page: 15 }), { watch: [importsPage] })

const showCreate = ref(false)
const creating = ref(false)
const createError = ref<string | null>(null)
const form = reactive<{ type: DataSourceType; name: string; connector_key: string | null }>({ type: 'rest_api', name: '', connector_key: null })
const token = ref<string | null>(null)
const tokenFor = ref<DataSource | null>(null)
const toDelete = ref<DataSource | null>(null)
const copied = ref(false)

const connectorOptions = computed(() => (connectors.data.value ?? []).filter((c) => c.status !== 'planned'))

async function create(): Promise<void> {
  creating.value = true
  createError.value = null
  try {
    await dataSourcesApi.create({ type: form.type, name: form.name.trim(), connector_key: form.type === 'connector' ? form.connector_key : null })
    showCreate.value = false
    form.name = ''
    await sources.execute()
    ui.notify(t('sources.created'), 'success')
  } catch (e) {
    createError.value = errorMessage(e, t)
  } finally {
    creating.value = false
  }
}

async function createToken(source: DataSource): Promise<void> {
  try {
    const result = await dataSourcesApi.createToken(source.id)
    token.value = result.token
    tokenFor.value = source
    copied.value = false
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}

async function copyToken(): Promise<void> {
  if (!token.value) return
  try {
    await navigator.clipboard.writeText(token.value)
    copied.value = true
  } catch {
    copied.value = false
  }
}

function closeToken(): void {
  token.value = null
  tokenFor.value = null
}

async function remove(): Promise<void> {
  if (!toDelete.value) return
  try {
    await dataSourcesApi.remove(toDelete.value.id)
    await sources.execute()
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    toDelete.value = null
  }
}

const importColumns = computed<Column<ImportJob>[]>(() => [
  { key: 'created_at', label: t('common.date'), format: (r) => formatDateTime(r.created_at, tenant.timezone) },
  { key: 'entity_type', label: t('imports.entityLabel'), format: (r) => t(`imports.entities.${r.entity_type}`) },
  { key: 'original_filename', label: t('sources.file') },
  { key: 'file_size', label: t('sources.size'), align: 'right', format: (r) => formatBytes(r.file_size) },
  { key: 'status', label: t('common.status') },
  { key: 'user', label: t('sources.user'), format: (r) => r.user?.name ?? '—' },
  { key: 'open', label: '' },
])
</script>

<template>
  <div class="flex flex-col gap-6">
    <header class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
      <div>
        <h1 class="page-title">{{ t('sources.title') }}</h1>
        <p class="text-fg-muted mt-1">{{ t('sources.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <RouterLink v-if="tenant.can('imports.manage')" :to="{ name: 'import-new' }" class="btn btn-primary"><AppIcon name="upload" :size="16" /> {{ t('sources.newImport') }}</RouterLink>
        <button v-if="canManage" type="button" class="btn btn-secondary" @click="showCreate = true"><AppIcon name="plus" :size="16" /> {{ t('sources.add') }}</button>
      </div>
    </header>

    <InlineNotice kind="info">{{ t('sources.principle') }}</InlineNotice>

    <section class="card p-4 sm:p-6">
      <h2 class="section-title mb-3">{{ t('sources.configured') }}</h2>
      <ErrorState v-if="sources.error.value" :error="sources.error.value" compact @retry="sources.execute" />
      <ul v-else-if="sources.data.value?.length" class="flex flex-col divide-y divide-line list-none p-0 m-0">
        <li v-for="s in sources.data.value" :key="s.id" class="py-3 flex flex-wrap items-center gap-3">
          <span class="rounded-lg bg-surface-2 p-2"><AppIcon :name="s.type === 'file_upload' ? 'file' : s.type === 'rest_api' ? 'key' : 'database'" :size="18" /></span>
          <span class="flex-1 min-w-0">
            <span class="font-semibold block">{{ s.name }}</span>
            <span class="text-xs text-fg-muted">{{ t(`sources.types.${s.type}`) }}<template v-if="s.connector_key"> · {{ s.connector_key }}</template> · {{ t('sources.lastSync', { date: formatDateTime(s.last_synced_at, tenant.timezone) }) }}</span>
            <span v-if="s.last_error" class="block text-xs text-danger">{{ s.last_error }}</span>
          </span>
          <StatusBadge domain="source" :value="s.status" />
          <template v-if="canManage">
            <button v-if="s.type === 'rest_api'" type="button" class="btn btn-ghost btn-sm" @click="createToken(s)"><AppIcon name="key" :size="15" /> {{ t('sources.createToken') }}</button>
            <button type="button" class="btn btn-ghost btn-sm" :aria-label="t('common.delete')" @click="toDelete = s"><AppIcon name="trash" :size="15" /></button>
          </template>
        </li>
      </ul>
      <EmptyState v-else-if="!sources.loading.value" icon="database" :title="t('sources.emptyTitle')" :description="t('sources.emptyText')" compact />
    </section>

    <section class="card p-4 sm:p-6">
      <h2 class="section-title mb-1">{{ t('sources.connectorsTitle') }}</h2>
      <p class="text-sm text-fg-muted mb-4">{{ t('sources.connectorsText') }}</p>
      <ErrorState v-if="connectors.error.value" :error="connectors.error.value" compact @retry="connectors.execute" />
      <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <article v-for="c in connectors.data.value ?? []" :key="c.key" class="rounded-xl border border-line p-4 flex flex-col gap-2">
          <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold text-sm m-0">{{ c.name }}</h3>
            <StatusBadge domain="connector" :value="c.status" />
          </div>
          <p class="text-sm text-fg-muted m-0">{{ c.description }}</p>
          <p v-if="c.entity_types?.length" class="text-xs text-fg-muted m-0">{{ t('sources.entities', { list: c.entity_types.join(', ') }) }}</p>
        </article>
      </div>
    </section>

    <section class="card p-2 sm:p-4">
      <h2 class="section-title px-2 pt-2 mb-2">{{ t('sources.importsTitle') }}</h2>
      <ErrorState v-if="imports.error.value" :error="imports.error.value" compact @retry="imports.execute" />
      <template v-else>
        <DataTable :columns="importColumns" :rows="imports.data.value?.data ?? []" :row-key="(r) => r.id" :loading="imports.loading.value" :caption="t('sources.importsTitle')" :empty-text="t('sources.noImports')">
          <template #cell-status="{ row }"><StatusBadge domain="import" :value="row.status" /></template>
          <template #cell-open="{ row }"><RouterLink :to="{ name: 'import-detail', params: { id: String(row.id) } }" class="btn btn-ghost btn-sm">{{ t('sources.open') }}</RouterLink></template>
        </DataTable>
        <PaginationBar v-model:page="importsPage" :meta="imports.data.value?.meta ?? null" />
      </template>
    </section>

    <AppModal :open="showCreate" :title="t('sources.add')" @close="showCreate = false">
      <form id="ds-form" class="flex flex-col gap-4" @submit.prevent="create">
        <div>
          <label class="label" for="ds-type">{{ t('sources.type') }}</label>
          <select id="ds-type" v-model="form.type" class="select">
            <option value="rest_api">{{ t('sources.types.rest_api') }}</option>
            <option value="file_upload">{{ t('sources.types.file_upload') }}</option>
            <option value="connector">{{ t('sources.types.connector') }}</option>
          </select>
          <p class="hint mt-1">{{ t(`sources.typeHints.${form.type}`) }}</p>
        </div>
        <div>
          <label class="label" for="ds-name">{{ t('sources.name') }}</label>
          <input id="ds-name" v-model="form.name" class="input" required maxlength="150" :placeholder="t('sources.namePlaceholder')" />
        </div>
        <div v-if="form.type === 'connector'">
          <label class="label" for="ds-conn">{{ t('sources.connector') }}</label>
          <select id="ds-conn" v-model="form.connector_key" class="select" required>
            <option :value="null" disabled>{{ t('common.select') }}</option>
            <option v-for="c in connectorOptions" :key="c.key" :value="c.key">{{ c.name }}</option>
          </select>
          <p class="hint mt-1">{{ t('sources.connectorHint') }}</p>
        </div>
        <p v-if="createError" class="field-error" role="alert">{{ createError }}</p>
      </form>
      <template #footer>
        <button type="button" class="btn btn-secondary" @click="showCreate = false">{{ t('common.cancel') }}</button>
        <button type="submit" form="ds-form" class="btn btn-primary" :disabled="creating || !form.name.trim() || (form.type === 'connector' && !form.connector_key)">{{ t('common.save') }}</button>
      </template>
    </AppModal>

    <AppModal :open="token !== null" :title="t('sources.tokenTitle')" :dismissible="true" @close="closeToken">
      <InlineNotice kind="warning" class="mb-3">{{ t('sources.tokenOnce') }}</InlineNotice>
      <label class="label" for="ds-token">{{ t('sources.tokenFor', { name: tokenFor?.name ?? '' }) }}</label>
      <div class="flex gap-2">
        <input id="ds-token" :value="token ?? ''" class="input font-mono text-xs" readonly @focus="($event.target as HTMLInputElement).select()" />
        <button type="button" class="btn btn-secondary" @click="copyToken"><AppIcon :name="copied ? 'check' : 'copy'" :size="16" /> {{ copied ? t('sources.copied') : t('sources.copy') }}</button>
      </div>
      <p class="text-sm text-fg-muted mt-4">{{ t('sources.tokenUsage') }}</p>
      <pre class="mt-2 rounded-xl bg-surface-2 p-3 text-xs overflow-x-auto"><code>POST /api/v1/sales/batch
Authorization: Bearer &lt;token&gt;
Content-Type: application/json

{"documents":[{"document_number":"001-001-000000123","issued_at":"2026-10-27","branch_code":"001",
  "items":[{"product_sku":"CEM-50","quantity":10,"unit_price":8.5}]}]}</code></pre>
      <template #footer>
        <button type="button" class="btn btn-primary" @click="closeToken">{{ t('sources.tokenSaved') }}</button>
      </template>
    </AppModal>

    <ConfirmDialog :open="toDelete !== null" :title="t('sources.deleteTitle')" :message="t('sources.deleteText', { name: toDelete?.name ?? '' })" danger :confirm-label="t('common.delete')" @confirm="remove" @cancel="toDelete = null" />
  </div>
</template>
