<script setup lang="ts">
/**
 * ROI y resultados (V2.5 · W5-E; ADR-V25-14 y -22). Separa SIEMPRE la oportunidad potencial, lo observado, lo asociado temporalmente a una acción y el incremento
 * estimado por intención de tratamiento; no los suma. El ROI nunca se presenta como probado: muestra su estado y su método, y solo con un experimento con grupo
 * de control y muestra suficiente, el incremento neto con su intervalo. Solo lee GET /decisions/roi y GET /decisions/outcomes (decisions.view).
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ErrorState from '@/components/ui/ErrorState.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import LoadingState from '@/components/ui/LoadingState.vue'
import DataOriginBand from '@/components/enterprise/DataOriginBand.vue'
import ProvenanceLine from '@/components/enterprise/ProvenanceLine.vue'
import RoiHeadline from '@/components/outcomes/RoiHeadline.vue'
import ValueKindColumns from '@/components/outcomes/ValueKindColumns.vue'
import RoiFunnel from '@/components/outcomes/RoiFunnel.vue'
import RoiCosts from '@/components/outcomes/RoiCosts.vue'
import RoiBreakdown from '@/components/outcomes/RoiBreakdown.vue'
import ExperimentCards from '@/components/outcomes/ExperimentCards.vue'
import OutcomesTable from '@/components/outcomes/OutcomesTable.vue'
import { outcomeApi } from '@/api'
import type { RoiQuery } from '@/api/types'
import { useAbility } from '@/composables/useAbility'
import { useAsync } from '@/composables/useAsync'
import { useTenantStore } from '@/stores/tenant'
import { formatDate } from '@/utils/format'
import { daysBetween, MAX_REPORT_RANGE_DAYS, todayISODate } from '@/utils/enterprise'
import { CLASSIFICATION_ORDER, classificationCounts, latestKnowledgeCutoff, reportPeriod } from '@/utils/outcomes'

const { t } = useI18n()
const { can } = useAbility()
const tenant = useTenantStore()

const kind = ref('')
const horizon = ref<number | null>(null)
const from = ref('')
const to = ref('')
const groupBy = ref<NonNullable<RoiQuery['group_by']>>('kind')

// Si «hasta» falta, el servidor toma hoy (día de la empresa) como fin del periodo: el rango se valida contra hoy. El servidor es la autoridad (422 si se pasa).
const effectiveTo = computed(() => to.value || todayISODate())
const rangeInvalid = computed(() => !!from.value && effectiveTo.value < from.value)
// El servidor rechaza (422) los periodos de más de 730 días (por defecto, los últimos 180 días del corte): se avisa antes de pedirlos y, mientras el rango no
// sea válido, no se envía ninguna de las dos fechas; el informe dice qué periodo aplicó de verdad.
const rangeTooLong = computed(() => !rangeInvalid.value && !!from.value && (daysBetween(from.value, effectiveTo.value) ?? 0) > MAX_REPORT_RANGE_DAYS)
const rangeUsable = computed(() => !rangeInvalid.value && !rangeTooLong.value)
const filters = computed<RoiQuery>(() => ({
  kind: kind.value || undefined,
  horizon_days: horizon.value ?? undefined,
  from: rangeUsable.value ? from.value || undefined : undefined,
  to: rangeUsable.value ? to.value || undefined : undefined,
}))
// Un solo informe: la agrupación elegida solo cambia el desglose. La oportunidad potencial no depende de ella (viene por tipo y horizonte en el resumen).
const report = useAsync(() => outcomeApi.roi({ ...filters.value, group_by: groupBy.value }), { watch: [filters, groupBy], scope: () => tenant.currentUuid })
// Corte real de los datos: hasta cuándo se leyeron las ventas del resultado medido más reciente (no la fecha en que se generó el informe).
const latest = useAsync(() => outcomeApi.list({ ...filters.value, sort: '-window_to', per_page: 1 }), { watch: [filters], scope: () => tenant.currentUuid })
const r = computed(() => report.data.value)
const breakdown = computed(() => r.value?.breakdown ?? null)
const dataCutoff = computed(() => latestKnowledgeCutoff(latest.data.value?.data ?? []))
const period = computed(() => (r.value ? reportPeriod(r.value) : null))
// «Periodo aplicado» (el que el servidor declara en la respuesta), con sus días si los trae; sin fechas, el que rige por defecto.
const appliedText = computed(() => {
  const p = period.value
  if (!p) return ''
  if (!p.from && !p.to) return t('roi.filters.periodDefault', { n: p.defaultDays ?? 180 })
  const dates = { from: p.from ? formatDate(p.from) : '…', to: p.to ? formatDate(p.to) : '…' }
  return p.days !== null ? t('roi.filters.periodAppliedDays', { ...dates, n: p.days }) : t('roi.filters.periodApplied', dates)
})
const listFilters = computed(() => ({ kind: filters.value.kind, horizon_days: filters.value.horizon_days, from: filters.value.from, to: filters.value.to }))
const canApprove = computed(() => can('decisions.approve'))
// Quien no aprueba solo ve conteos coherentes con lo que puede listar (la API no le entrega los de la retención y no se derivan).
const classificationTotals = computed(() => (r.value ? classificationCounts(r.value.summary, canApprove.value) : null))
const classifications = computed(() => CLASSIFICATION_ORDER.map((c) => ({ key: c, n: classificationTotals.value?.[c] ?? 0 })))
</script>

<template>
  <div class="flex flex-col gap-6">
    <header>
      <h1 class="page-title">{{ t('roi.title') }}</h1>
      <p class="text-fg-muted mt-1">{{ t('roi.subtitle') }}</p>
    </header>

    <DataOriginBand :origin="r ? (r.data_origin.value ?? 'undeclared') : undefined" />

    <section class="card p-4 sm:p-6" :aria-label="t('filters.title')" data-test="roi-filters">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div>
          <label class="label" for="roi-kind">{{ t('radar.filters.kind') }}</label>
          <select id="roi-kind" v-model="kind" class="select">
            <option value="">{{ t('radar.filters.all') }}</option>
            <option v-for="k in ['contact', 'recovery', 'customer_risk', 'replenishment', 'inventory_mismatch', 'budget_constraint', 'scenario']" :key="k" :value="k">{{ t(`decisions.kind.${k}`) }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="roi-horizon">{{ t('common.horizon') }}</label>
          <select id="roi-horizon" v-model="horizon" class="select">
            <option :value="null">{{ t('radar.filters.allHorizons') }}</option>
            <option v-for="h in [7, 14, 15, 30, 60, 90]" :key="h" :value="h">{{ t('common.days', { n: h }) }}</option>
          </select>
        </div>
        <div>
          <label class="label" for="roi-from">{{ t('roi.filters.from') }}</label>
          <input id="roi-from" v-model="from" type="date" class="input">
        </div>
        <div>
          <label class="label" for="roi-to">{{ t('roi.filters.to') }}</label>
          <input id="roi-to" v-model="to" type="date" class="input" :aria-invalid="rangeInvalid || rangeTooLong || undefined">
          <p v-if="rangeInvalid" class="field-error" data-test="range-error">{{ t('roi.filters.rangeInvalid') }}</p>
          <p v-else-if="rangeTooLong" class="field-error" data-test="range-too-long">{{ t('roi.filters.rangeTooLong') }}</p>
        </div>
        <div>
          <label class="label" for="roi-group">{{ t('roi.filters.groupBy') }}</label>
          <select id="roi-group" v-model="groupBy" class="select">
            <option v-for="g in ['kind', 'horizon', 'segment', 'owner']" :key="g" :value="g">{{ t(`roi.breakdown.groupBy.${g}`) }}</option>
          </select>
        </div>
      </div>
      <p class="hint mt-2 mb-0">{{ t('roi.filters.periodBasis') }}</p>
      <p v-if="r && period" class="hint mt-1 mb-0" data-test="period">
        {{ appliedText }}
      </p>
      <p v-if="r && period?.defaulted" class="hint mt-1 mb-0" data-test="period-defaulted">{{ t('roi.filters.periodDefaulted', { n: period.defaultDays ?? 180, max: period.maxDays ?? 730 }) }}</p>
    </section>

    <LoadingState v-if="report.loading.value && !r" :lines="5" />
    <div v-else-if="report.error.value" class="card"><ErrorState :error="report.error.value" @retry="report.execute" /></div>

    <div v-else-if="r" class="flex flex-col gap-6" :class="report.loading.value ? 'opacity-60' : ''" :aria-busy="report.loading.value" data-test="roi-report">
      <section class="card p-4 sm:p-6 flex flex-col gap-3">
        <ProvenanceLine :as-of="dataCutoff" :generated-at="r.today" :unit="t('roi.unit')" :currency="r.currency" :uncertainty="t('roi.uncertaintyIntervals')" :origin="r.data_origin.value ?? 'undeclared'" />
        <p class="m-0 text-xs text-fg-muted" data-test="origin-note">{{ r.data_origin.note }}</p>
        <RoiHeadline :report="r" />
      </section>

      <section class="card p-4 sm:p-6"><ValueKindColumns :report="r" /></section>

      <section v-if="canApprove" class="card p-4 sm:p-6"><ExperimentCards :experiments="r.experiments ?? []" :currency="r.currency" /></section>

      <section class="card p-4 sm:p-6 flex flex-col gap-3">
        <RoiFunnel :funnel="r.summary.funnel" :can-approve="canApprove" />
        <div>
          <h3 class="text-sm font-semibold m-0 mb-1">{{ t('roi.classification.title') }}</h3>
          <ul class="list-none p-0 m-0 flex flex-wrap gap-2" data-test="classifications">
            <li v-for="c in classifications" :key="c.key" class="badge" :title="r.method.classification[c.key] ?? undefined">{{ t(`roi.classification.${c.key}`) }}<template v-if="classificationTotals">: {{ c.n }}</template></li>
          </ul>
          <p v-if="!classificationTotals" class="m-0 mt-1 text-xs text-fg-muted" data-test="classifications-hidden">{{ t('roi.classification.countsInList') }}</p>
        </div>
      </section>

      <section class="card p-4 sm:p-6"><RoiCosts :costs="r.costs" :currency="r.currency" /></section>

      <section class="card p-4 sm:p-6 flex flex-col gap-3">
        <h2 class="section-title m-0">{{ t('roi.breakdown.title') }}</h2>
        <RoiBreakdown v-if="breakdown" :breakdown="breakdown" :currency="r.currency" />
      </section>

      <section class="card p-4 sm:p-6"><OutcomesTable :filters="listFilters" :currency="r.currency" /></section>

      <section class="card p-4 sm:p-6" data-test="limitations">
        <h2 class="section-title m-0 mb-2">{{ t('roi.limitations') }}</h2>
        <ul class="list-disc pl-5 m-0 text-sm flex flex-col gap-1"><li v-for="(l, i) in r.limitations" :key="i">{{ l }}</li></ul>
      </section>
    </div>
    <InlineNotice v-else-if="report.loaded.value" kind="info">{{ t('roi.empty') }}</InlineNotice>
  </div>
</template>
