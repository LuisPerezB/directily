<script setup lang="ts">
/**
 * Verificación causal: lista los requisitos para publicar el efecto del precio sobre la demanda. Ningún estimador está
 * habilitado: el resultado es SIEMPRE «evidencia insuficiente» y no hay efecto numérico, aunque los datos cumplan los
 * requisitos. La simulación condicionada sigue disponible. Un buen error predictivo no es un efecto causal.
 */
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import ProductPicker from '@/components/forecast/ProductPicker.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import { supplyApi } from '@/api'
import { errorMessage } from '@/api/errors'
import type { CausalCheck, Product, ResultEnvelope } from '@/api/types'
import { useAbility } from '@/composables/useAbility'
import { useCatalogStore } from '@/stores/catalog'
import { causalCounts } from '@/utils/supply'

const OBSERVABLE = ['promotions', 'calendar_seasonality', 'stock_availability', 'own_cost_changes']
const UNOBSERVABLE = ['competitor_prices', 'macro_conditions', 'marketing_other_than_promotions']
const ASSUMPTIONS = ['conditional_ignorability', 'positivity', 'no_interference', 'price_exogeneity']
const { t, te } = useI18n()
const { can } = useAbility()
const catalog = useCatalogStore()
const product = ref<Product | null>(null)
const form = reactive({
  branch_id: null as number | null, treatment: 'precio', outcome: 'unidades vendidas', unit_of_analysis: 'producto-sucursal-semana', estimand: 'efecto promedio del precio', horizon_days: 28 as number | null,
  population: '', price_change_pct: null as number | null,
  controlled: [] as string[], unobserved: [] as string[], assumptions: [] as string[],
})
const busy = ref(false)
const error = ref<string | null>(null)
const check = ref<CausalCheck | null>(null)
const envelope = ref<ResultEnvelope | null>(null)
const counts = computed(() => causalCounts(check.value))

