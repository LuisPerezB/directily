<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import TabsNav from '@/components/ui/TabsNav.vue'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import CustomerPredictions from '@/components/customers/CustomerPredictions.vue'
import CustomerEvaluation from '@/components/customers/CustomerEvaluation.vue'
import CustomerReconciliation from '@/components/customers/CustomerReconciliation.vue'
import CustomerModels from '@/components/customers/CustomerModels.vue'
import CustomerRuns from '@/components/customers/CustomerRuns.vue'
import { useHorizonSelection } from '@/composables/useHorizons'
import { CUSTOMER_HORIZONS } from '@/utils/constants'
import type { CustomerHorizon } from '@/api/types'

const props = defineProps<{ tab?: string }>()
const { t } = useI18n()
const router = useRouter()
const { horizon, options, hasHorizons } = useHorizonSelection<CustomerHorizon>(CUSTOMER_HORIZONS, 30)

const TABS = ['predicciones', 'evaluacion', 'conciliacion', 'modelos', 'ejecuciones'] as const
const tabs = computed(() => TABS.map((key) => ({ key, label: t(`customers.tabs.${key}`) })))
const active = computed({
  get: () => (TABS.includes(props.tab as (typeof TABS)[number]) ? props.tab! : 'predicciones'),
  set: (key: string) => void router.replace({ name: 'customers', params: { tab: key } }),
})
// El horizonte solo aplica a las pestañas que consultan resultados por horizonte.
const usesHorizon = computed(() => ['predicciones', 'evaluacion', 'conciliacion'].includes(active.value))
</script>

<template>
  <div class="flex flex-col gap-6">
    <header class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
      <div>
        <h1 class="page-title">{{ t('customers.title') }}</h1>
        <p class="text-fg-muted mt-1">{{ t('customers.subtitle') }}</p>
      </div>
      <SegmentedControl v-if="usesHorizon && hasHorizons" v-model="horizon" :options="options" :label="t('common.horizon')" />
    </header>

    <InlineNotice kind="info" :title="t('customers.privacyTitle')">{{ t('customers.privacy') }}</InlineNotice>
    <InlineNotice v-if="!hasHorizons" kind="warning">{{ t('customers.noHorizonsInPlan') }}</InlineNotice>

    <TabsNav v-model="active" :tabs="tabs" :label="t('customers.title')" id-prefix="cust" />
    <section id="cust-panel" role="tabpanel" :aria-labelledby="`cust-${active}`" class="card p-4 sm:p-6">
      <CustomerPredictions v-if="active === 'predicciones'" :horizon="horizon" @update:horizon="(h) => (horizon = h)" />
      <CustomerEvaluation v-else-if="active === 'evaluacion'" :horizon="horizon" />
      <CustomerReconciliation v-else-if="active === 'conciliacion'" :horizon="horizon" />
      <CustomerModels v-else-if="active === 'modelos'" />
      <CustomerRuns v-else @finished="() => undefined" />
    </section>
  </div>
</template>
