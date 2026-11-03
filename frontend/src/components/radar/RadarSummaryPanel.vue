<script setup lang="ts">
/**
 * Resumen del Revenue Radar por horizonte. Cada horizonte se informa DENTRO de sí mismo: nada se suma entre horizontes (las ventanas se solapan y se
 * contaría dos veces la misma compra). Dentro de un horizonte hay dos cosas que NUNCA se suman:
 *  - el valor PRONOSTICADO de las relaciones cliente × producto (oportunidad potencial, atribución no demostrada), y
 *  - la caída OBSERVADA del gasto de los clientes (recuperación y riesgo), que no es pronóstico ni contribución.
 * No muestra cuántas oportunidades se retienen para medición (revelaría el experimento).
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import EnvelopePanel from '@/components/forecast/EnvelopePanel.vue'
import MoneyFigure from '@/components/enterprise/MoneyFigure.vue'
import ProvenanceLine from '@/components/enterprise/ProvenanceLine.vue'
import PartialSumNote from '@/components/enterprise/PartialSumNote.vue'
import type { ResultEnvelope, RadarSummary } from '@/api/types'
import { formatInteger } from '@/utils/format'
import { leftOut } from '@/utils/enterprise'
import { AGENDA_STATES, summaryHorizons } from '@/utils/radar'

const props = defineProps<{ summary: RadarSummary; envelope?: ResultEnvelope | null; message?: string | null }>()
const { t, te } = useI18n()
const horizons = computed(() => summaryHorizons(props.summary))
const uncertainty = computed(() => {
  const source = props.envelope?.uncertainty_source
  return source && te(`envelope.uncertainty.${source}`) ? t(`envelope.uncertainty.${source}`) : t('envelope.uncertainty.unknown')
})
const qualityText = computed(() => {
  const status = props.envelope?.quality_status
  if (!status || status === 'ok') return null
  return te(`envelope.abstention.${status}`) ? t(`envelope.abstention.${status}`) : t('radar.summary.degraded')
})
const currency = computed(() => horizons.value[0]?.data.value.currency ?? props.envelope?.currency ?? null)
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('radar.summary.title')" data-test="radar-summary">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="section-title m-0">{{ t('radar.summary.title') }}</h2>
      <span class="text-sm text-fg-muted" data-test="summary-totals">{{ t('radar.summary.totals', { opportunities: formatInteger(summary.totals.opportunities), customers: formatInteger(summary.totals.customers) }) }}</span>
    </div>
    <ProvenanceLine :as-of="summary.as_of ?? envelope?.as_of" :unit="summary.totals.unit" :currency="currency" :uncertainty="uncertainty" :coverage="summary.run_id ? t('radar.summary.run', { id: summary.run_id }) : null" />
    <InlineNotice v-if="qualityText" kind="warning" data-test="summary-quality">{{ qualityText }}</InlineNotice>
    <InlineNotice v-if="message" kind="info" data-test="summary-message">{{ message }}</InlineNotice>
    <p class="m-0 text-sm text-fg-muted">{{ t('radar.summary.noCrossHorizon') }}</p>

    <div v-if="horizons.length" class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
      <article v-for="h in horizons" :key="h.horizon" class="rounded-xl border border-line p-4 flex flex-col gap-3" data-test="horizon-card" :data-horizon="h.horizon">
        <header class="flex items-baseline justify-between gap-2">
          <h3 class="text-base font-semibold m-0">{{ t('common.days', { n: h.horizon }) }}</h3>
          <span class="text-sm text-fg-muted">{{ t('radar.summary.horizonCounts', { opportunities: formatInteger(h.data.opportunities), customers: formatInteger(h.data.customers) }) }}</span>
        </header>
        <ul class="list-none p-0 m-0 flex flex-wrap gap-1.5">
          <li v-for="s in AGENDA_STATES.filter((x) => (h.data.by_state[x] ?? 0) > 0)" :key="s" class="badge" data-test="state-chip">{{ t(`radar.state.${s}`) }}: {{ h.data.by_state[s] }}</li>
        </ul>

        <div data-test="forecast-block">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-brand-strong m-0">{{ t('radar.summary.forecastTitle') }}</h4>
          <p class="m-0 text-xs text-fg-muted">{{ t('radar.summary.forecastNote') }}</p>
          <dl class="grid grid-cols-2 gap-2 mt-2 mb-0 text-sm">
            <div>
              <dt class="text-fg-muted">{{ t('radar.value.forecastGross') }}</dt>
              <dd class="m-0">
                <span class="font-semibold" data-test="gross"><MoneyFigure :value="h.data.value.gross_value" :currency="h.data.value.currency" /></span>
                <PartialSumNote :n="leftOut(h.data.value.opportunities, h.data.value.gross_value_opportunities)" reason="value" data-test="gross-partial" />
              </dd>
            </div>
            <div>
              <dt class="text-fg-muted">{{ t('radar.value.contribution') }}</dt>
              <dd class="m-0">
                <span class="font-semibold" data-test="contribution"><MoneyFigure :value="h.data.value.contribution" :currency="h.data.value.currency" :unknown-label="t('enterprise.notEstimatedF')" /></span>
                <PartialSumNote :n="h.data.value.contribution_unavailable" reason="cost" data-test="contribution-unavailable" />
              </dd>
            </div>
          </dl>
          <p class="m-0 mt-1 text-xs"><span class="badge badge-warning">{{ t('enterprise.attribution.potential_unproven') }}</span></p>
        </div>

        <div class="border-t border-line pt-3" data-test="observed-block">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-fg-muted m-0">{{ t('radar.summary.customerLevelTitle') }}</h4>
          <p class="m-0 text-xs text-fg-muted">{{ t('radar.summary.customerLevelNote') }}</p>
          <p class="m-0 mt-2 text-sm">
            <span class="text-fg-muted">{{ t('radar.value.observedDecline') }}: </span>
            <span class="font-semibold" data-test="decline"><MoneyFigure :value="h.data.customer_level.observed_spend_decline" :currency="h.data.customer_level.currency" /></span>
            <PartialSumNote :n="leftOut(h.data.customer_level.opportunities, h.data.customer_level.observed_spend_decline_opportunities)" reason="value" data-test="decline-partial" />
          </p>
          <p class="m-0 text-xs text-fg-muted">{{ t('radar.summary.customerLevelCounts', { opportunities: formatInteger(h.data.customer_level.opportunities), customers: formatInteger(h.data.customer_level.customers) }) }}</p>
        </div>

        <p class="m-0 text-xs text-fg-muted border-t border-line pt-2" data-test="inventory-counts">{{ t('radar.summary.inventory', { known: h.data.inventory.known, unknown: h.data.inventory.unknown }) }}</p>
      </article>
    </div>
    <details v-if="envelope" class="rounded-xl border border-line p-3">
      <summary class="cursor-pointer text-sm font-semibold">{{ t('radar.summary.envelope') }}</summary>
      <div class="mt-3"><EnvelopePanel :envelope="envelope" compact /></div>
    </details>
  </section>
</template>
