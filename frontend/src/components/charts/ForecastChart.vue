<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import VChart from 'vue-echarts'
import type { EChartsCoreOption } from 'echarts/core'
import './echarts'
import { baseGrid, formatAxisDate, useChartPalette } from './echarts'
import type { ForecastPoint, Granularity, HistoryPoint } from '@/api/types'
import { buildForecastChartData, describeSeries, type Metric } from '@/utils/series'
import { formatCurrency, formatNumber, formatDate } from '@/utils/format'

const props = withDefaults(
  defineProps<{
    history: HistoryPoint[]
    forecast: ForecastPoint[]
    metric?: Metric
    granularity?: Granularity
    currency?: string
    height?: number
    title: string
    showTable?: boolean
    /** El horizonte no tiene respaldo suficiente: se dibuja como provisional (línea punteada fina, sin bandas ni mediana). */
    provisional?: boolean
  }>(),
  { metric: 'units', granularity: 'day', currency: 'USD', height: 340, showTable: true, provisional: false },
)
const { t } = useI18n()
const palette = useChartPalette()

const built = computed(() => buildForecastChartData(props.history, props.forecast, props.metric))
// Sin respaldo suficiente no se dibujan bandas de incertidumbre ni mediana: serían una precisión que no existe.
const data = computed(() => (props.provisional ? { ...built.value, hasIntervals: false, hasP50: false } : built.value))
const forecastLabel = computed(() => (props.provisional ? t('chart.forecastProvisional') : t('chart.forecast')))
const unitLabel = computed(() => (props.metric === 'units' ? t('chart.units') : t('chart.dollars')))
const ariaLabel = computed(() => `${props.title}. ${describeSeries(data.value, unitLabel.value)}`)

function fmt(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—'
  return props.metric === 'revenue' ? formatCurrency(v, props.currency, 0) : formatNumber(v, 1)
}

