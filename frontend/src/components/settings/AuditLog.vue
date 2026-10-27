<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import { auditApi } from '@/api'
import type { AuditLogEntry } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatDateTime } from '@/utils/format'

const { t } = useI18n()
const tenant = useTenantStore()
const page = ref(1)
const logs = useAsync(() => auditApi.list({ page: page.value }), { watch: [page] })
const columns = computed<Column<AuditLogEntry>[]>(() => [
  { key: 'created_at', label: t('common.date'), format: (r) => formatDateTime(r.created_at, tenant.timezone) },
  { key: 'action', label: t('audit.action') },
  { key: 'user', label: t('sources.user'), format: (r) => r.user?.name ?? t('audit.system') },
  { key: 'auditable_type', label: t('audit.object'), format: (r) => (r.auditable_type ? `${r.auditable_type} #${r.auditable_id ?? ''}` : '—') },
  { key: 'ip_address', label: t('audit.ip') },
])
</script>

<template>
  <div class="flex flex-col gap-3">
    <p class="text-sm text-fg-muted m-0">{{ t('audit.intro') }}</p>
    <ErrorState v-if="logs.error.value" :error="logs.error.value" compact @retry="logs.execute" />
    <template v-else>
      <DataTable :columns="columns" :rows="logs.data.value?.data ?? []" :row-key="(r) => r.id" :loading="logs.loading.value" :caption="t('settings.tabs.auditoria')">
        <template #cell-action="{ row }"><code class="text-xs">{{ row.action }}</code></template>
      </DataTable>
      <PaginationBar v-model:page="page" :meta="logs.data.value?.meta ?? null" />
    </template>
  </div>
</template>
