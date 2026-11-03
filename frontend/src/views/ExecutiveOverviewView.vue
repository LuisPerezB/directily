<script setup lang="ts">
/**
 * Resumen ejecutivo (V2.5 · W5-E; ADR-V25-22; V25-UIX-001): pronóstico, stock, oportunidades, decisiones pendientes y resultados medidos con su clasificación.
 * SOLO agrega lo que entregan las APIs existentes (/dashboard/summary, /inventory/summary, /radar/summary, /decisions/summary, /decisions/roi): no inventa
 * indicadores; donde la API no tiene un indicador (capacidad, beneficio probado) lo dice. Cada tarjeta lleva su corte, unidad e incertidumbre, y el origen
 * real/demostración/simulado. Ninguna cifra económica de tipos distintos se suma, y un valor nulo es «No estimado», nunca 0.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import HorizonSupportNotice from '@/components/forecast/HorizonSupportNotice.vue'
import DataOriginBand from '@/components/enterprise/DataOriginBand.vue'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import PartialSumNote from '@/components/enterprise/PartialSumNote.vue'
import ProvenanceLine from '@/components/enterprise/ProvenanceLine.vue'
import { decisionApi, forecastApi, inventoryApi, outcomeApi, radarApi } from '@/api'
import { isApiError } from '@/api/errors'
import { useAbility } from '@/composables/useAbility'
import { useAsync } from '@/composables/useAsync'
import { useHorizonSelection } from '@/composables/useHorizons'
import { useTenantStore } from '@/stores/tenant'
import { horizonSupportView } from '@/utils/envelope'
import { isKnown, leftOut } from '@/utils/enterprise'
import { formatDate, formatInteger, formatNumber } from '@/utils/format'
import { CLASSIFICATION_ORDER, classificationCounts, latestKnowledgeCutoff, reportPeriod, roiView } from '@/utils/outcomes'
import { summaryHorizons } from '@/utils/radar'

const { t } = useI18n()
const tenant = useTenantStore()
const { can } = useAbility()
const { horizon, options: horizonOptions, hasHorizons } = useHorizonSelection()

// Los datos pertenecen a la empresa activa: al cambiarla se descartan de inmediato y se vuelven a pedir (nada de la anterior queda en pantalla).
const scope = () => tenant.currentUuid
const forecast = useAsync(() => forecastApi.dashboard({ horizon: horizon.value }), { watch: [horizon], scope })
const inventory = useAsync(() => inventoryApi.summary(), { scope })
// Un plan sin Customer no consulta el Radar: el servidor respondería 402.
const radar = useAsync(() => (tenant.tenant?.plan?.limits?.features?.customer === false ? Promise.resolve(null) : radarApi.summary({})), { scope })
const decisions = useAsync(() => decisionApi.summary(), { scope })
const roi = useAsync(() => outcomeApi.roi({}), { scope })
// Corte real de los datos de los resultados: hasta cuándo se leyeron las ventas del resultado medido más reciente (no la fecha en que se generó el informe).
const latest = useAsync(() => outcomeApi.list({ sort: '-window_to', per_page: 1 }), { scope })

const currency = computed(() => tenant.currency)

// ─── Pronóstico ───
const f = computed(() => forecast.data.value)
const support = computed(() => horizonSupportView(f.value?.horizon_support))
const forecastComplete = computed(() => !!f.value?.data_status?.has_forecast && support.value?.showAsComplete !== false)
const forecastRange = computed(() => {
  const k = f.value?.kpis
  if (support.value?.hideIntervals || !k || !isKnown(k.forecast_units_lo_80) || !isKnown(k.forecast_units_hi_80)) return null
  return t('dashboard.range80', { lo: formatNumber(k.forecast_units_lo_80, 0), hi: formatNumber(k.forecast_units_hi_80, 0) })
})

// ─── Stock ───
const stockRisk = computed(() => {
  const c = inventory.data.value?.risk_counts
  return c ? (c.high ?? 0) + (c.critical ?? 0) : null
})

// ─── Oportunidades: un horizonte, el elegido si es del Customer Core y, si no, el primero disponible (nunca la suma de varios) ───
const radarPlanBlocked = computed(() => tenant.tenant?.plan?.limits?.features?.customer === false || (isApiError(radar.error.value) && radar.error.value.isPlanLimit))
const radarEntry = computed(() => {
  const list = summaryHorizons(radar.data.value?.data)
  return list.find((x) => x.horizon === horizon.value) ?? list.find((x) => x.horizon === 30) ?? list[0] ?? null
})
const radarFailure = computed(() => (radarPlanBlocked.value ? null : radar.error.value))

// ─── Resultados medidos ───
const report = computed(() => roi.data.value)
// Para quien no aprueba, los conteos por clasificación solo se muestran si no incluyen lo retenido para medición (la API no se lo entrega y no se deriva).
const classificationTotals = computed(() => (report.value ? classificationCounts(report.value.summary, can('decisions.approve')) : null))
const classifications = computed(() => CLASSIFICATION_ORDER.map((c) => ({ key: c, n: classificationTotals.value?.[c] ?? 0 })))
const dataCutoff = computed(() => latestKnowledgeCutoff(latest.data.value?.data ?? []))
const roiState = computed(() => (report.value ? roiView(report.value.roi) : null))
// El periodo que el servidor aplicó de verdad (por omisión, los últimos 180 días del corte de las recomendaciones).
const roiPeriod = computed(() => (report.value ? reportPeriod(report.value) : null))

function reloadAll(): void {
  void forecast.execute()
  void inventory.execute()
  void radar.execute()
  void decisions.execute()
  void roi.execute()
  void latest.execute()
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <header class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
      <div>
        <h1 class="page-title">{{ t('exec.title') }}</h1>
        <p class="text-fg-muted mt-1">{{ t('exec.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <SegmentedControl v-if="hasHorizons" v-model="horizon" :options="horizonOptions" :label="t('common.horizon')" />
        <button type="button" class="btn btn-secondary btn-sm" data-test="refresh" @click="reloadAll">{{ t('exec.refresh') }}</button>
      </div>
    </header>

    <DataOriginBand :origin="report ? (report.data_origin.value ?? 'undeclared') : undefined" />

    <div class="grid gap-4 lg:grid-cols-2">
      <!-- Pronóstico -->
      <article class="card p-4 sm:p-6 flex flex-col gap-2" :aria-label="t('exec.forecast.title')" data-test="exec-forecast">
        <h2 class="section-title m-0">{{ t('exec.forecast.title') }}</h2>
        <LoadingState v-if="forecast.loading.value && !f" :lines="2" />
        <ErrorState v-else-if="forecast.error.value" :error="forecast.error.value" compact @retry="forecast.execute" />
        <template v-else-if="f">
          <ProvenanceLine :as-of="f.run?.cutoff_date" :unit="t('chart.units')" :uncertainty="forecastRange ?? t('exec.forecast.noInterval')" :coverage="f.data_status?.last_sale_date ? t('exec.forecast.lastSale', { date: f.data_status.last_sale_date }) : null" />
          <HorizonSupportNotice :support="f.horizon_support" :horizon="horizon" />
          <p v-if="!f.data_status?.has_forecast" class="m-0 text-sm" data-test="forecast-none">{{ t('exec.forecast.none') }}</p>
          <p v-else-if="!forecastComplete" class="m-0 text-sm" data-test="forecast-incomplete">{{ t('exec.forecast.incomplete') }}</p>
          <template v-else>
            <p class="m-0 text-2xl font-bold tabular" data-test="forecast-units">{{ isKnown(f.kpis.forecast_units) ? `${formatNumber(f.kpis.forecast_units, 0)} ${t('chart.units')}` : t('enterprise.notEstimated') }}</p>
            <p v-if="forecastRange" class="m-0 text-sm text-fg-muted">{{ forecastRange }}</p>
          </template>
          <RouterLink v-if="can('forecast.view')" :to="{ name: 'forecasts' }" class="text-sm font-semibold text-brand-strong">{{ t('dashboard.seeForecasts') }}</RouterLink>
        </template>
      </article>

      <!-- Stock y capacidad -->
      <article class="card p-4 sm:p-6 flex flex-col gap-2" :aria-label="t('exec.stock.title')" data-test="exec-stock">
        <h2 class="section-title m-0">{{ t('exec.stock.title') }}</h2>
        <LoadingState v-if="inventory.loading.value && !inventory.data.value" :lines="2" />
        <ErrorState v-else-if="inventory.error.value" :error="inventory.error.value" compact @retry="inventory.execute" />
        <template v-else-if="inventory.data.value">
          <ProvenanceLine :as-of="inventory.data.value.snapshot_date" :unit="t('exec.stock.unit')" :uncertainty="t('exec.stock.noInterval')" />
          <p v-if="!inventory.data.value.snapshot_date" class="m-0 text-sm" data-test="stock-none">{{ t('exec.stock.none') }}</p>
          <dl v-else class="grid grid-cols-2 gap-3 m-0 text-sm">
            <div><dt class="text-fg-muted">{{ t('exec.stock.atRisk') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="stock-risk">{{ stockRisk === null ? t('enterprise.notEstimated') : formatInteger(stockRisk) }}</dd></div>
            <div><dt class="text-fg-muted">{{ t('exec.stock.coverage') }}</dt><dd class="m-0 text-xl font-bold tabular">{{ isKnown(inventory.data.value.coverage_days_median) ? t('common.daysValue', { n: formatNumber(inventory.data.value.coverage_days_median, 0) }) : t('enterprise.notEstimated') }}</dd></div>
          </dl>
          <p class="m-0 text-sm text-fg-muted" data-test="capacity-unavailable">{{ t('exec.stock.capacityUnavailable') }}</p>
          <RouterLink v-if="can('forecast.view')" :to="{ name: 'inventory' }" class="text-sm font-semibold text-brand-strong">{{ t('dashboard.seeInventory') }}</RouterLink>
        </template>
      </article>

      <!-- Oportunidades -->
      <article class="card p-4 sm:p-6 flex flex-col gap-2" :aria-label="t('exec.radar.title')" data-test="exec-radar">
        <h2 class="section-title m-0">{{ t('exec.radar.title') }}</h2>
        <InlineNotice v-if="radarPlanBlocked" kind="warning" data-test="exec-radar-plan">{{ t('radar.notInPlan') }}</InlineNotice>
        <LoadingState v-else-if="radar.loading.value && !radar.data.value" :lines="2" />
        <ErrorState v-else-if="radarFailure" :error="radarFailure" compact @retry="radar.execute" />
        <template v-else-if="radar.data.value">
          <ProvenanceLine :as-of="radar.data.value.data.as_of" :unit="radar.data.value.data.totals.unit" :currency="currency" :uncertainty="t('exec.radar.uncertainty')" />
          <p v-if="!radarEntry" class="m-0 text-sm" data-test="radar-none">{{ radar.data.value.message ?? t('exec.radar.none') }}</p>
          <template v-else>
            <p class="m-0 text-sm text-fg-muted">{{ t('exec.radar.horizon', { n: radarEntry.horizon }) }}</p>
            <p class="m-0 text-2xl font-bold tabular" data-test="radar-count">{{ t('exec.radar.count', { opportunities: formatInteger(radarEntry.data.opportunities), customers: formatInteger(radarEntry.data.customers) }) }}</p>
            <dl class="grid grid-cols-2 gap-3 m-0 text-sm">
              <div>
                <dt class="text-fg-muted">{{ t('radar.value.forecastGross') }}</dt>
                <dd class="m-0"><span class="font-semibold" data-test="radar-gross"><MoneyFigure :value="radarEntry.data.value.gross_value" :currency="radarEntry.data.value.currency" /></span><PartialSumNote :n="leftOut(radarEntry.data.value.opportunities, radarEntry.data.value.gross_value_opportunities)" reason="value" data-test="radar-gross-partial" /></dd>
              </div>
              <div>
                <dt class="text-fg-muted">{{ t('radar.value.contribution') }}</dt>
                <dd class="m-0"><span class="font-semibold" data-test="radar-contribution"><MoneyFigure :value="radarEntry.data.value.contribution" :currency="radarEntry.data.value.currency" :unknown-label="t('enterprise.notEstimatedF')" /></span><PartialSumNote :n="radarEntry.data.value.contribution_unavailable" reason="cost" data-test="radar-contribution-partial" /></dd>
              </div>
            </dl>
            <p class="m-0 text-xs"><span class="badge badge-warning">{{ t('enterprise.attribution.potential_unproven') }}</span></p>
            <p class="m-0 text-sm" data-test="radar-decline">
              <span class="text-fg-muted">{{ t('exec.radar.decline') }}: </span>
              <MoneyFigure :value="radarEntry.data.customer_level.observed_spend_decline" :currency="radarEntry.data.customer_level.currency" />
              <PartialSumNote :n="leftOut(radarEntry.data.customer_level.opportunities, radarEntry.data.customer_level.observed_spend_decline_opportunities)" reason="value" data-test="radar-decline-partial" />
              <span class="block text-xs text-fg-muted">{{ t('radar.summary.customerLevelNote') }}</span>
            </p>
          </template>
          <RouterLink :to="{ name: 'radar' }" class="text-sm font-semibold text-brand-strong">{{ t('exec.radar.open') }}</RouterLink>
        </template>
      </article>

      <!-- Decisiones pendientes -->
      <article class="card p-4 sm:p-6 flex flex-col gap-2" :aria-label="t('exec.decisions.title')" data-test="exec-decisions">
        <h2 class="section-title m-0">{{ t('exec.decisions.title') }}</h2>
        <LoadingState v-if="decisions.loading.value && !decisions.data.value" :lines="2" />
        <ErrorState v-else-if="decisions.error.value" :error="decisions.error.value" compact @retry="decisions.execute" />
        <template v-else-if="decisions.data.value">
          <ProvenanceLine live :unit="t('exec.decisions.unit')" :uncertainty="t('exec.decisions.uncertainty')" />
          <dl class="grid grid-cols-3 gap-3 m-0 text-sm">
            <div><dt class="text-fg-muted">{{ t('decisions.summary.awaitingAssignment') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="awaiting-assignment">{{ formatInteger(decisions.data.value.awaiting_assignment) }}</dd></div>
            <div><dt class="text-fg-muted">{{ t('decisions.summary.awaitingApproval') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="awaiting-approval">{{ formatInteger(decisions.data.value.awaiting_approval) }}</dd></div>
            <div><dt class="text-fg-muted">{{ t('decisions.summary.expiring') }}</dt><dd class="m-0 text-xl font-bold tabular" data-test="expiring">{{ formatInteger(decisions.data.value.expiring_within_24h) }}</dd></div>
          </dl>
          <p class="m-0 text-xs text-fg-muted">{{ decisions.data.value.attribution_note }}</p>
          <RouterLink :to="{ name: 'decision-center' }" class="text-sm font-semibold text-brand-strong">{{ t('exec.decisions.open') }}</RouterLink>
        </template>
      </article>

      <!-- Resultados medidos -->
      <article class="card p-4 sm:p-6 flex flex-col gap-2 lg:col-span-2" :aria-label="t('exec.outcomes.title')" data-test="exec-outcomes">
        <h2 class="section-title m-0">{{ t('exec.outcomes.title') }}</h2>
        <LoadingState v-if="roi.loading.value && !report" :lines="2" />
        <ErrorState v-else-if="roi.error.value" :error="roi.error.value" compact @retry="roi.execute" />
        <template v-else-if="report">
          <ProvenanceLine :as-of="dataCutoff" :generated-at="report.today" :unit="t('roi.unit')" :uncertainty="t('roi.uncertaintyIntervals')" :origin="report.data_origin.value ?? 'undeclared'" />
          <p v-if="roiPeriod && roiPeriod.from && roiPeriod.to" class="m-0 text-xs text-fg-muted" data-test="exec-period">{{ t('exec.outcomes.period', { from: formatDate(roiPeriod.from), to: formatDate(roiPeriod.to), n: roiPeriod.days ?? 0 }) }}</p>
          <ul class="list-none p-0 m-0 flex flex-wrap gap-2" data-test="exec-classifications">
            <li v-for="c in classifications" :key="c.key" class="badge" :title="report.method.classification[c.key] ?? undefined">{{ t(`roi.classification.${c.key}`) }}<template v-if="classificationTotals">: {{ formatInteger(c.n) }}</template></li>
          </ul>
          <p v-if="!classificationTotals" class="m-0 text-xs text-fg-muted" data-test="exec-classifications-hidden">{{ t('roi.classification.countsInList') }}</p>
          <p class="m-0 text-sm" data-test="exec-roi">
            <span class="badge badge-warning">{{ roiState ? t(`roi.status.${roiState.status}`) : '' }}</span>
            <span class="badge ml-1">{{ t('roi.headline.notProven') }}</span>
          </p>
          <p class="m-0 text-sm text-fg-muted">{{ roiState?.message }}</p>
          <p class="m-0 text-xs text-fg-muted">{{ t('exec.outcomes.note') }}</p>
          <RouterLink :to="{ name: 'roi-outcomes' }" class="text-sm font-semibold text-brand-strong">{{ t('exec.outcomes.open') }}</RouterLink>
        </template>
      </article>
    </div>

    <section class="card p-4 sm:p-6" :aria-label="t('exec.unavailable.title')" data-test="exec-unavailable">
      <h2 class="section-title m-0 mb-2">{{ t('exec.unavailable.title') }}</h2>
      <ul class="list-disc pl-5 m-0 text-sm flex flex-col gap-1">
        <li>{{ t('exec.unavailable.capacity') }}</li>
        <li>{{ t('exec.unavailable.benefit') }}</li>
        <li>{{ t('exec.unavailable.kpis') }}</li>
      </ul>
    </section>
  </div>
</template>
