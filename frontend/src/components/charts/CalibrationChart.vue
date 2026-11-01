<script setup lang="ts">
/**
 * Curva de calibración: probabilidad predicha frente a frecuencia observada por grupo, con el n de cada grupo.
 * Los grupos con n < 30 se marcan como muestra pequeña; si el modelo predice casi lo mismo para todos, se avisa
 * que la curva no distingue compradores. Incluye la tabla equivalente para lectores de pantalla.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import VChart from 'vue-echarts'
import type { EChartsCoreOption } from 'echarts/core'
import './echarts'
import { baseGrid, useChartPalette } from './echarts'
import type { CalibrationBin } from '@/api/types'
import { calibrationAxisMax, calibrationPoints, calibrationSummary, MIN_BIN_N } from '@/utils/calibration'
import { formatInteger, formatPercent } from '@/utils/format'

const props = withDefaults(defineProps<{ bins: CalibrationBin[] | null | undefined; title: string; height?: number }>(), { height: 280 })
const { t } = useI18n()
const palette = useChartPalette()

const points = computed(() => calibrationPoints(props.bins))
const summary = computed(() => calibrationSummary(points.value))
const axisMax = computed(() => calibrationAxisMax(points.value))
const pct = (v: number | null | undefined): string => formatPercent(v, v != null && v < 0.1 ? 2 : 1)

const ariaLabel = computed(() =>
  summary.value.groups === 0
    ? `${props.title}. ${t('calibration.empty')}`
    : `${props.title}. ${t('calibration.aria', { groups: summary.value.groups, n: formatInteger(summary.value.totalN), gap: pct(summary.value.weightedGap) })}`,
)

const option = computed<EChartsCoreOption>(() => {
  const p = palette.value
  const max = axisMax.value
  return {
    animationDuration: 200,
    grid: baseGrid(),
    tooltip: {
      trigger: 'item',
      backgroundColor: p.surface,
      borderColor: p.grid,
      textStyle: { color: p.text },
      formatter: (params: { dataIndex: number; seriesName: string }) => {
        const pt = points.value[params.dataIndex]
        if (!pt || params.seriesName !== t('calibration.observed')) return ''
        return [
          `${t('calibration.predicted')}: <b>${pct(pt.predicted)}</b>`,
          `${t('calibration.observed')}: <b>${pct(pt.observed)}</b>`,
          `n = <b>${formatInteger(pt.n)}</b>${pt.reliable ? '' : ` · ${t('calibration.smallSample')}`}`,
        ].join('<br/>')
      },
    },
    xAxis: { type: 'value', min: 0, max, name: t('calibration.predicted'), nameLocation: 'middle', nameGap: 26, axisLabel: { color: p.muted, formatter: (v: number) => formatPercent(v, 0) }, splitLine: { lineStyle: { color: p.grid } } },
    yAxis: { type: 'value', min: 0, max, name: t('calibration.observed'), axisLabel: { color: p.muted, formatter: (v: number) => formatPercent(v, 0) }, splitLine: { lineStyle: { color: p.grid } } },
    series: [
      { type: 'line', name: t('calibration.perfect'), data: [[0, 0], [max, max]], showSymbol: false, lineStyle: { color: p.muted, type: 'dashed', width: 1.5 }, silent: true, tooltip: { show: false } },
      {
        type: 'scatter',
        name: t('calibration.observed'),
        data: points.value.map((pt) => [pt.predicted, pt.observed]),
        symbolSize: (_: unknown, params: { dataIndex: number }) => 6 + Math.min(14, Math.sqrt((points.value[params.dataIndex]?.n ?? 0) / 400)),
        itemStyle: { color: p.forecast },
      },
    ],
  }
})
</script>

<template>
  <figure class="m-0" data-test="calibration-chart">
    <p v-if="summary.groups === 0" class="text-sm text-fg-muted" data-test="calibration-empty">{{ t('calibration.empty') }}</p>
    <template v-else>
      <div role="img" :aria-label="ariaLabel">
        <VChart :option="option" autoresize :style="{ height: `${height}px`, width: '100%' }" />
      </div>
      <p v-if="summary.flat" class="hint mt-1" data-test="calibration-flat">{{ t('calibration.flat') }}</p>
      <p v-if="summary.smallGroups > 0" class="hint mt-1" data-test="calibration-small">{{ t('calibration.smallGroups', { n: summary.smallGroups, min: MIN_BIN_N }) }}</p>
      <p class="text-sm mt-2" data-test="calibration-summary">{{ t('calibration.summary', { groups: summary.groups, n: formatInteger(summary.totalN), gap: pct(summary.weightedGap) }) }}</p>
      <details class="mt-2 text-sm" open>
        <summary class="cursor-pointer text-fg-muted hover:text-fg">{{ t('calibration.table') }}</summary>
        <div class="table-wrap mt-2">
          <table class="table">
            <caption class="sr-only">{{ title }}</caption>
            <thead>
              <tr>
                <th scope="col" class="num">{{ t('calibration.predicted') }}</th>
                <th scope="col" class="num">{{ t('calibration.observed') }}</th>
                <th scope="col" class="num">{{ t('calibration.range95') }}</th>
                <th scope="col" class="num">n</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(pt, i) in points" :key="i" :data-test="pt.reliable ? 'calibration-row' : 'calibration-row-small'">
                <td class="num">{{ pct(pt.predicted) }}</td>
                <td class="num">{{ pct(pt.observed) }}</td>
                <td class="num">{{ pt.lo != null && pt.hi != null ? `${pct(pt.lo)} – ${pct(pt.hi)}` : '—' }}</td>
                <td class="num">
                  {{ formatInteger(pt.n) }}
                  <span v-if="!pt.reliable" class="badge badge-warning ml-1">{{ t('calibration.smallSample') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </template>
  </figure>
</template>
