<script setup lang="ts">
/**
 * Resultado de un plan de compras: sugerencias de reposición con restricciones. Predictily NO emite pedidos ni
 * compromete fondos. Muestra el estado del solver (nunca «óptimo» si no lo es), líneas con proveedor, cantidades,
 * fechas de emisión y recepción, costo, restricciones activas y justificación; la demanda no atendida con su causa; la
 * infactibilidad con causas y alternativas; las ofertas excluidas y la producción no configurada.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '@/components/ui/EmptyState.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import QualityBadge from '@/components/ui/QualityBadge.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import SolverStatus from './SolverStatus.vue'
import { supplyApi } from '@/api'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatCurrency, formatDate, formatNumber, formatPercent } from '@/utils/format'
import { infeasibilityCauses, planTotals, shortfallTotals, solverHasPlan, sortPlanLines } from '@/utils/supply'

const props = defineProps<{ runId: number }>()
const { t, te } = useI18n()
const tenant = useTenantStore()
const onlyNow = ref(false)
const result = useAsync(() => supplyApi.plan(props.runId, { actionable_now: onlyNow.value || undefined }), { watch: [() => props.runId, onlyNow] })
const data = computed(() => result.data.value?.data ?? null)
const envelope = computed(() => result.data.value?.envelope ?? null)
const plan = computed(() => data.value?.plan ?? null)
const lines = computed(() => sortPlanLines(data.value?.lines ?? []))
const totals = computed(() => planTotals(data.value?.lines ?? []))
const shortfalls = computed(() => data.value?.shortfalls ?? [])
const shortfallSum = computed(() => shortfallTotals(shortfalls.value))
const causes = computed(() => infeasibilityCauses(data.value?.infeasibility))
const excluded = computed(() => (data.value?.offers ?? []).filter((o) => o.status !== 'comparable'))
const binding = computed(() => (data.value?.constraints ?? []).filter((c) => c.binding))
const currency = computed(() => plan.value?.currency ?? tenant.currency)
// El bloque de infactibilidad solo aplica si el solver lo declaró o el motor entregó un diagnóstico con mensaje (un objeto vacío no cuenta).
const infeasible = computed(() => plan.value?.solver_status === 'infeasible' || !!data.value?.infeasibility?.message)
const hasPlan = computed(() => solverHasPlan(plan.value?.solver_status))
const money = (v: number | null | undefined, d = 2): string => formatCurrency(v, currency.value, d)
const reasonText = (r: string | null | undefined): string => (r && te(`supply.shortfall.${r}`) ? t(`supply.shortfall.${r}`) : (r ?? t('supply.shortfall.unknown')))
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
        </div>

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

        <div v-if="hasPlan">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
            <h3 class="section-title m-0">{{ t('supply.plan.linesTitle') }} <span class="text-sm font-normal text-fg-muted">({{ t('supply.plan.linesCount', { n: totals.lines, now: totals.actionableNow }) }})</span></h3>
            <label class="flex items-center gap-2 text-sm"><input v-model="onlyNow" type="checkbox" /> {{ t('supply.plan.onlyNow') }}</label>
          </div>
          <p v-if="!lines.length" class="text-sm text-fg-muted m-0" data-test="no-lines">{{ t('supply.plan.noLines') }}</p>
          <div v-else class="table-wrap">
            <table class="table" data-test="plan-lines">
              <caption class="sr-only">{{ t('supply.plan.linesTitle') }}</caption>
              <thead>
                <tr>
                  <th scope="col">{{ t('common.product') }}</th><th scope="col">{{ t('common.branch') }}</th><th scope="col">{{ t('supply.plan.supplier') }}</th>
                  <th scope="col" class="num">{{ t('supply.plan.quantity') }}</th><th scope="col">{{ t('supply.plan.issueDate') }}</th><th scope="col">{{ t('supply.plan.receiptDate') }}</th>
                  <th scope="col" class="num">{{ t('supply.plan.unitCost') }}</th><th scope="col" class="num">{{ t('supply.plan.lineCost') }}</th><th scope="col">{{ t('supply.plan.constraints') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(l, i) in lines" :key="l.id ?? i" :data-actionable="l.actionable_now">
                  <td><span class="font-medium block">{{ l.product?.name ?? `#${l.product_id}` }}</span><span class="text-xs text-fg-muted">{{ l.product?.sku }}</span><span v-if="l.line_type === 'production'" class="badge ml-1">{{ t('supply.plan.production') }}</span></td>
                  <td>{{ l.branch?.name ?? '—' }}</td>
                  <td>{{ l.supplier?.name ?? '—' }}</td>
                  <td class="num">{{ formatNumber(l.quantity, 2) }} <span class="text-xs text-fg-muted">{{ l.unit }}</span><span v-if="l.offer_quantity != null" class="block text-xs text-fg-muted">{{ formatNumber(l.offer_quantity, 2) }} {{ l.offer_unit }}</span></td>
                  <td>{{ formatDate(l.order_date) }}<span v-if="l.actionable_now" class="badge badge-success block w-fit mt-1" data-test="actionable-now">{{ t('supply.plan.issueToday') }}</span></td>
                  <td>{{ formatDate(l.expected_receipt_date) }}</td>
                  <td class="num">{{ l.unit_cost != null ? money(l.unit_cost, 4) : '—' }}</td>
                  <td class="num">{{ l.line_cost != null ? money(l.line_cost, 2) : '—' }}</td>
                  <td>
                    <span v-for="(b, k) in l.binding_constraints ?? []" :key="k" class="badge badge-warning mr-1" :title="`${formatNumber(b.used, 2)} / ${formatNumber(b.limit, 2)}`" data-test="binding">{{ familyText(b.family) }}</span>
                    <span v-if="!(l.binding_constraints ?? []).length" class="text-fg-muted">—</span>
                    <details v-if="l.justification" class="text-xs mt-1"><summary class="cursor-pointer text-fg-muted">{{ t('supply.plan.why') }}</summary><p class="m-0 max-w-xs">{{ l.justification }}</p></details>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div data-test="shortfalls">
          <h3 class="section-title mb-1">{{ t('supply.plan.shortfallsTitle') }}</h3>
          <p v-if="!shortfalls.length" class="text-sm text-fg-muted m-0">{{ t('supply.plan.noShortfalls') }}</p>
          <template v-else>
            <p class="text-sm text-fg-muted mb-2">{{ t('supply.plan.shortfallsText', { units: formatNumber(shortfallSum.unmet, 0), pct: shortfallSum.share != null ? formatPercent(shortfallSum.share, 1) : '—' }) }}</p>
            <div class="table-wrap">
              <table class="table">
                <caption class="sr-only">{{ t('supply.plan.shortfallsTitle') }}</caption>
                <thead><tr><th scope="col">{{ t('common.product') }}</th><th scope="col">{{ t('supply.plan.period') }}</th><th scope="col" class="num">{{ t('supply.plan.demand') }}</th><th scope="col" class="num">{{ t('supply.plan.unmet') }}</th><th scope="col">{{ t('supply.plan.reason') }}</th></tr></thead>
                <tbody>
                  <tr v-for="(s, i) in shortfalls.slice(0, 200)" :key="i">
                    <td>{{ s.product?.name ?? `#${s.product_id}` }}</td>
                    <td>{{ formatDate(s.period_start, 'short') }} – {{ formatDate(s.period_end, 'short') }}</td>
                    <td class="num">{{ formatNumber(s.demand, 1) }}</td><td class="num">{{ formatNumber(s.unmet, 1) }}</td>
                    <td><span class="badge badge-warning" data-test="shortfall-reason">{{ reasonText(s.reason) }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
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
