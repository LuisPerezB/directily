<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import RunForecastPanel from '@/components/forecast/RunForecastPanel.vue'
import { importsApi, tenantApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { ImportJob } from '@/api/types'
import { useTenantStore } from '@/stores/tenant'
import { useCatalogStore } from '@/stores/catalog'
import { useUiStore } from '@/stores/ui'
import { usePolling } from '@/composables/usePolling'

const { t } = useI18n()
const tenant = useTenantStore()
const catalog = useCatalogStore()
const ui = useUiStore()
const router = useRouter()

const onboarding = computed(() => tenant.tenant?.onboarding ?? null)
const demoJobs = ref<ImportJob[]>([])
const demoBusy = ref(false)
const demoError = ref<string | null>(null)

const steps = computed(() => {
  const o = onboarding.value
  return [
    { key: 'branches', done: !!o?.has_branches, to: { name: 'settings', params: { tab: 'sucursales' } }, optional: false },
    { key: 'sales', done: !!o?.has_sales, to: { name: 'import-new', query: { entity: 'sales' } }, optional: false },
    { key: 'products', done: !!o?.has_products, to: { name: 'import-new', query: { entity: 'products' } }, optional: true },
    { key: 'inventory', done: !!o?.has_inventory, to: { name: 'import-new', query: { entity: 'inventory' } }, optional: true },
    { key: 'forecast', done: !!o?.has_forecast, to: null, optional: false },
  ]
})
const progress = computed(() => steps.value.filter((s) => s.done).length)

const polling = usePolling(
  () => Promise.all(demoJobs.value.map((j) => importsApi.get(j.id))),
  {
    interval: 3000,
    until: (jobs) => jobs.every((j) => ['completed', 'failed', 'rejected'].includes(j.status)),
    onUpdate: async (jobs) => {
      demoJobs.value = jobs
      if (jobs.every((j) => ['completed', 'failed', 'rejected'].includes(j.status))) {
        await refresh()
        await catalog.ensureLoaded(tenant.currentUuid, true)
        ui.notify(t('onboarding.demoLoaded'), 'success')
      }
    },
  },
)

async function refresh(): Promise<void> {
  try {
    await tenant.load()
  } catch {
    // El estado se muestra con los datos disponibles.
  }
}

async function loadDemo(): Promise<void> {
  demoBusy.value = true
  demoError.value = null
  try {
    const result = await tenantApi.loadDemoData()
    demoJobs.value = await Promise.all(result.import_job_ids.map((id) => importsApi.get(id)))
    polling.start(false)
  } catch (e) {
    demoError.value = errorMessage(e, t)
  } finally {
    demoBusy.value = false
  }
}

async function complete(): Promise<void> {
  try {
    await tenantApi.completeOnboarding()
    await refresh()
    await router.push({ name: 'dashboard' })
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  }
}

onMounted(refresh)
</script>

<template>
  <div class="flex flex-col gap-6 max-w-4xl">
    <header>
      <h1 class="page-title">{{ t('onboarding.title', { name: tenant.tenant?.name ?? '' }) }}</h1>
      <p class="text-fg-muted mt-1">{{ t('onboarding.subtitle') }}</p>
    </header>

    <section class="card p-5 sm:p-6" :aria-label="t('onboarding.checklist')">
      <div class="flex items-center justify-between gap-3 mb-4">
        <h2 class="section-title">{{ t('onboarding.checklist') }}</h2>
        <span class="badge badge-brand tabular">{{ t('onboarding.progress', { done: progress, total: steps.length }) }}</span>
      </div>
      <ol class="flex flex-col gap-3 list-none p-0 m-0">
        <li v-for="(step, i) in steps" :key="step.key" class="rounded-xl border border-line p-4 flex flex-col sm:flex-row sm:items-center gap-3">
          <span class="inline-flex w-8 h-8 shrink-0 rounded-full items-center justify-center font-bold" :class="step.done ? 'bg-success text-white' : 'bg-surface-2 text-fg-muted'">
            <AppIcon v-if="step.done" name="check" :size="16" :label="t('onboarding.done')" />
            <template v-else>{{ i + 1 }}</template>
          </span>
          <div class="flex-1 min-w-0">
            <p class="font-semibold m-0">
              {{ t(`onboarding.steps.${step.key}.title`) }}
              <span v-if="step.optional" class="badge ml-1">{{ t('common.optional') }}</span>
            </p>
            <p class="text-sm text-fg-muted m-0">{{ t(`onboarding.steps.${step.key}.text`) }}</p>
          </div>
          <RouterLink v-if="step.to && !step.done" :to="step.to" class="btn btn-secondary btn-sm">{{ t(`onboarding.steps.${step.key}.cta`) }}</RouterLink>
          <div v-else-if="step.key === 'forecast' && !step.done && onboarding?.has_sales" class="sm:max-w-sm">
            <RunForecastPanel compact @finished="refresh" />
          </div>
        </li>
      </ol>
    </section>

    <section v-if="onboarding && !onboarding.has_sales && tenant.can('settings.manage')" class="card p-5 sm:p-6">
      <h2 class="section-title">{{ t('onboarding.demoTitle') }}</h2>
      <p class="text-sm text-fg-muted mt-1">{{ t('onboarding.demoText') }}</p>
      <InlineNotice kind="warning" class="mt-3">{{ t('onboarding.demoFictitious') }}</InlineNotice>
      <button type="button" class="btn btn-secondary mt-4" :disabled="demoBusy || polling.active.value" @click="loadDemo">
        <AppIcon name="database" :size="16" /> {{ t('onboarding.demoButton') }}
      </button>
      <p v-if="demoError" class="field-error" role="alert">{{ demoError }}</p>
      <ul v-if="demoJobs.length" class="mt-4 flex flex-col gap-2 list-none p-0" aria-live="polite">
        <li v-for="job in demoJobs" :key="job.id" class="flex items-center justify-between gap-2 text-sm">
          <span>{{ t(`imports.entities.${job.entity_type}`) }} · {{ job.original_filename }}</span>
          <StatusBadge domain="import" :value="job.status" />
        </li>
      </ul>
    </section>

    <div class="flex flex-wrap gap-2">
      <button type="button" class="btn btn-primary" :disabled="!onboarding?.has_sales" @click="complete">{{ t('onboarding.finish') }}</button>
      <RouterLink :to="{ name: 'dashboard' }" class="btn btn-ghost">{{ t('onboarding.skip') }}</RouterLink>
    </div>
  </div>
</template>
