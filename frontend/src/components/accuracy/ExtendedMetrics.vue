<script setup lang="ts">
/**
 * Métricas de la etapa 2 del modelo seleccionado: MASE/RMSSE (con la nota de lectura), pinball de P10/P50/P90,
 * amplitud de los intervalos, dinero separado de unidades y exclusiones de la muestra. Un valor ausente se muestra
 * como "no disponible" con su motivo, nunca como cero.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import KpiCard from '@/components/ui/KpiCard.vue'
import InlineNotice from '@/components/ui/InlineNotice.vue'
import type { MetricSet, P50Decision } from '@/api/types'
import { formatChange, formatCurrency, formatInteger, formatNumber, formatPercent } from '@/utils/format'

const props = defineProps<{ metrics: MetricSet | null | undefined; baseline?: MetricSet | null; p50?: P50Decision | null; independentHoldout: boolean }>()
const { t, te } = useI18n()

const exclusions = computed(() => Object.entries(props.metrics?.sample?.exclusions ?? {}).filter(([, n]) => n > 0))
const hasScaled = computed(() => props.metrics?.mase != null || props.metrics?.rmsse != null)
const pinballAvailable = computed(() => props.metrics?.pinball?.p10 != null || props.metrics?.pinball?.p90 != null)
const money = computed(() => props.metrics?.money ?? null)
const scaleHint = (v: number | null | undefined) => (v == null ? t('extended.notAvailable') : v < 1 ? t('extended.betterThanNaive') : t('extended.worseThanNaive'))
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="t('extended.title')" data-test="extended-metrics">
    <p v-if="!metrics || (!hasScaled && !pinballAvailable && !money)" class="text-sm text-fg-muted m-0" data-test="extended-missing">{{ t('extended.missing') }}</p>
    <template v-else>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <KpiCard :label="t('extended.mase')" :value="metrics.mase != null ? formatNumber(metrics.mase, 2) : t('extended.notAvailable')" :hint="scaleHint(metrics.mase)" icon="target" />
        <KpiCard :label="t('extended.rmsse')" :value="metrics.rmsse != null ? formatNumber(metrics.rmsse, 2) : t('extended.notAvailable')" :hint="scaleHint(metrics.rmsse)" icon="target" />
        <KpiCard :label="t('extended.width80')" :value="metrics.interval_width?.['80'] != null ? formatNumber(metrics.interval_width['80'], 1) : t('extended.notAvailable')" :hint="t('extended.widthHint')" icon="sliders" />
        <KpiCard :label="t('extended.width95')" :value="metrics.interval_width?.['95'] != null ? formatNumber(metrics.interval_width['95'], 1) : t('extended.notAvailable')" :hint="t('extended.widthHint')" icon="sliders" />
      </div>
      <p class="hint m-0" data-test="mase-note">{{ t('extended.maseNote') }}</p>

      <div>
        <h3 class="text-sm font-semibold m-0 mb-1">{{ t('extended.pinballTitle') }}</h3>
        <InlineNotice v-if="!independentHoldout" kind="warning">{{ t('extended.pinballNeedsHoldout') }}</InlineNotice>
        <div class="table-wrap mt-2">
          <table class="table">
            <caption class="sr-only">{{ t('extended.pinballTitle') }}</caption>
            <thead><tr><th scope="col">{{ t('extended.quantile') }}</th><th scope="col" class="num">{{ t('extended.pinball') }}</th><th scope="col">{{ t('extended.meaning') }}</th></tr></thead>
            <tbody>
              <tr><td>P10</td><td class="num">{{ metrics.pinball?.p10 != null ? formatNumber(metrics.pinball.p10, 3) : '—' }}</td><td>{{ t('extended.p10Meaning') }}</td></tr>
              <tr data-test="p50-row">
                <td>P50</td>
                <td class="num">{{ metrics.pinball?.p50 != null ? formatNumber(metrics.pinball.p50, 3) : '—' }}</td>
                <td>{{ metrics.pinball?.p50 != null ? t('extended.p50Meaning') : t('extended.p50NotPublished') }}</td>
              </tr>
              <tr><td>P90</td><td class="num">{{ metrics.pinball?.p90 != null ? formatNumber(metrics.pinball.p90, 3) : '—' }}</td><td>{{ t('extended.p90Meaning') }}</td></tr>
            </tbody>
          </table>
        </div>
        <p v-if="p50 && !p50.published" class="hint mt-1" data-test="p50-decision">{{ t('extended.p50Decision', { reason: p50.reason ?? '—' }) }}</p>
      </div>

      <div v-if="money" data-test="money-metrics">
        <h3 class="text-sm font-semibold m-0 mb-1">{{ t('extended.moneyTitle', { currency: money.currency }) }}</h3>
        <p class="hint m-0 mb-2">{{ t('extended.moneyNote') }}</p>
        <div class="table-wrap">
          <table class="table">
            <caption class="sr-only">{{ t('extended.moneyTitle', { currency: money.currency }) }}</caption>
            <thead><tr><th scope="col">{{ t('extended.measure') }}</th><th scope="col" class="num">{{ t('extended.inUnits') }}</th><th scope="col" class="num">{{ t('extended.inMoney') }}</th></tr></thead>
            <tbody>
              <tr><td>WAPE</td><td class="num">{{ formatPercent(metrics.wape) }}</td><td class="num">{{ formatPercent(money.wape) }}</td></tr>
              <tr><td>{{ t('accuracy.bias') }}</td><td class="num">{{ formatChange(metrics.bias) }}</td><td class="num">{{ formatChange(money.bias) }}</td></tr>
              <tr><td>{{ t('extended.actualTotal') }}</td><td class="num">—</td><td class="num">{{ formatCurrency(money.actual_total, money.currency, 0) }}</td></tr>
              <tr><td>{{ t('extended.forecastTotal') }}</td><td class="num">—</td><td class="num">{{ formatCurrency(money.forecast_total, money.currency, 0) }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="metrics.sample" data-test="sample-info">
        <p class="text-sm m-0">{{ t('extended.sample', { obs: formatInteger(metrics.sample.n_obs), series: formatInteger(metrics.sample.n_series ?? null), windows: formatInteger(metrics.sample.n_windows ?? null) }) }}</p>
        <ul v-if="exclusions.length" class="list-disc pl-5 mt-1 mb-0 text-sm text-fg-muted">
          <li v-for="[key, n] in exclusions" :key="key">{{ te(`extended.exclusions.${key}`) ? t(`extended.exclusions.${key}`, { n: formatInteger(n) }) : `${key}: ${formatInteger(n)}` }}</li>
        </ul>
      </div>
    </template>
  </section>
</template>
