<script setup lang="ts">
/**
 * Paso 2: consulta autorizada por entidad. Cada cambio crea una versión NUEVA sin aprobar; solo la versión aprobada y
 * activa sincroniza. La vista previa muestra los campos canónicos sin importar nada. El servidor valida que la consulta
 * sea un único SELECT de solo lectura (422 sql_query_rejected con el motivo).
 */
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import { importsApi, sqlSourcesApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { ImportField, ImportEntityType, MappingPreview, SourceMapping, SqlEntity } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/utils/format'
import { buildFieldMap, fieldMapToRows, missingRequiredFields, parseOptionsJson, SQL_ENTITIES, sqlRejection, type FieldMapEntry } from '@/utils/sqlSource'
import { useTenantStore } from '@/stores/tenant'

const props = defineProps<{ sourceId: number }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const ui = useUiStore()
const tenant = useTenantStore()

const entity = ref<SqlEntity>('sales')
const mappings = useAsync(() => sqlSourcesApi.mappings(props.sourceId))
const fields = ref<ImportField[]>([])
const fieldsError = ref<unknown>(null)
const query = ref('')
const rows = reactive<Record<string, FieldMapEntry>>({})
const optionsText = ref('')
const saving = ref(false)
const formError = ref<string | null>(null)
const rejected = ref<string | null>(null)
const preview = ref<{ mapping: SourceMapping; data: MappingPreview } | null>(null)
const previewError = ref<string | null>(null)
const toApprove = ref<SourceMapping | null>(null)
const busy = ref(false)

const versions = computed(() => (mappings.data.value ?? []).filter((m) => m.entity === entity.value).sort((a, b) => b.version - a.version))
const missing = computed(() => missingRequiredFields(fields.value, rows))

async function loadFields(): Promise<void> {
  fieldsError.value = null
  try {
    fields.value = await importsApi.fields(entity.value as ImportEntityType)
  } catch (e) {
    fields.value = []
    fieldsError.value = e
  }
  for (const k of Object.keys(rows)) delete rows[k]
  for (const f of fields.value) rows[f.key] = { column: '', fixed: false }
}
watch(entity, () => { void loadFields(); preview.value = null }, { immediate: true })

/** Carga una versión existente en el editor (para ajustarla y guardarla como versión nueva). */
function edit(m: SourceMapping): void {
  query.value = m.query
  const loaded = fieldMapToRows(m.field_map)
  for (const k of Object.keys(rows)) rows[k] = loaded[k] ?? { column: '', fixed: false }
  optionsText.value = Object.keys(m.options ?? {}).length ? JSON.stringify(m.options, null, 2) : ''
  formError.value = null
  rejected.value = null
}

async function save(): Promise<void> {
  formError.value = null
  rejected.value = null
  const opts = parseOptionsJson(optionsText.value)
  if (!opts.ok) {
    formError.value = t('sql.query.optionsInvalid')
    return
  }
  saving.value = true
  try {
    const created = await sqlSourcesApi.createMapping(props.sourceId, { entity: entity.value, query: query.value, field_map: buildFieldMap(rows), options: opts.value })
    ui.notify(t('sql.query.savedVersion', { n: created.version }), 'success')
    await mappings.execute()
    emit('changed')
  } catch (e) {
    const rej = sqlRejection(e)
    if (rej) rejected.value = rej.reason
    else formError.value = errorMessage(e, t)
  } finally {
    saving.value = false
  }
}

async function showPreview(m: SourceMapping): Promise<void> {
  previewError.value = null
  preview.value = null
  busy.value = true
  try {
    preview.value = { mapping: m, data: await sqlSourcesApi.previewMapping(props.sourceId, m.id) }
  } catch (e) {
    previewError.value = isApiError(e) && e.code === 'source_unavailable' ? t('sql.query.sourceUnavailable', { reason: e.message }) : errorMessage(e, t)
  } finally {
    busy.value = false
  }
}

async function approve(): Promise<void> {
  if (!toApprove.value) return
  busy.value = true
  try {
    await sqlSourcesApi.approveMapping(props.sourceId, toApprove.value.id)
    ui.notify(t('sql.query.approved'), 'success')
    await mappings.execute()
    emit('changed')
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    busy.value = false
    toApprove.value = null
  }
}

async function deactivate(m: SourceMapping): Promise<void> {
  try {
    await sqlSourcesApi.deactivateMapping(props.sourceId, m.id)
    await mappings.execute()
    emit('changed')
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}

function versionState(m: SourceMapping): 'active' | 'pending' | 'inactive' {
  if (m.is_active && m.approved_at) return 'active'
  return m.approved_at ? 'inactive' : 'pending'
}
</script>

<template>
  <section class="flex flex-col gap-5" :aria-label="t('sql.steps.queries')" data-test="sql-queries">
    <InlineNotice kind="info">{{ t('sql.query.principle') }}</InlineNotice>
    <div>
      <label class="label" for="sq-entity">{{ t('sql.query.entity') }}</label>
      <select id="sq-entity" v-model="entity" class="select !w-auto min-w-[14rem]">
        <option v-for="e in SQL_ENTITIES" :key="e" :value="e">{{ t(`sql.entities.${e}`) }}</option>
      </select>
    </div>

    <form class="flex flex-col gap-4" @submit.prevent="save">
      <div>
        <label class="label" for="sq-query">{{ t('sql.query.query') }}</label>
        <textarea id="sq-query" v-model="query" rows="8" class="textarea font-mono text-xs" spellcheck="false" required :aria-describedby="rejected ? 'sq-query-error' : 'sq-query-hint'" :aria-invalid="rejected ? true : undefined" data-test="query-editor" />
        <p id="sq-query-hint" class="hint mt-1">{{ t('sql.query.queryHint') }}</p>
        <p v-if="rejected" id="sq-query-error" class="field-error" role="alert" data-test="query-rejected">{{ t('sql.query.rejected', { reason: rejected }) }}</p>
      </div>

      <fieldset class="border-0 p-0 m-0">
        <legend class="section-title mb-1">{{ t('sql.query.fieldMap') }}</legend>
        <p class="hint mb-2">{{ t('sql.query.fieldMapHint') }}</p>
        <ErrorState v-if="fieldsError" :error="fieldsError" compact @retry="loadFields" />
        <div class="grid gap-3 sm:grid-cols-2">
          <div v-for="f in fields" :key="f.key" class="flex flex-col gap-1">
            <label class="label !mb-0" :for="`sq-f-${f.key}`">{{ f.label }}<span v-if="f.required" class="text-danger" aria-hidden="true"> *</span> <span class="text-xs text-fg-muted font-mono">{{ f.key }}</span></label>
            <div class="flex gap-2 items-center">
              <input :id="`sq-f-${f.key}`" v-model="rows[f.key]!.column" class="input font-mono text-xs" :placeholder="rows[f.key]?.fixed ? t('sql.query.fixedValue') : t('sql.query.sourceColumn')" />
              <label class="flex items-center gap-1 text-xs whitespace-nowrap"><input v-model="rows[f.key]!.fixed" type="checkbox" /> {{ t('sql.query.fixed') }}</label>
            </div>
          </div>
        </div>
        <p v-if="missing.length" class="field-error mt-2" role="alert" data-test="missing-fields">{{ t('sql.query.missingFields', { list: missing.join(', ') }) }}</p>
      </fieldset>

      <details>
        <summary class="cursor-pointer text-sm text-fg-muted hover:text-fg">{{ t('sql.query.advanced') }}</summary>
        <label class="label mt-2" for="sq-options">{{ t('sql.query.options') }}</label>
        <textarea id="sq-options" v-model="optionsText" rows="5" class="textarea font-mono text-xs" spellcheck="false" :placeholder="'{ &quot;page_size&quot;: 5000, &quot;overlap_minutes&quot;: 120 }'" />
        <p class="hint mt-1">{{ t('sql.query.optionsHint', { tz: tenant.timezone }) }}</p>
      </details>

      <p v-if="formError" class="field-error" role="alert">{{ formError }}</p>
      <div><button type="submit" class="btn btn-primary" :disabled="saving || !query.trim() || missing.length > 0" data-test="save-version">{{ t('sql.query.saveVersion') }}</button></div>
    </form>

    <div>
      <h3 class="section-title mb-2">{{ t('sql.query.versions') }}</h3>
      <ErrorState v-if="mappings.error.value" :error="mappings.error.value" compact @retry="mappings.execute" />
      <p v-else-if="!versions.length" class="text-sm text-fg-muted m-0">{{ t('sql.query.noVersions') }}</p>
      <ul v-else class="list-none p-0 m-0 flex flex-col divide-y divide-line" data-test="versions">
        <li v-for="m in versions" :key="m.id" class="py-3 flex flex-wrap items-center gap-3">
          <span class="font-semibold">v{{ m.version }}</span>
          <span class="badge" :class="versionState(m) === 'active' ? 'badge-success' : versionState(m) === 'pending' ? 'badge-warning' : ''" :data-state="versionState(m)">{{ t(`sql.query.state.${versionState(m)}`) }}</span>
          <span class="text-xs text-fg-muted flex-1 min-w-[10rem]">
            {{ formatDateTime(m.created_at, tenant.timezone) }}<template v-if="m.approved_by"> · {{ t('sql.query.approvedBy', { name: m.approved_by.name }) }}</template>
          </span>
          <button type="button" class="btn btn-ghost btn-sm" @click="edit(m)">{{ t('sql.query.useAsBase') }}</button>
          <button type="button" class="btn btn-ghost btn-sm" :disabled="busy" data-test="preview" @click="showPreview(m)">{{ t('sql.query.preview') }}</button>
          <button v-if="versionState(m) !== 'active'" type="button" class="btn btn-secondary btn-sm" data-test="approve" @click="toApprove = m">{{ t('sql.query.approve') }}</button>
          <button v-else type="button" class="btn btn-ghost btn-sm" @click="deactivate(m)">{{ t('sql.query.deactivate') }}</button>
        </li>
      </ul>
    </div>

    <p v-if="previewError" class="field-error" role="alert">{{ previewError }}</p>
    <div v-if="preview" data-test="preview-result">
      <h3 class="section-title mb-1">{{ t('sql.query.previewTitle', { n: preview.mapping.version }) }}</h3>
      <p class="hint mb-2">{{ preview.data.note }}</p>
      <div class="table-wrap">
        <table class="table">
          <caption class="sr-only">{{ t('sql.query.previewTitle', { n: preview.mapping.version }) }}</caption>
          <thead><tr><th v-for="h in preview.data.headers" :key="h" scope="col">{{ h }}</th></tr></thead>
          <tbody>
            <tr v-for="(r, i) in preview.data.rows" :key="i"><td v-for="(c, j) in r" :key="j">{{ c ?? '—' }}</td></tr>
            <tr v-if="!preview.data.rows.length"><td :colspan="preview.data.headers.length || 1" class="text-center text-fg-muted">{{ t('sql.query.previewEmpty') }}</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <ConfirmDialog :open="toApprove !== null" :title="t('sql.query.approveTitle')" :message="t('sql.query.approveText', { n: toApprove?.version ?? 0 })" :busy="busy" :confirm-label="t('sql.query.approve')" @confirm="approve" @cancel="toApprove = null" />
  </section>
</template>
