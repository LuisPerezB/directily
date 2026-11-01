<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import TabsNav from '@/components/ui/TabsNav.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import PlanTab from '@/components/supply/PlanTab.vue'
import SupplyConfig from '@/components/supply/SupplyConfig.vue'
import CostForecastTab from '@/components/supply/CostForecastTab.vue'
import ScenarioEvalTab from '@/components/supply/ScenarioEvalTab.vue'
import CausalTab from '@/components/supply/CausalTab.vue'
import SimulationTab from '@/components/supply/SimulationTab.vue'

/** Plan de compras: sugerencias de reposición con restricciones, costos, escenarios, verificación causal y simulación histórica. */
const props = defineProps<{ tab?: string }>()
const { t } = useI18n()
const router = useRouter()
const TABS = ['plan', 'configuracion', 'costos', 'escenarios', 'causalidad', 'simulacion'] as const
const tabs = computed(() => TABS.map((key) => ({ key, label: t(`supply.tabs.${key}`) })))
const active = computed({
  get: () => (TABS.includes(props.tab as (typeof TABS)[number]) ? props.tab! : 'plan'),
  set: (key: string) => void router.replace({ name: 'supply', params: { tab: key } }),
})
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <h1 class="page-title">{{ t('supply.title') }}</h1>
      <p class="text-fg-muted mt-1">{{ t('supply.subtitle') }}</p>
    </header>
    <InlineNotice kind="info">{{ t('supply.principle') }}</InlineNotice>
    <TabsNav v-model="active" :tabs="tabs" :label="t('supply.title')" id-prefix="supply" />
    <section id="supply-panel" role="tabpanel" :aria-labelledby="`supply-${active}`" class="card p-4 sm:p-6">
      <PlanTab v-if="active === 'plan'" />
      <SupplyConfig v-else-if="active === 'configuracion'" />
      <CostForecastTab v-else-if="active === 'costos'" />
      <ScenarioEvalTab v-else-if="active === 'escenarios'" />
      <CausalTab v-else-if="active === 'causalidad'" />
      <SimulationTab v-else />
    </section>
  </div>
</template>