const option = computed<EChartsCoreOption>(() => {
  const d = data.value
  const p = palette.value
  const cutoffDate = d.cutoffIndex >= 0 ? d.dates[d.cutoffIndex] : undefined
  const stockoutPoints = d.history.map((v, i) => (d.stockout[i] && v !== null ? v : null))
  const lineBase = { type: 'line', showSymbol: false, symbol: 'none', connectNulls: false }
  const bandBase = { ...lineBase, lineStyle: { opacity: 0 }, emphasis: { disabled: true }, silent: true, tooltip: { show: false } }

  return {
    animationDuration: 300,
    grid: baseGrid(),
    textStyle: { color: p.text, fontFamily: 'inherit' },
    legend: {
      top: 0,
      left: 0,
      itemWidth: 14,
      textStyle: { color: p.muted },
      data: [
        ...(d.history.some((v) => v !== null) ? [t('chart.history')] : []),
        ...(d.yhat.some((v) => v !== null) ? [forecastLabel.value] : []),
        ...(d.hasP50 ? [t('chart.median')] : []),
        ...(d.hasIntervals ? [t('chart.interval80'), t('chart.interval95')] : []),
        ...(stockoutPoints.some((v) => v !== null) ? [t('chart.stockout')] : []),
      ],
    },
    tooltip: {
      trigger: 'axis',
      backgroundColor: p.surface,
      borderColor: p.grid,
      textStyle: { color: p.text },
      formatter: (params: { dataIndex: number }[]) => {
        const i = params[0]?.dataIndex ?? 0
        const lines = [`<strong>${formatDate(d.dates[i] ?? '')}</strong>`]
        if (d.history[i] !== null) lines.push(`${t('chart.history')}: <b>${fmt(d.history[i])}</b>${d.stockout[i] ? ` · ${t('chart.stockout')}` : ''}`)
        if (d.yhat[i] !== null) {
          lines.push(`${forecastLabel.value}: <b>${fmt(d.yhat[i])}</b>`)
          if (d.p50[i] != null) lines.push(`${t('chart.median')}: <b>${fmt(d.p50[i])}</b>`)
          const lo80 = d.lo80[i]
          const b80 = d.band80[i]
          if (lo80 != null && b80 != null) lines.push(`${t('chart.interval80')}: ${fmt(lo80)} – ${fmt(lo80 + b80)}`)
          const lo95 = d.lo95[i]
          const b95 = d.band95[i]
          if (lo95 != null && b95 != null) lines.push(`${t('chart.interval95')}: ${fmt(lo95)} – ${fmt(lo95 + b95)}`)
        }
        return lines.join('<br/>')
      },
    },
    xAxis: {
      type: 'category',
      data: d.dates,
      boundaryGap: false,
      axisLabel: { color: p.muted, formatter: (v: string) => formatAxisDate(v, props.granularity), hideOverlap: true },
      axisLine: { lineStyle: { color: p.grid } },
      axisTick: { show: false },
    },
    yAxis: {
      type: 'value',
      min: 0,
      axisLabel: { color: p.muted, formatter: (v: number) => (props.metric === 'revenue' ? formatCurrency(v, props.currency, 0) : formatNumber(v, 0)) },
      splitLine: { lineStyle: { color: p.grid } },
    },
    dataZoom: d.dates.length > 120 ? [{ type: 'inside', start: Math.max(0, 100 - (180 / d.dates.length) * 100), end: 100 }] : [],
    series: [
      ...(d.hasIntervals
        ? [
            { ...bandBase, name: 'lo95', data: d.lo95, stack: 'b95' },
            { ...bandBase, name: t('chart.interval95'), data: d.band95, stack: 'b95', areaStyle: { color: p.band95 }, itemStyle: { color: p.band95 } },
            { ...bandBase, name: 'lo80', data: d.lo80, stack: 'b80' },
            { ...bandBase, name: t('chart.interval80'), data: d.band80, stack: 'b80', areaStyle: { color: p.band80 }, itemStyle: { color: p.band80 } },
          ]
        : []),
      {
        ...lineBase,
        name: t('chart.history'),
        data: d.history,
        lineStyle: { color: p.history, width: 2 },
        itemStyle: { color: p.history },
        markLine: cutoffDate
          ? {
              silent: true,
              symbol: 'none',
              label: { formatter: t('chart.cutoff'), color: p.muted, position: 'insideEndTop' },
              lineStyle: { color: p.muted, type: 'dashed' },
              data: [{ xAxis: cutoffDate }],
            }
          : undefined,
      },
      {
        ...lineBase,
        name: forecastLabel.value,
        data: d.yhat,
        lineStyle: props.provisional
          ? { color: p.muted, width: 1.5, type: 'dotted' }
          : { color: p.forecast, width: 2.5, type: 'dashed' },
        itemStyle: { color: props.provisional ? p.muted : p.forecast },
      },
      ...(d.hasP50
        ? [{ ...lineBase, name: t('chart.median'), data: d.p50, lineStyle: { color: p.violet, width: 2, type: 'solid' }, itemStyle: { color: p.violet } }]
        : []),
      {
        type: 'scatter',
        name: t('chart.stockout'),
        data: stockoutPoints,
        symbolSize: 7,
        itemStyle: { color: p.danger },
        tooltip: { show: false },
      },
    ],
  }
})
</script>

<template>
  <figure class="m-0">
    <div role="img" :aria-label="ariaLabel">
      <VChart :option="option" autoresize :style="{ height: `${height}px`, width: '100%' }" />
    </div>
    <figcaption class="sr-only">{{ ariaLabel }}</figcaption>
    <details v-if="showTable" class="mt-2 text-sm">
      <summary class="cursor-pointer text-fg-muted hover:text-fg">{{ t('chart.viewTable') }}</summary>
      <div class="table-wrap max-h-72 overflow-y-auto mt-2">
        <table class="table">
          <caption class="sr-only">{{ title }}</caption>
          <thead>
            <tr>
              <th scope="col">{{ t('chart.date') }}</th>
              <th scope="col" class="num">{{ t('chart.history') }}</th>
              <th scope="col" class="num">{{ forecastLabel }}</th>
              <th v-if="data.hasP50" scope="col" class="num">{{ t('chart.median') }}</th>
              <th v-if="data.hasIntervals" scope="col" class="num">{{ t('chart.interval80') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(date, i) in data.dates" :key="date">
              <td>{{ formatDate(date) }}</td>
              <td class="num">{{ fmt(data.history[i]) }}</td>
              <td class="num">{{ fmt(data.yhat[i]) }}</td>
              <td v-if="data.hasP50" class="num">{{ fmt(data.p50[i]) }}</td>
              <td v-if="data.hasIntervals" class="num">
                <template v-if="data.lo80[i] != null && data.band80[i] != null">{{ fmt(data.lo80[i]) }} – {{ fmt((data.lo80[i] ?? 0) + (data.band80[i] ?? 0)) }}</template>
                <template v-else>—</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>
  </figure>
</template>
