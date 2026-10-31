<script setup lang="ts">
/**
 * Evaluador de escenarios: compara estrategias sobre el MISMO corte, pronóstico base, unidades y horizonte. Cada entrada se
 * clasifica como observada, pronosticada o supuesta. Es una simulación condicionada: el cambio de demanda es un supuesto y NO
 * se deriva del cambio de precio. No es una estimación causal.
 */
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import { supplyApi } from '@/api'
import { errorMessage, isApiError } from '@/api/errors'
import type { ScenarioEvaluationResult, ScenarioStrategyInput } from '@/api/types'
import { useAsync } from '@/composables/useAsync'
import { useAbility } from '@/composables/useAbility'
import { useTenantStore } from '@/stores/tenant'
import { formatCurrency, formatNumber, formatPercent } from '@/utils/format'
import { classificationCounts } from '@/utils/supply'
import type { ResultEnvelope } from '@/api/types'

interface Row { key: string; name: string; price: number; discount: number; cost: number; demand: number; useCost: boolean; planId: number | null }
const { t, te } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()
const horizon = ref(30)
const rows = reactive<Row[]>([
  { key: 'base', name: '', price: 0, discount: 0, cost: 0, demand: 0, useCost: false, planId: null },
  { key: 's2', name: '', price: 0, discount: 0, cost: 0, demand: 0, useCost: false, planId: null },
])
const plans = useAsync(() => supplyApi.plans({ status: 'completed', per_page: 20 }))
const evaluating = ref(false)
const error = ref<string | null>(null)
const result = ref<ScenarioEvaluationResult | null>(null)
const envelope = ref<ResultEnvelope | null>(null)

