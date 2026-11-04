<script setup lang="ts">
/**
 * Uso por empresa y costos: consumo contra los límites del plan (GET /tenant/usage) y uso de la plataforma registrado (`tenant_usage_records`, vía GET /decisions/roi).
 * El uso son CANTIDADES; solo se convierte en dinero con precios que la empresa configure. Sin precios: «costo no estimado» (nunca cero).
 */
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import PlanUsage from '@/components/settings/PlanUsage.vue'
import PlatformUsageTable from '@/components/outcomes/PlatformUsageTable.vue'
import ProvenanceLine from '@/components/enterprise/ProvenanceLine.vue'
import { outcomeApi } from '@/api'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'

const { t } = useI18n()
const tenant = useTenantStore()
const report = useAsync(() => outcomeApi.roi({}), { scope: () => tenant.currentUuid })
</script>

<template>
  <section class="flex flex-col gap-6" :aria-label="t('entAdmin.usage.title')" data-test="usage-costs">
    <div>
      <h3 class="section-title m-0 mb-2">{{ t('entAdmin.usage.plan') }}</h3>
      <PlanUsage />
    </div>
    <div class="flex flex-col gap-2">
      <h3 class="section-title m-0">{{ t('entAdmin.usage.platform') }}</h3>
      <p class="m-0 text-sm text-fg-muted">{{ t('entAdmin.usage.platformText') }}</p>
      <LoadingState v-if="report.loading.value && !report.data.value" :lines="2" />
      <ErrorState v-else-if="report.error.value" :error="report.error.value" compact @retry="report.execute" />
      <template v-else-if="report.data.value">
        <ProvenanceLine :as-of="report.data.value.today" :unit="t('entAdmin.usage.unit')" :currency="report.data.value.currency" />
        <PlatformUsageTable :usage="report.data.value.costs.platform_usage" :currency="report.data.value.currency" />
      </template>
    </div>
  </section>
</template>
