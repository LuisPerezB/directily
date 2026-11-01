<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import TabsNav from '@/components/ui/TabsNav.vue'
import SqlConnectionStep from '@/components/datasources/SqlConnectionStep.vue'
import SqlQueryStep from '@/components/datasources/SqlQueryStep.vue'
import SqlSyncStep from '@/components/datasources/SqlSyncStep.vue'
import { dataSourcesApi } from '@/api'
import { useAsync } from '@/composables/useAsync'

/** Asistente de una fuente SQL de solo lectura: conexión → consultas autorizadas → sincronización. */
const props = defineProps<{ id: string }>()
const { t } = useI18n()
const sources = useAsync(() => dataSourcesApi.list())
const connectors = useAsync(() => dataSourcesApi.connectors())
const source = computed(() => sources.data.value?.find((s) => s.id === Number(props.id)) ?? null)
const connector = computed(() => connectors.data.value?.find((c) => c.key === source.value?.connector_key) ?? null)
const step = ref('connection')
const steps = computed(() => ['connection', 'queries', 'sync'].map((k) => ({ key: k, label: `${['connection', 'queries', 'sync'].indexOf(k) + 1}. ${t(`sql.steps.${k}`)}` })))
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <RouterLink :to="{ name: 'data-sources' }" class="text-sm font-semibold">← {{ t('sql.back') }}</RouterLink>
      <h1 class="page-title mt-1">{{ source?.name ?? t('sql.title') }}</h1>
      <p class="text-fg-muted mt-1 flex flex-wrap items-center gap-2">
        {{ t('sql.subtitle') }}
        <StatusBadge v-if="connector" domain="connector" :value="connector.status" />
        <StatusBadge v-if="connector?.verification" domain="verification" :value="connector.verification" />
      </p>
    </header>
    <LoadingState v-if="sources.loading.value && !source" :lines="3" />
    <div v-else-if="sources.error.value" class="card"><ErrorState :error="sources.error.value" @retry="sources.execute" /></div>
    <div v-else-if="!source" class="card"><EmptyState icon="database" :title="t('sql.notFoundTitle')" :description="t('sql.notFoundText')" /></div>
    <template v-else>
      <TabsNav v-model="step" :tabs="steps" :label="t('sql.title')" id-prefix="sql" />
      <section id="sql-panel" role="tabpanel" :aria-labelledby="`sql-${step}`" class="card p-4 sm:p-6">
        <SqlConnectionStep v-if="step === 'connection'" :source="source" :connector="connector" @saved="sources.execute" />
        <SqlQueryStep v-else-if="step === 'queries'" :source-id="source.id" />
        <SqlSyncStep v-else :source-id="source.id" />
      </section>
    </template>
  </div>
</template>
