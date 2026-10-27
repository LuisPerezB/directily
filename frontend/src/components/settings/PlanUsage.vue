<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import ProgressBar from '@/components/ui/ProgressBar.vue'
import { tenantApi } from '@/api'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatInteger } from '@/utils/format'

const { t } = useI18n()
const tenant = useTenantStore()
const usage = useAsync(() => tenantApi.usage())

const rows = computed(() => {
  const u = usage.data.value
  if (!u) return []
  const l = u.limits ?? {}
  return [
    { key: 'branches', used: u.metrics.branches, max: l.max_branches ?? null },
    { key: 'active_products', used: u.metrics.active_products, max: l.max_active_products ?? null },
    { key: 'users', used: u.metrics.users, max: l.max_users ?? null },
    { key: 'import_rows', used: u.metrics.import_rows, max: l.max_import_rows_per_month ?? null },
    { key: 'forecast_runs', used: u.metrics.forecast_runs, max: null },
  ]
})
const features = computed(() => Object.entries(usage.data.value?.limits?.features ?? {}))
</script>

<template>
  <div class="flex flex-col gap-5 max-w-3xl">
    <div class="rounded-xl bg-surface-2 p-4 flex flex-wrap items-center justify-between gap-2">
      <div>
        <p class="text-sm text-fg-muted m-0">{{ t('plan.current') }}</p>
        <p class="text-lg font-bold m-0">{{ tenant.tenant?.plan?.name ?? '—' }}</p>
      </div>
      <p class="hint m-0 max-w-sm">{{ t('plan.manual') }}</p>
    </div>
    <LoadingState v-if="usage.loading.value && !usage.data.value" />
    <ErrorState v-else-if="usage.error.value" :error="usage.error.value" compact @retry="usage.execute" />
    <template v-else-if="usage.data.value">
      <p class="text-sm text-fg-muted m-0">{{ t('plan.period', { period: usage.data.value.period }) }}</p>
      <ul class="flex flex-col gap-4 list-none p-0 m-0">
        <li v-for="r in rows" :key="r.key">
          <div class="flex justify-between text-sm mb-1">
            <span class="font-medium">{{ t(`plan.metrics.${r.key}`) }}</span>
            <span class="tabular">{{ formatInteger(r.used) }}<template v-if="r.max"> / {{ formatInteger(r.max) }}</template><template v-else> · {{ t('plan.noLimit') }}</template></span>
          </div>
          <ProgressBar v-if="r.max" :value="(r.used / r.max) * 100" :label="t(`plan.metrics.${r.key}`)" />
        </li>
      </ul>
      <div v-if="usage.data.value.limits?.horizons?.length" class="text-sm">
        <span class="font-medium">{{ t('plan.horizons') }}:</span> {{ usage.data.value.limits.horizons.map((h) => t('common.days', { n: h })).join(', ') }}
      </div>
      <div v-if="features.length" class="flex flex-wrap gap-2">
        <span v-for="[k, enabled] in features" :key="k" class="badge" :class="enabled ? 'badge-success' : ''">{{ t(`plan.features.${k}`) }}: {{ enabled ? t('common.yes') : t('common.no') }}</span>
      </div>
    </template>
  </div>
</template>