async function run(): Promise<void> {
  busy.value = true
  error.value = null
  try {
    const res = await supplyApi.causalCheck({
      product_id: product.value?.id ?? null, branch_id: form.branch_id, treatment: form.treatment || null, outcome: form.outcome || null,
      unit_of_analysis: form.unit_of_analysis || null, estimand: form.estimand || null, horizon_days: form.horizon_days, population: form.population || null,
      price_change_pct: form.price_change_pct != null ? form.price_change_pct / 100 : null,
      confounders_controlled: form.controlled, unobserved_confounders_acknowledged: form.unobserved, identification_assumptions_acknowledged: form.assumptions,
    })
    check.value = res.data
    envelope.value = res.envelope
  } catch (e) {
    check.value = null
    error.value = errorMessage(e, t)
  } finally {
    busy.value = false
  }
}
const statusClass = (s: string): string => (s === 'met' ? 'badge-success' : s === 'declared_not_verifiable' ? 'badge-info' : 'badge-danger')
const reqLabel = (id: string, fallback: string): string => (te(`supply.causal.requirements.${id}`) ? t(`supply.causal.requirements.${id}`) : fallback)
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('supply.tabs.causal')" data-test="causal-tab">
    <InlineNotice kind="info" :title="t('supply.causal.introTitle')">{{ t('supply.causal.intro') }}</InlineNotice>
    <form v-if="can('forecast.run')" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="run">
      <div><label class="label" for="ca-product">{{ t('filters.product') }}</label><ProductPicker id="ca-product" v-model="product" /></div>
      <div><label class="label" for="ca-branch">{{ t('filters.branch') }}</label><select id="ca-branch" v-model="form.branch_id" class="select"><option :value="null">{{ t('filters.allBranches') }}</option><option v-for="b in catalog.branches" :key="b.id" :value="b.id">{{ b.name }}</option></select></div>
      <div><label class="label" for="ca-price">{{ t('supply.causal.priceChange') }}</label><input id="ca-price" v-model.number="form.price_change_pct" type="number" step="1" min="-95" max="500" class="input" :placeholder="t('supply.causal.optional')" /></div>
      <div><label class="label" for="ca-treatment">{{ t('supply.causal.treatment') }}</label><input id="ca-treatment" v-model="form.treatment" class="input" maxlength="100" /></div>
      <div><label class="label" for="ca-outcome">{{ t('supply.causal.outcome') }}</label><input id="ca-outcome" v-model="form.outcome" class="input" maxlength="100" /></div>
      <div><label class="label" for="ca-unit">{{ t('supply.causal.unit') }}</label><input id="ca-unit" v-model="form.unit_of_analysis" class="input" maxlength="100" /></div>
      <div><label class="label" for="ca-estimand">{{ t('supply.causal.estimand') }}</label><input id="ca-estimand" v-model="form.estimand" class="input" maxlength="100" /></div>
      <div><label class="label" for="ca-horizon">{{ t('supply.causal.horizon') }}</label><input id="ca-horizon" v-model.number="form.horizon_days" type="number" min="1" max="365" class="input" /></div>
      <div><label class="label" for="ca-pop">{{ t('supply.causal.population') }}</label><input id="ca-pop" v-model="form.population" class="input" maxlength="200" /></div>
      <fieldset class="border-0 p-0 m-0"><legend class="label">{{ t('supply.causal.controlled') }}</legend><label v-for="c in OBSERVABLE" :key="c" class="flex items-center gap-2 text-sm"><input v-model="form.controlled" type="checkbox" :value="c" /> {{ t(`supply.causal.confounders.${c}`) }}</label></fieldset>
      <fieldset class="border-0 p-0 m-0"><legend class="label">{{ t('supply.causal.unobserved') }}</legend><label v-for="c in UNOBSERVABLE" :key="c" class="flex items-center gap-2 text-sm"><input v-model="form.unobserved" type="checkbox" :value="c" /> {{ t(`supply.causal.confounders.${c}`) }}</label></fieldset>
      <fieldset class="border-0 p-0 m-0"><legend class="label">{{ t('supply.causal.assumptions') }}</legend><label v-for="c in ASSUMPTIONS" :key="c" class="flex items-center gap-2 text-sm"><input v-model="form.assumptions" type="checkbox" :value="c" /> {{ t(`supply.causal.assumptionNames.${c}`) }}</label><p class="hint m-0">{{ t('supply.causal.assumptionsHint') }}</p></fieldset>
      <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="btn btn-primary" :disabled="busy" data-test="causal-check"><AppIcon name="shield" :size="16" /> {{ busy ? t('common.processing') : t('supply.causal.check') }}</button></div>
    </form>
    <InlineNotice v-else kind="info">{{ t('supply.needsRun') }}</InlineNotice>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>

    <template v-if="check">
      <div class="rounded-xl border border-danger p-4 flex flex-col gap-2" data-test="causal-verdict">
        <div class="flex flex-wrap items-center gap-2"><h3 class="section-title m-0">{{ t('supply.causal.verdictTitle') }}</h3><QualityBadge status="insufficient_evidence" /></div>
        <p class="m-0 text-sm" data-test="causal-message">{{ check.message }}</p>
        <p class="m-0 font-semibold" data-test="causal-no-effect">{{ t('supply.causal.noEffect') }}</p>
        <p class="m-0 text-sm text-fg-muted">{{ t('supply.causal.estimatorOff') }}</p>
        <p class="m-0 text-sm">{{ t('supply.causal.eligible', { v: check.eligible_for_estimator_evaluation ? t('common.yes') : t('common.no') }) }}</p>
      </div>
      <p class="text-sm m-0">{{ t('supply.causal.counts', counts) }}</p>
      <ul class="list-none p-0 m-0 flex flex-col gap-3" data-test="causal-requirements">
        <li v-for="r in check.requirements" :key="r.id" class="rounded-xl border border-line p-3" :data-requirement="r.id" :data-status="r.status">
          <div class="flex flex-wrap items-center gap-2"><span class="font-semibold text-sm">{{ reqLabel(r.id, r.label) }}</span><span class="badge" :class="statusClass(r.status)">{{ t(`supply.causal.status.${r.status}`) }}</span></div>
          <p v-if="r.how_to_fix" class="text-sm m-0 mt-1"><strong>{{ t('supply.causal.howToFix') }}</strong> {{ r.how_to_fix }}</p>
        </li>
      </ul>
      <InlineNotice kind="info">{{ check.predictive_vs_causal }}</InlineNotice>
      <p v-if="check.conditional_simulation.available" class="text-sm m-0">{{ check.conditional_simulation.note }} <RouterLink :to="{ name: 'supply', params: { tab: 'escenarios' } }" class="font-semibold">{{ t('supply.causal.goScenarios') }}</RouterLink></p>
      <EnvelopePanel :envelope="envelope" />
    </template>
  </section>
</template>