const pct = (v: number): number => Math.round(v * 10) / 1000
function payload(): { horizon_days: number; strategies: ScenarioStrategyInput[] } {
  return {
    horizon_days: horizon.value,
    strategies: rows.map((r, i) => ({
      key: r.key || `s${i + 1}`,
      name: r.name.trim() || (i === 0 ? t('supply.scenarios.baseName') : t('supply.scenarios.strategyN', { n: i + 1 })),
      price_change_pct: pct(r.price), discount_pct: pct(r.discount), cost_change_pct: pct(r.cost), demand_change_pct: pct(r.demand),
      use_cost_forecast: r.useCost, ...(r.planId ? { plan_run_id: r.planId } : {}),
    })),
  }
}
async function evaluate(): Promise<void> {
  evaluating.value = true
  error.value = null
  try {
    const res = await supplyApi.evaluateScenarios(payload())
    result.value = res.data
    envelope.value = res.envelope
  } catch (e) {
    result.value = null
    error.value = isApiError(e) && e.isPlanLimit ? t('supply.scenarios.planLimit') : errorMessage(e, t)
  } finally {
    evaluating.value = false
  }
}
function add(): void {
  if (rows.length < 8) rows.push({ key: `s${rows.length + 1}`, name: '', price: 0, discount: 0, cost: 0, demand: 0, useCost: false, planId: null })
}
const res = computed(() => result.value?.results ?? null)
const cur = computed(() => res.value?.common_basis.currency ?? tenant.currency)
const ranking = computed(() => res.value?.ranking_by_gross_margin ?? [])
const nameOf = (key: string): string => res.value?.strategies.find((s) => s.key === key)?.name ?? key
const money = (v: number | null | undefined): string => (v == null ? t('supply.scenarios.unknown') : formatCurrency(v, cur.value, 0))
const inputText = (i: string): string => (te(`supply.scenarios.inputs.${i}`) ? t(`supply.scenarios.inputs.${i}`) : i)
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('supply.tabs.scenarios')" data-test="scenarios-tab">
    <InlineNotice kind="warning" :title="t('supply.scenarios.notCausalTitle')" data-test="not-causal">{{ t('supply.scenarios.notCausal') }}</InlineNotice>
    <form v-if="can('forecast.run')" class="flex flex-col gap-4" @submit.prevent="evaluate">
      <div class="max-w-xs">
        <label class="label" for="sc-h">{{ t('supply.scenarios.horizon') }}</label>
        <input id="sc-h" v-model.number="horizon" type="number" min="7" max="180" class="input" />
      </div>
      <div class="overflow-x-auto">
        <table class="table">
          <caption class="sr-only">{{ t('supply.scenarios.strategies') }}</caption>
          <thead><tr><th scope="col">{{ t('supply.scenarios.name') }}</th><th scope="col">{{ t('supply.scenarios.price') }}</th><th scope="col">{{ t('supply.scenarios.discount') }}</th><th scope="col">{{ t('supply.scenarios.cost') }}</th><th scope="col">{{ t('supply.scenarios.demand') }}</th><th scope="col">{{ t('supply.scenarios.useCostForecast') }}</th><th scope="col">{{ t('supply.scenarios.plan') }}</th><th scope="col"></th></tr></thead>
          <tbody>
            <tr v-for="(r, i) in rows" :key="i">
              <td><label class="sr-only" :for="`sc-n-${i}`">{{ t('supply.scenarios.name') }}</label><input :id="`sc-n-${i}`" v-model="r.name" class="input !min-h-9" :placeholder="i === 0 ? t('supply.scenarios.baseName') : t('supply.scenarios.strategyN', { n: i + 1 })" maxlength="150" /></td>
              <td v-for="f in (['price', 'discount', 'cost', 'demand'] as const)" :key="f"><label class="sr-only" :for="`sc-${f}-${i}`">{{ t(`supply.scenarios.${f}`) }}</label><input :id="`sc-${f}-${i}`" v-model.number="r[f]" type="number" step="1" min="-90" max="500" class="input !min-h-9 !w-20 text-right tabular" /></td>
              <td class="text-center"><input v-model="r.useCost" type="checkbox" :aria-label="t('supply.scenarios.useCostForecast')" /></td>
              <td><select v-model="r.planId" class="select !min-h-9" :aria-label="t('supply.scenarios.plan')"><option :value="null">{{ t('supply.scenarios.noPlan') }}</option><option v-for="p in plans.data.value?.data ?? []" :key="p.id" :value="p.id">v{{ p.version }}</option></select></td>
              <td><button v-if="i > 0" type="button" class="btn btn-ghost btn-sm" :aria-label="t('common.delete')" @click="rows.splice(i, 1)"><AppIcon name="trash" :size="15" /></button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="hint m-0">{{ t('supply.scenarios.pctHint') }}</p>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary" :disabled="evaluating" data-test="evaluate"><AppIcon name="play" :size="16" /> {{ evaluating ? t('common.processing') : t('supply.scenarios.evaluate') }}</button>
        <button type="button" class="btn btn-secondary" :disabled="rows.length >= 8" @click="add"><AppIcon name="plus" :size="16" /> {{ t('supply.scenarios.addStrategy') }}</button>
      </div>
    </form>
    <InlineNotice v-else kind="info">{{ t('supply.needsRun') }}</InlineNotice>
    <p v-if="error" class="field-error" role="alert" data-test="scenario-error">{{ error }}</p>

    <EmptyState v-if="!res && !error" icon="sliders" :title="t('supply.scenarios.emptyTitle')" :description="t('supply.scenarios.emptyText')" compact />
    <template v-if="res">
      <InlineNotice kind="warning" data-test="scenario-disclaimer">{{ res.disclaimer }}</InlineNotice>
      <p class="text-sm m-0">{{ res.common_basis.note }}</p>
      <div class="table-wrap">
        <table class="table" data-test="scenario-table">
          <caption class="sr-only">{{ t('supply.scenarios.results') }}</caption>
          <thead><tr><th scope="col">{{ t('supply.scenarios.measure') }}</th><th v-for="s in res.strategies" :key="s.key" scope="col" class="num">{{ s.name }}</th></tr></thead>
          <tbody>
            <tr><td>{{ t('supply.scenarios.sales') }}</td><td v-for="s in res.strategies" :key="s.key" class="num">{{ s.metrics.attainable_sales_units != null ? formatNumber(s.metrics.attainable_sales_units, 0) : '—' }}</td></tr>
            <tr><td>{{ t('supply.scenarios.unmet') }}</td><td v-for="s in res.strategies" :key="s.key" class="num">{{ s.metrics.unmet_units != null ? formatNumber(s.metrics.unmet_units, 0) : '—' }}</td></tr>
            <tr><td>{{ t('supply.scenarios.revenue') }}</td><td v-for="s in res.strategies" :key="s.key" class="num">{{ money(s.metrics.revenue) }}</td></tr>
            <tr data-test="margin-row"><td>{{ t('supply.scenarios.margin') }}</td><td v-for="s in res.strategies" :key="s.key" class="num">{{ money(s.metrics.gross_margin) }}<span v-if="s.metrics.gross_margin_pct != null" class="block text-xs text-fg-muted">{{ formatPercent(s.metrics.gross_margin_pct, 1) }}</span><span v-if="s.metrics.gross_margin == null" class="block text-xs text-warning">{{ t('supply.scenarios.marginUnknown') }}</span></td></tr>
            <tr><td>{{ t('supply.scenarios.endingInventory') }}</td><td v-for="s in res.strategies" :key="s.key" class="num">{{ money(s.metrics.ending_inventory_value) }}</td></tr>
            <tr><td>{{ t('supply.scenarios.planOutlay') }}</td><td v-for="s in res.strategies" :key="s.key" class="num">{{ money(s.metrics.plan_purchase_outlay) }}</td></tr>
          </tbody>
        </table>
      </div>
      <p v-if="ranking.length" class="text-sm m-0" data-test="ranking">{{ t('supply.scenarios.ranking', { list: ranking.map(nameOf).join(' > ') }) }}</p>
      <p v-else class="text-sm text-warning m-0">{{ t('supply.scenarios.noRanking') }}</p>
      <ul v-if="res.strategies.some((s) => s.metrics.units_note)" class="list-disc pl-5 m-0 text-sm text-fg-muted"><li v-for="s in res.strategies.filter((x) => x.metrics.units_note)" :key="s.key">{{ s.name }}: {{ s.metrics.units_note }}</li></ul>

      <div v-for="s in res.strategies" :key="s.key" class="rounded-xl border border-line p-3" :data-test="`inputs-${s.key}`">
        <h3 class="text-sm font-semibold m-0 mb-1">{{ t('supply.scenarios.inputsOf', { name: s.name }) }} <span class="text-fg-muted font-normal">({{ t('supply.scenarios.counts', classificationCounts(s)) }})</span></h3>
        <ul class="list-none p-0 m-0 flex flex-col gap-1 text-sm">
          <li v-for="i in s.inputs" :key="i.input" class="flex flex-wrap items-baseline gap-2">
            <span class="badge" :class="i.classification === 'assumed' ? 'badge-warning' : i.classification === 'forecasted' ? 'badge-info' : 'badge-success'" :data-classification="i.classification">{{ t(`supply.scenarios.classes.${i.classification}`) }}</span>
            <span class="font-medium">{{ inputText(i.input) }}</span><span class="text-fg-muted">{{ i.source }}</span>
          </li>
        </ul>
      </div>
      <EnvelopePanel :envelope="envelope" />
    </template>
  </section>
</template>
