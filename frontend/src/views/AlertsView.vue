<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import { alertsApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { Alert, AlertStatus } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { formatDateTime } from '@/utils/format'

const { t, te } = useI18n()
const tenant = useTenantStore()
const ui = useUiStore()
const status = ref<AlertStatus | 'all'>('open')
const page = ref(1)
watch(status, () => (page.value = 1))
const alerts = useAsync(() => alertsApi.list({ status: status.value === 'all' ? undefined : status.value, page: page.value, per_page: 20 }), { watch: [status, page] })
const busy = ref<number | null>(null)

async function update(alert: Alert, next: 'acknowledged' | 'resolved'): Promise<void> {
  busy.value = alert.id
  try {
    await alertsApi.update(alert.id, next)
    await alerts.execute()
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    busy.value = null
  }
}

function typeLabel(type: string): string {
  return te(`alerts.types.${type}`) ? t(`alerts.types.${type}`) : type
}
</script>

<template>
  <div class="flex flex-col gap-6 max-w-5xl">
    <header class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
      <div>
        <h1 class="page-title">{{ t('alerts.title') }}</h1>
        <p class="text-fg-muted mt-1">{{ t('alerts.subtitle') }}</p>
      </div>
      <SegmentedControl
        v-model="status"
        :options="[{ value: 'open', label: t('status.alert.open') }, { value: 'acknowledged', label: t('status.alert.acknowledged') }, { value: 'resolved', label: t('status.alert.resolved') }, { value: 'all', label: t('filters.all') }]"
        :label="t('common.status')"
      />
    </header>

    <section class="card p-4 sm:p-6">
      <LoadingState v-if="alerts.loading.value && !alerts.data.value" />
      <ErrorState v-else-if="alerts.error.value" :error="alerts.error.value" @retry="alerts.execute" />
      <EmptyState v-else-if="!alerts.data.value?.data.length" icon="bell" :title="t('alerts.emptyTitle')" :description="t('alerts.emptyText')" />
      <template v-else>
        <ul class="flex flex-col divide-y divide-line list-none p-0 m-0">
          <li v-for="a in alerts.data.value.data" :key="a.id" class="py-4 flex flex-col sm:flex-row sm:items-start gap-3">
            <div class="flex items-center gap-2 shrink-0">
              <StatusBadge domain="severity" :value="a.severity" />
              <span class="badge">{{ typeLabel(a.type) }}</span>
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-semibold m-0">{{ a.title }}</p>
              <p class="text-sm text-fg-muted m-0">{{ a.message }}</p>
              <p class="text-xs text-fg-muted mt-1 mb-0">{{ formatDateTime(a.created_at, tenant.timezone) }} · <StatusBadge domain="alert" :value="a.status" /></p>
            </div>
            <div v-if="tenant.can('alerts.manage') && a.status !== 'resolved'" class="flex gap-1 shrink-0">
              <button v-if="a.status === 'open'" type="button" class="btn btn-ghost btn-sm" :disabled="busy === a.id" @click="update(a, 'acknowledged')">{{ t('alerts.acknowledge') }}</button>
              <button type="button" class="btn btn-secondary btn-sm" :disabled="busy === a.id" @click="update(a, 'resolved')">{{ t('alerts.resolve') }}</button>
            </div>
          </li>
        </ul>
        <PaginationBar v-model:page="page" :meta="alerts.data.value.meta" />
      </template>
    </section>
  </div>
</template>
