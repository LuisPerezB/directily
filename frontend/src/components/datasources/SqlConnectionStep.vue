<script setup lang="ts">
/** Paso 1: configuración de la conexión y prueba. La prueba abre una transacción de solo lectura y muestra advertencias. */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import SqlSourceForm from './SqlSourceForm.vue'
import { dataSourcesApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { ConnectionTestResult, Connector, DataSource } from '@/api/types'
import { useUiStore } from '@/stores/ui'
import { buildSqlPayload, defaultPort, emptySqlForm, sqlFieldErrors, type SqlFormState } from '@/utils/sqlSource'

const props = defineProps<{ source: DataSource; connector: Connector | null }>()
const emit = defineEmits<{ saved: [] }>()
const { t, te } = useI18n()
const ui = useUiStore()

const form = ref<SqlFormState>(emptySqlForm(props.source.connector_key ?? 'sql_postgresql'))
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const testing = ref(false)
const test = ref<ConnectionTestResult | null>(null)
const testError = ref<string | null>(null)

watch(
  () => props.source,
  (s) => {
    const c = s.config as Record<string, unknown>
    form.value = {
      ...emptySqlForm(s.connector_key ?? 'sql_postgresql'),
      name: s.name,
      host: String(c.host ?? ''),
      port: typeof c.port === 'number' ? c.port : defaultPort(s.connector_key),
      database: String(c.database ?? ''),
      sslmode: String(c.sslmode ?? 'prefer'),
      source_timezone: String(c.source_timezone ?? 'America/Guayaquil'),
      sync_schedule: (['manual', 'hourly', 'daily'].includes(String(c.sync_schedule)) ? c.sync_schedule : 'manual') as SqlFormState['sync_schedule'],
    }
  },
  { immediate: true },
)

const untested = computed(() => props.connector?.verification === 'implemented_untested' || props.connector?.status === 'implemented_untested')

async function save(): Promise<void> {
  saving.value = true
  errors.value = {}
  try {
    await dataSourcesApi.update(props.source.id, buildSqlPayload(form.value, 'update'))
    form.value.username = ''
    form.value.password = ''
    ui.notify(t('sql.connection.saved'), 'success')
    emit('saved')
  } catch (e) {
    errors.value = sqlFieldErrors(e)
    if (!Object.keys(errors.value).length) ui.notify(errorMessage(e, t), 'error')
  } finally {
    saving.value = false
  }
}

async function runTest(): Promise<void> {
  testing.value = true
  test.value = null
  testError.value = null
  try {
    test.value = await dataSourcesApi.test(props.source.id)
  } catch (e) {
    testError.value = errorMessage(e, t)
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('sql.steps.connection')" data-test="sql-connection">
    <InlineNotice v-if="untested" kind="warning" :title="t('sql.untestedTitle')">{{ t('sql.untested') }}</InlineNotice>
    <InlineNotice kind="info">{{ t('sql.readOnlyPrinciple') }}</InlineNotice>
    <form class="flex flex-col gap-4" @submit.prevent="save">
      <SqlSourceForm v-model="form" :errors="errors" editing :has-credentials="source.has_credentials" />
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary" :disabled="saving">{{ t('common.save') }}</button>
        <button type="button" class="btn btn-secondary" :disabled="testing" data-test="test-connection" @click="runTest">{{ testing ? t('common.processing') : t('sql.connection.test') }}</button>
      </div>
    </form>
    <p v-if="testError" class="field-error" role="alert">{{ testError }}</p>
    <div v-if="test" class="rounded-xl border p-4 flex flex-col gap-2" :class="test.ok ? 'border-success' : 'border-danger'" role="status" data-test="test-result">
      <p class="m-0 font-semibold" :class="test.ok ? 'text-success' : 'text-danger'">{{ test.ok ? t('sql.connection.ok') : t('sql.connection.failed') }}</p>
      <p class="m-0 text-sm">{{ test.message }}</p>
      <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm m-0">
        <template v-if="test.details.server_version"><dt class="text-fg-muted">{{ t('sql.connection.serverVersion') }}</dt><dd class="m-0">{{ test.details.server_version }}</dd></template>
        <template v-if="test.details.read_only_enforcement"><dt class="text-fg-muted">{{ t('sql.connection.readOnly') }}</dt><dd class="m-0">{{ te(`sql.readOnlyModes.${test.details.read_only_enforcement}`) ? t(`sql.readOnlyModes.${test.details.read_only_enforcement}`) : test.details.read_only_enforcement }}</dd></template>
        <template v-if="test.details.verification"><dt class="text-fg-muted">{{ t('sql.connection.verification') }}</dt><dd class="m-0"><StatusBadge domain="verification" :value="String(test.details.verification)" /></dd></template>
      </dl>
      <InlineNotice v-if="test.details.warnings?.length" kind="warning" :title="t('sql.connection.warnings')" data-test="test-warnings">
        <ul class="list-disc pl-5 m-0"><li v-for="(w, i) in test.details.warnings" :key="i">{{ w }}</li></ul>
      </InlineNotice>
    </div>
  </section>
</template>
