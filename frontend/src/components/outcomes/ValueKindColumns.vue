<script setup lang="ts">
/**
 * Cuatro tipos de valor, cada uno en su propia columna y SIN total que los combine (V25-UIX-006): oportunidad potencial (atribución no demostrada), lo observado,
 * lo asociado temporalmente a una acción (no es beneficio atribuible) y el incremento estimado por intención de tratamiento, solo si existe.
 * Sumar potencial + observado + asociado contaría dos veces la misma venta. La oportunidad potencial va POR TIPO y por HORIZONTE: no existe un total entre tipos
 * (contacto, reposición, presupuesto… miden cosas distintas) ni entre horizontes (los mismos clientes), y los tipos de nivel de cliente (recuperación, riesgo) se
 * rotulan aparte. Lo observado y lo asociado ya vienen sin contar dos veces una misma línea de venta; la suma por recomendación es un detalle NO aditivo.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import type { OutcomeRoiReport } from '@/api/types'
import { formatInteger, formatNumber } from '@/utils/format'
import { valueKinds } from '@/utils/outcomes'

const props = defineProps<{ report: OutcomeRoiReport }>()
const { t, te } = useI18n()
const kinds = computed(() => valueKinds(props.report))
const currency = computed(() => props.report.currency)
</script>

<template>
  <section class="flex flex-col gap-3" :aria-label="t('roi.columns.title')" data-test="value-kinds">
    <h2 class="section-title m-0">{{ t('roi.columns.title') }}</h2>
    <p class="m-0 text-sm text-fg-muted">{{ t('roi.columns.noTotal') }}</p>
    <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-[1.5fr_1fr_1fr_1fr]">
      <article class="rounded-xl border border-line p-4 flex flex-col gap-3" data-test="col-potential">
        <h3 class="text-sm font-semibold m-0">{{ t('roi.columns.potential') }}</h3>
        <p class="m-0 text-xs text-fg-muted">{{ t('enterprise.attribution.potential_unproven') }}</p>
        <p class="m-0 text-xs font-semibold" data-test="potential-per-kind">{{ t('roi.columns.perKind') }}</p>
        <p v-if="kinds.potential === null" class="m-0 text-sm" data-test="potential-unavailable">{{ t('roi.columns.potentialUnavailable') }}</p>
        <p v-else-if="!kinds.potential.length" class="m-0 text-sm" data-test="potential-none">{{ t('roi.columns.potentialNone') }}</p>
        <ul v-else class="list-none p-0 m-0 flex flex-col gap-3">
          <li v-for="p in kinds.potential" :key="p.kind" class="rounded-lg bg-surface-2 p-3 flex flex-col gap-2" data-test="potential-kind" :data-kind="p.kind">
            <p class="m-0 text-sm font-semibold">{{ te(`decisions.kind.${p.kind}`) ? t(`decisions.kind.${p.kind}`) : p.kind }}</p>
            <p v-if="p.grossBasis === 'customer_level'" class="m-0 text-xs text-fg-muted" data-test="potential-customer-level">{{ t('roi.columns.customerLevelNote') }}</p>
            <ul class="list-none p-0 m-0 flex flex-col gap-2">
              <li v-for="c in p.cells" :key="c.horizonDays" class="flex flex-col gap-0.5 border-t border-line pt-2 first:border-t-0 first:pt-0" data-test="potential-cell" :data-horizon="c.horizonDays">
                <p class="m-0 text-xs font-semibold">{{ t('roi.columns.horizonCell', { days: c.horizonDays, n: formatInteger(c.recommendations) }) }}</p>
                <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.potentialContribution') }}</p>
                <p class="m-0 text-lg font-bold" data-test="potential-contribution"><MoneyFigure :value="c.contribution" :currency="currency" /></p>
                <p v-if="c.withoutReliableCost > 0" class="m-0 text-xs text-fg-muted" data-test="potential-unknown">{{ t('roi.columns.withoutReliableCost', { n: formatInteger(c.withoutReliableCost) }) }}</p>
                <p class="m-0 text-xs text-fg-muted" data-test="potential-gross" :data-basis="p.grossBasis">
                  {{ p.grossBasis === 'forecast' ? t('roi.columns.grossValue') : t('roi.columns.customerLevelAmount') }}: <MoneyFigure :value="c.grossValue" :currency="currency" />
                </p>
              </li>
            </ul>
          </li>
        </ul>
        <p class="m-0 text-xs text-fg-muted" data-test="potential-not-additive">{{ t('roi.columns.potentialNotAdditive') }}</p>
      </article>

      <article class="rounded-xl border border-line p-4 flex flex-col gap-2" data-test="col-observed">
        <h3 class="text-sm font-semibold m-0">{{ t('roi.columns.observed') }}</h3>
        <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.observedNote') }}</p>
        <p class="m-0 text-xs text-fg-muted" data-test="observed-not-additive">{{ t('roi.columns.notAdditive') }}</p>
        <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.contributionKnown') }}</p>
        <p class="m-0 text-xl font-bold" data-test="observed-contribution"><MoneyFigure :value="kinds.observed.contributionKnown" :currency="currency" /></p>
        <p v-if="kinds.observed.pairsUnknown > 0" class="m-0 text-xs text-fg-muted" data-test="observed-unknown">{{ t('roi.columns.pairsUnknown', { n: formatInteger(kinds.observed.pairsUnknown) }) }}</p>
        <p class="m-0 text-xs text-fg-muted" data-test="observed-counts">{{ t('roi.columns.observedCounts', { customers: formatInteger(kinds.observed.customers), lines: formatInteger(kinds.observed.purchaseLines), units: formatNumber(kinds.observed.units, 1) }) }}</p>
        <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.netSales') }}: <MoneyFigure :value="kinds.observed.netAmount" :currency="currency" /></p>
        <p v-if="kinds.observed.rowsWithoutLineDetail > 0" class="m-0 text-xs text-warning" data-test="observed-no-detail">{{ t('roi.columns.rowsWithoutLineDetail', { n: formatInteger(kinds.observed.rowsWithoutLineDetail) }) }}</p>
        <details v-if="kinds.observed.perRecommendation" class="text-xs text-fg-muted" data-test="observed-per-rec">
          <summary class="cursor-pointer font-semibold">{{ t('roi.columns.perRecommendation') }}</summary>
          <p class="m-0 mt-1">{{ t('roi.columns.perRecommendationNote') }}</p>
          <p class="m-0 mt-1">{{ t('roi.columns.perRecommendationCounts', { rows: formatInteger(kinds.observed.perRecommendation.rows), units: formatNumber(kinds.observed.perRecommendation.units, 1) }) }}</p>
          <p class="m-0">{{ t('roi.columns.netSales') }}: <MoneyFigure :value="kinds.observed.perRecommendation.netAmount" :currency="currency" /></p>
          <p class="m-0">{{ t('roi.columns.contributionKnown') }}: <MoneyFigure :value="kinds.observed.perRecommendation.contributionKnown" :currency="currency" /></p>
        </details>
      </article>

      <article class="rounded-xl border border-line p-4 flex flex-col gap-2" data-test="col-associated">
        <h3 class="text-sm font-semibold m-0">{{ t('roi.columns.associated') }}</h3>
        <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.associatedNote') }}</p>
        <p class="m-0 text-xs text-fg-muted" data-test="associated-not-additive">{{ t('roi.columns.associatedNotAdditive') }}</p>
        <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.contributionKnown') }}</p>
        <p class="m-0 text-xl font-bold" data-test="associated-contribution"><MoneyFigure :value="kinds.associated.contributionKnown" :currency="currency" /></p>
        <p v-if="kinds.associated.pairsUnknown > 0" class="m-0 text-xs text-fg-muted" data-test="associated-unknown">{{ t('roi.columns.pairsUnknown', { n: formatInteger(kinds.associated.pairsUnknown) }) }}</p>
        <p class="m-0 text-xs text-fg-muted" data-test="associated-counts">{{ t('roi.columns.associatedCounts', { recs: formatInteger(kinds.associated.recommendations), customers: formatInteger(kinds.associated.customers), units: formatNumber(kinds.associated.units, 1) }) }}</p>
        <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.netSalesAfter') }}: <MoneyFigure :value="kinds.associated.netAmount" :currency="currency" /></p>
        <p v-if="kinds.associated.rowsWithoutLineDetail > 0" class="m-0 text-xs text-warning" data-test="associated-no-detail">{{ t('roi.columns.rowsWithoutLineDetail', { n: formatInteger(kinds.associated.rowsWithoutLineDetail) }) }}</p>
        <details v-if="kinds.associated.perRecommendation" class="text-xs text-fg-muted" data-test="associated-per-rec">
          <summary class="cursor-pointer font-semibold">{{ t('roi.columns.perRecommendation') }}</summary>
          <p class="m-0 mt-1">{{ t('roi.columns.perRecommendationNote') }}</p>
          <p class="m-0 mt-1">{{ t('roi.columns.perRecommendationCounts', { rows: formatInteger(kinds.associated.perRecommendation.rows), units: formatNumber(kinds.associated.perRecommendation.units, 1) }) }}</p>
          <p class="m-0">{{ t('roi.columns.netSalesAfter') }}: <MoneyFigure :value="kinds.associated.perRecommendation.netAmount" :currency="currency" /></p>
          <p class="m-0">{{ t('roi.columns.contributionKnown') }}: <MoneyFigure :value="kinds.associated.perRecommendation.contributionKnown" :currency="currency" /></p>
        </details>
      </article>

      <article class="rounded-xl border border-line p-4 flex flex-col gap-2" data-test="col-incremental" :data-status="kinds.incrementalStatus">
        <h3 class="text-sm font-semibold m-0">{{ t('roi.columns.incremental') }}</h3>
        <template v-if="kinds.incremental">
          <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.incrementalNote') }}</p>
          <p class="m-0 text-xl font-bold"><MoneyFigure :value="kinds.incremental.contribution" :currency="currency" /></p>
          <p class="m-0 text-xs text-fg-muted" data-test="incremental-interval">{{ t('roi.columns.incrementalInterval', { low: formatNumber(kinds.incremental.ciLow, 2), high: formatNumber(kinds.incremental.ciHigh, 2) }) }}</p>
          <p v-if="kinds.heavyTailed" class="m-0 text-xs text-warning" data-test="incremental-heavy-tail">{{ t('roi.heavyTail') }}</p>
        </template>
        <template v-else-if="kinds.incrementalStatus === 'incremental_conversion_only'">
          <p class="m-0 text-sm font-medium" data-test="incremental-conversion-only">{{ t('roi.columns.incrementalConversionOnly') }}</p>
          <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.incrementalConversionOnlyHint') }}</p>
          <p v-if="kinds.heavyTailed" class="m-0 text-xs text-warning" data-test="incremental-heavy-tail">{{ t('roi.heavyTail') }}</p>
        </template>
        <template v-else-if="kinds.incrementalStatus === 'multiple_experiments'">
          <p class="m-0 text-sm font-medium" data-test="incremental-multiple">{{ t('roi.columns.incrementalMultiple') }}</p>
          <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.incrementalMultipleHint') }}</p>
        </template>
        <template v-else-if="kinds.incrementalStatus === 'incremental_estimated'">
          <p class="m-0 text-sm font-medium" data-test="incremental-restricted">{{ t('roi.columns.incrementalRestricted') }}</p>
        </template>
        <template v-else>
          <p class="m-0 text-sm font-medium" data-test="incremental-none">{{ t('roi.columns.incrementalNone') }}</p>
          <p class="m-0 text-xs text-fg-muted">{{ t('roi.columns.incrementalNoneHint') }}</p>
        </template>
      </article>
    </div>
  </section>
</template>
