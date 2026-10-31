<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import FormField from '@/components/ui/FormField.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import { errorMessage } from '@/api/errors'
import type { ForecastSchedule, Horizon } from '@/api/types'
import { useTenantStore } from '@/stores/tenant'
import { useUiStore } from '@/stores/ui'
import { HORIZONS } from '@/utils/constants'
import { allowedHorizons } from '@/utils/horizons'

/** Parámetros de inventario y pronóstico (tenants.settings). Valores como % en el formulario. */
const { t } = useI18n()
const tenant = useTenantStore()
const ui = useUiStore()
const canEdit = computed(() => tenant.can('settings.manage'))
const form = reactive({ service_level: 95, review_period_days: 7, default_lead_time_days: 7, forecast_schedule: 'weekly' as ForecastSchedule, default_horizon_days: 30 as Horizon, stockout_risk_threshold: 30 })
const saving = ref(false)
// Horizontes del plan; el valor ya guardado se conserva visible aunque el plan cambie.
const horizonChoices = computed(() => {
  const list = allowedHorizons(HORIZONS, tenant.tenant?.plan?.limits)
  return list.includes(form.default_horizon_days) ? list : [...list, form.default_horizon_days].sort((a, b) => a - b)
})

watch(
  () => tenant.tenant?.settings,
  (s) => {
    if (!s) return
    form.service_level = Math.round((s.service_level ?? 0.95) * 1000) / 10
    form.review_period_days = s.review_period_days ?? 7
    form.default_lead_time_days = s.default_lead_time_days ?? 7
    form.forecast_schedule = s.forecast_schedule ?? 'weekly'
    form.default_horizon_days = (s.default_horizon_days ?? 30) as Horizon
    form.stockout_risk_threshold = Math.round((s.stockout_risk_threshold ?? 0.3) * 100)
  },
  { immediate: true },
)

const valid = computed(() => form.service_level >= 50 && form.service_level <= 99.9 && form.review_period_days >= 1 && form.default_lead_time_days >= 1 && form.stockout_risk_threshold >= 1 && form.stockout_risk_threshold <= 99)

async function save(): Promise<void> {
  saving.value = true
  try {
    await tenant.update({
      settings: {
        service_level: Math.round(form.service_level * 10) / 1000,
        review_period_days: form.review_period_days,
        default_lead_time_days: form.default_lead_time_days,
        forecast_schedule: form.forecast_schedule,
        default_horizon_days: form.default_horizon_days,
        stockout_risk_threshold: form.stockout_risk_threshold / 100,
      },
    })
    ui.notify(t('settings.saved'), 'success')
  } catch (e) {
    ui.notify(errorMessage(e, t), 'error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form class="flex flex-col gap-5 max-w-3xl" @submit.prevent="save">
    <InlineNotice kind="info">{{ t('settings.paramsIntro') }}</InlineNotice>
    <fieldset class="grid gap-4 md:grid-cols-2 border-0 p-0 m-0" :disabled="!canEdit">
      <FormField id="p-sl" :label="t('settings.serviceLevel')" :hint="t('settings.serviceLevelHint')">
        <template #default="{ describedby }"><input id="p-sl" v-model.number="form.service_level" type="number" min="50" max="99.9" step="0.5" class="input" :aria-describedby="describedby" /></template>
      </FormField>
      <FormField id="p-rp" :label="t('settings.reviewPeriod')" :hint="t('settings.reviewPeriodHint')">
        <template #default="{ describedby }"><input id="p-rp" v-model.number="form.review_period_days" type="number" min="1" max="90" class="input" :aria-describedby="describedby" /></template>
      </FormField>
      <FormField id="p-lt" :label="t('settings.leadTime')" :hint="t('settings.leadTimeHint')">
        <template #default="{ describedby }"><input id="p-lt" v-model.number="form.default_lead_time_days" type="number" min="1" max="180" class="input" :aria-describedby="describedby" /></template>
      </FormField>
      <FormField id="p-th" :label="t('settings.riskThreshold')" :hint="t('settings.riskThresholdHint')">
        <template #default="{ describedby }"><input id="p-th" v-model.number="form.stockout_risk_threshold" type="number" min="1" max="99" class="input" :aria-describedby="describedby" /></template>
      </FormField>
      <FormField id="p-sch" :label="t('settings.schedule')" :hint="t('settings.scheduleHint')">
        <template #default="{ describedby }">
          <select id="p-sch" v-model="form.forecast_schedule" class="select" :aria-describedby="describedby">
            <option value="daily">{{ t('settings.schedules.daily') }}</option>
            <option value="weekly">{{ t('settings.schedules.weekly') }}</option>
            <option value="manual">{{ t('settings.schedules.manual') }}</option>
          </select>
        </template>
      </FormField>
      <FormField id="p-hz" :label="t('settings.defaultHorizon')">
        <template #default>
          <select id="p-hz" v-model.number="form.default_horizon_days" class="select">
            <option v-for="h in horizonChoices" :key="h" :value="h">{{ t('common.days', { n: h }) }}</option>
          </select>
        </template>
      </FormField>
    </fieldset>
    <div v-if="canEdit"><button type="submit" class="btn btn-primary" :disabled="saving || !valid">{{ t('common.save') }}</button></div>
    <p v-else class="hint">{{ t('settings.readOnly') }}</p>
  </form>
</template>
