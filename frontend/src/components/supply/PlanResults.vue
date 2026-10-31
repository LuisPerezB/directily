<script setup lang="ts">
/**
 * Resultado de un plan de compras: sugerencias de reposición con restricciones. Predictily NO emite pedidos ni
 * compromete fondos. Muestra el estado del solver (nunca «óptimo» si no lo es), líneas con proveedor, cantidades,
 * fechas de emisión y recepción, costo, restricciones activas y justificación; la demanda no atendida con su causa; la
 * infactibilidad con causas y alternativas; las ofertas excluidas y la producción no configurada.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import SolverStatus from './SolverStatus.vue'
import PlanLinesTable from './PlanLinesTable.vue'
import PlanShortfallsTable from './PlanShortfallsTable.vue'
import { supplyApi } from '@/api'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatCurrency, formatDate, formatNumber, formatPercent } from '@/utils/format'
import { infeasibilityCauses, planCutoff, solverHasPlan } from '@/utils/supply'

const props = defineProps<{ runId: number }>()
const { t, te } = useI18n()
const tenant = useTenantStore()
// El detalle trae totales y resumen; las líneas y la demanda no atendida se paginan en el servidor (PlanLinesTable / PlanShortfallsTable).
const result = useAsync(() => supplyApi.plan(props.runId), { watch: [() => props.runId] })
const data = computed(() => result.data.value?.data ?? null)
const envelope = computed(() => result.data.value?.envelope ?? null)
const plan = computed(() => data.value?.plan ?? null)
const cutoff = computed(() => planCutoff(plan.value))
const linesSummary = computed(() => data.value?.lines_summary ?? { total: 0, shown: 0, matched: 0, truncated: false })
const shortfallsSummary = computed(() => data.value?.shortfalls_summary ?? { total: 0, shown: 0, matched: 0, truncated: false })
const branches = computed(() => data.value?.branches ?? [])
const ageText = computed(() => (cutoff.value.ageDays === 1 ? t('supply.plan.ageOneDay') : t('supply.plan.ageDays', { n: formatNumber(cutoff.value.ageDays ?? 0, 0) })))
const causes = computed(() => infeasibilityCauses(data.value?.infeasibility))
const excluded = computed(() => (data.value?.offers ?? []).filter((o) => o.status !== 'comparable'))
const binding = computed(() => (data.value?.constraints ?? []).filter((c) => c.binding))
const currency = computed(() => plan.value?.currency ?? tenant.currency)
// El bloque de infactibilidad solo aplica si el solver lo declaró o el motor entregó un diagnóstico con mensaje (un objeto vacío no cuenta).
const infeasible = computed(() => plan.value?.solver_status === 'infeasible' || !!data.value?.infeasibility?.message)
const hasPlan = computed(() => solverHasPlan(plan.value?.solver_status))
const money = (v: number | null | undefined, d = 2): string => formatCurrency(v, currency.value, d)
const familyText = (f: string): string => (te(`supply.families.${f}`) ? t(`supply.families.${f}`) : f)
const offerReason = (r: string): string => (te(`supply.offerReasons.${r}`) ? t(`supply.offerReasons.${r}`) : r)
const prodNotConfigured = computed(() => data.value?.production?.status === 'not_configured')
</script>

<template>
  <section class="flex flex-col gap-5" :aria-label="t('supply.plan.resultTitle')" data-test="plan-results">
    <LoadingState v-if="result.loading.value && !data" :lines="4" />
    <ErrorState v-else-if="result.error.value" :error="result.error.value" compact @retry="result.execute" />
    <template v-else-if="data">
      <InlineNotice kind="warning" :title="t('supply.plan.suggestionTitle')" data-test="no-orders-notice">{{ t('supply.plan.suggestion') }}</InlineNotice>

      <EmptyState v-if="!plan" icon="layers" :title="t('supply.plan.noPlanTitle')" :description="data.run.error_message ?? t('supply.plan.noPlanText')" compact data-test="plan-abstention">
        <QualityBadge :status="envelope?.quality_status" />
      </EmptyState>

      <template v-else>
        <div class="rounded-xl border border-line p-4 flex flex-col gap-3">
          <h3 class="section-title m-0">{{ t('supply.plan.solverTitle') }}</h3>
          <SolverStatus :status="plan.solver_status" :gap="plan.mip_gap" :seconds="plan.solve_seconds" :notes="data.solver?.notes" />
          <p class="text-sm m-0">{{ t(`supply.plan.objectives.${plan.objective}`) }} · {{ t(`supply.plan.coverageModes.${plan.coverage_mode}`) }}</p>
          <p v-if="cutoff.asOf" class="text-sm m-0 flex flex-wrap items-center gap-2" data-test="plan-cutoff">
            <span class="badge badge-info" :title="t('supply.plan.cutoffHint')"><AppIcon name="clock" :size="14" class="mr-1" />{{ t('supply.plan.cutoff', { date: formatDate(cutoff.asOf, 'long') }) }}</span>
          </p>
          <p v-else class="text-sm text-fg-muted m-0" data-test="plan-cutoff-unknown">{{ t('supply.plan.cutoffUnknown') }}</p>
        </div>

        <InlineNotice v-if="cutoff.isStale" kind="warning" :title="t('supply.plan.staleTitle')" data-test="plan-stale">
          {{ t('supply.plan.stale', { date: formatDate(cutoff.asOf, 'long'), age: ageText }) }}
        </InlineNotice>

        <dl v-if="hasPlan" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 m-0" data-test="plan-totals">
          <div class="rounded-xl border border-line p-3"><dt class="text-sm text-fg-muted">{{ t('supply.plan.purchaseCost') }}</dt><dd class="m-0 text-xl font-bold tabular">{{ money(plan.total_purchase_cost, 0) }}</dd></div>
          <div class="rounded-xl border border-line p-3"><dt class="text-sm text-fg-muted">{{ t('supply.plan.holdingCost') }}</dt><dd class="m-0 text-xl font-bold tabular">{{ money(plan.total_holding_cost, 0) }}</dd><dd class="m-0 hint">{{ t('supply.plan.assumptionCost') }}</dd></div>
          <div class="rounded-xl border border-line p-3"><dt class="text-sm text-fg-muted">{{ t('supply.plan.shortagePenalty') }}</dt><dd class="m-0 text-xl font-bold tabular">{{ money(plan.total_shortage_penalty, 0) }}</dd><dd class="m-0 hint">{{ t('supply.plan.assumptionCost') }}</dd></div>
          <div class="rounded-xl border border-line p-3"><dt class="text-sm text-fg-muted">{{ t('supply.plan.unmet') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="unmet-total">{{ formatNumber(plan.unmet_units_total, 0) }}</dd><dd class="m-0 hint">{{ plan.demand_units_total ? t('supply.plan.unmetShare', { pct: formatPercent((plan.unmet_units_total ?? 0) / plan.demand_units_total, 1) }) : '' }}</dd></div>
        </dl>

        <div v-if="infeasible" class="rounded-xl border border-danger p-4 flex flex-col gap-3" data-test="infeasibility">
          <h3 class="section-title m-0 text-danger">{{ t('supply.plan.infeasibleTitle') }}</h3>
          <p class="text-sm m-0">{{ data.infeasibility?.message ?? t('supply.plan.infeasibleText') }}</p>
          <div v-if="causes.length" class="table-wrap">
            <table class="table">
              <caption class="sr-only">{{ t('supply.plan.causes') }}</caption>
              <thead><tr><th scope="col">{{ t('supply.plan.cause') }}</th><th scope="col" class="num">{{ t('supply.plan.unitsRecovered') }}</th><th scope="col">{{ t('supply.plan.requirement') }}</th></tr></thead>
              <tbody>
                <tr v-for="c in causes" :key="c.family">
                  <td>{{ c.label || familyText(c.family) }}</td>
                  <td class="num">{{ formatNumber(c.units_recovered, 0) }}</td>
                  <td class="text-sm">
                    <template v-if="c.required?.length"><span v-for="(r, i) in c.required.slice(0, 3)" :key="i" class="block">{{ t('supply.plan.needed', { needed: formatNumber(Number(r.needed), 0), declared: formatNumber(Number(r.declared), 0) }) }}</span></template>
                    <span v-else class="text-fg-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="data.infeasibility?.structural" class="text-sm m-0">{{ t('supply.plan.structural', { label: data.infeasibility.structural.label, units: formatNumber(data.infeasibility.structural.unmet_even_without_constraints, 0) }) }}</p>
          <div v-if="data.alternative_plan" class="rounded-lg bg-surface-2 p-3" data-test="alternative-plan">
            <p class="font-semibold text-sm m-0">{{ t('supply.plan.alternativeTitle') }}</p>
            <p class="text-sm m-0">{{ data.alternative_plan.description }}</p>
            <p class="text-sm m-0 text-fg-muted">{{ t('supply.plan.alternativeLines', { n: data.alternative_plan.lines.length, unmet: formatNumber(Number((data.alternative_plan.summary as Record<string, unknown>).unmet_units_total ?? 0), 0) }) }}</p>
          </div>
        </div>

        <PlanLinesTable v-if="hasPlan" :run-id="runId" :summary="linesSummary" :branches="branches" :cutoff="cutoff" :currency="currency" />

        <div data-test="shortfalls">
          <h3 class="section-title mb-1">{{ t('supply.plan.shortfallsTitle') }}</h3>
          <p v-if="!shortfallsSummary.total" class="text-sm text-fg-muted m-0">{{ t('supply.plan.noShortfalls') }}</p>
          <PlanShortfallsTable v-else :run-id="runId" :summary="shortfallsSummary" :branches="branches" />
        </div>

        <div v-if="binding.length" data-test="active-constraints">
          <h3 class="section-title mb-1">{{ t('supply.plan.activeConstraints') }}</h3>
          <ul class="list-none p-0 m-0 text-sm flex flex-col gap-1"><li v-for="(c, i) in binding.slice(0, 20)" :key="i">{{ familyText(c.family) }}: {{ formatNumber(c.used, 2) }} / {{ formatNumber(c.limit, 2) }} <span class="text-fg-muted">{{ c.period != null ? `(${c.period})` : '' }}</span></li></ul>
        </div>

        <div data-test="offers-coverage">
          <h3 class="section-title mb-1">{{ t('supply.plan.offersTitle') }}</h3>
          <p v-if="data.comparison_coverage" class="text-sm m-0">{{ t('supply.plan.offersSummary', { received: data.comparison_coverage.offers_received, comparable: data.comparison_coverage.offers_comparable, excluded: data.comparison_coverage.offers_excluded }) }}</p>
          <p v-if="data.comparison_coverage?.note" class="hint m-0">{{ data.comparison_coverage.note }}</p>
          <ul v-if="excluded.length" class="list-disc pl-5 mt-2 mb-0 text-sm" data-test="excluded-offers">
            <li v-for="(o, i) in excluded" :key="i">{{ t('supply.plan.excludedOffer', { id: o.offer_id ?? '—', product: o.product_id }) }}: {{ (o.reasons ?? []).map(offerReason).join(', ') }}</li>
          </ul>
        </div>

        <p v-if="prodNotConfigured" class="text-sm text-fg-muted m-0" data-test="production-not-configured">{{ t('supply.plan.productionNotConfigured') }}</p>
      </template>
      <EnvelopePanel :envelope="envelope" />
    </template>
  </section>
</template>
