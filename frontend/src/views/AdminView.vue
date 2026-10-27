<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AuthShell from '@/layouts/AuthShell.vue'
import DataTable, { type Column } from '@/components/ui/DataTable.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import { adminApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { AdminTenant } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/utils/format'

/** Administración de la plataforma (superadmin): empresas y planes. Sin acceso a datos de negocio. */
const { t } = useI18n()
const ui = useUiStore()
const page = ref(1)
const search = ref('')
const tenants = useAsync(() => adminApi.tenants({ page: page.value, search: search.value || undefined }), { watch: [page, search], debounce: 250 })
const plans = useAsync(() => adminApi.plans())

async function update(tn: AdminTenant, payload: { plan_code?: string; status?: string }): Promise<void> {
  try {
    await adminApi.updateTenant(tn.uuid, payload)
    await tenants.execute()
    ui.notify(t('settings.saved'), 'success')
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}

const columns = computed<Column<AdminTenant>[]>(() => [
  { key: 'name', label: t('tenant.name') },
  { key: 'sector', label: t('tenant.sector'), format: (r) => t(`sectors.${r.sector}`) },
  { key: 'plan', label: t('plan.current') },
  { key: 'status', label: t('common.status') },
  { key: 'users_count', label: t('admin.users'), align: 'right' },
  { key: 'created_at', label: t('admin.created'), format: (r) => formatDate(r.created_at?.slice(0, 10)) },
])
</script>

<template>
  <AuthShell :title="t('admin.title')" :subtitle="t('admin.subtitle')" wide>
    <InlineNotice kind="info" class="mb-4">{{ t('admin.privacy') }}</InlineNotice>
    <label class="label" for="adm-search">{{ t('filters.search') }}</label>
    <input id="adm-search" v-model.trim="search" type="search" class="input mb-4" />
    <ErrorState v-if="tenants.error.value" :error="tenants.error.value" compact @retry="tenants.execute" />
    <template v-else>
      <DataTable :columns="columns" :rows="tenants.data.value?.data ?? []" :row-key="(r) => r.uuid" :loading="tenants.loading.value" :caption="t('admin.title')">
        <template #cell-plan="{ row }">
          <label class="sr-only" :for="`plan-${row.uuid}`">{{ t('plan.current') }}</label>
          <select :id="`plan-${row.uuid}`" class="select !w-auto !min-h-8 !py-1 text-sm" :value="row.plan?.code ?? ''" @change="update(row, { plan_code: ($event.target as HTMLSelectElement).value })">
            <option v-for="p in plans.data.value ?? []" :key="p.code" :value="p.code">{{ p.name }}</option>
          </select>
        </template>
        <template #cell-status="{ row }">
          <label class="sr-only" :for="`status-${row.uuid}`">{{ t('common.status') }}</label>
          <select :id="`status-${row.uuid}`" class="select !w-auto !min-h-8 !py-1 text-sm" :value="row.status" @change="update(row, { status: ($event.target as HTMLSelectElement).value })">
            <option value="active">{{ t('admin.statuses.active') }}</option>
            <option value="suspended">{{ t('admin.statuses.suspended') }}</option>
            <option value="pending_deletion" disabled>{{ t('admin.statuses.pending_deletion') }}</option>
          </select>
        </template>
      </DataTable>
      <PaginationBar v-model:page="page" :meta="tenants.data.value?.meta ?? null" />
    </template>
    <template #below><RouterLink :to="{ name: 'dashboard' }" class="font-semibold text-brand-strong">{{ t('admin.back') }}</RouterLink></template>
  </AuthShell>
</template>
