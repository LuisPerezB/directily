<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import VChart from 'vue-echarts'
import type { EChartsCoreOption } from 'echarts/core'
import './echarts'
import { baseGrid, formatAxisDate, useChartPalette } from './echarts'
import type { ComparisonPoint } from '@/api/types'
import { buildComparisonChartData, describeComparison } from '@/utils/series'
import { formatDate, formatNumber } from '@/utils/format'

const props = withDefaults(defineProps<{ points: ComparisonPoint[]; title: string; height?: number }>(), { height: 300 })
const { t } = useI18n()
const palette = useChartPalette()
const data = computed(() => buildComparisonChartData(props.points))
const ariaLabel = computed(() => `${props.title}. ${describeComparison(data.value)}`)

const option = computed<EChartsCoreOption>(() => {
  const d = data.value
  const p = palette.value
  const band = { type: 'line', showSymbol: false, lineStyle: { opacity: 0 }, silent: true, tooltip: { show: false }, emphasis: { disabled: true } }
  return {
    grid: baseGrid(),
    legend: { top: 0, left: 0, textStyle: { color: p.muted }, data: [t('chart.actual'), t('chart.forecast'), t('chart.interval80')] },
    tooltip: {
      trigger: 'axis',
      backgroundColor: p.surface,
      borderColor: p.grid,
      textStyle: { color: p.text },
      formatter: (params: { dataIndex: number }[]) => {
        const i = params[0]?.dataIndex ?? 0
        const lo = d.lo80[i]
        const b = d.band80[i]
        return [
          `<strong>${formatDate(d.dates[i] ?? '')}</strong>`,
          `${t('chart.actual')}: <b>${formatNumber(d.actual[i] ?? null, 1)}</b>`,
          `${t('chart.forecast')}: <b>${formatNumber(d.yhat[i] ?? null, 1)}</b>`,
          lo != null && b != null ? `${t('chart.interval80')}: ${formatNumber(lo, 1)} – ${formatNumber(lo + b, 1)}` : '',
        ].filter(Boolean).join('<br/>')
      },
    },
    xAxis: { type: 'category', data: d.dates, boundaryGap: false, axisLabel: { color: p.muted, formatter: (v: string) => formatAxisDate(v, 'day'), hideOverlap: true }, axisLine: { lineStyle: { color: p.grid } } },
    yAxis: { type: 'value', min: 0, axisLabel: { color: p.muted }, splitLine: { lineStyle: { color: p.grid } } },
    series: [
      { ...band, name: 'lo80', data: d.lo80, stack: 'b' },
      { ...band, name: t('chart.interval80'), data: d.band80, stack: 'b', areaStyle: { color: p.band80 }, itemStyle: { color: p.band80 } },
      { type: 'line', name: t('chart.forecast'), data: d.yhat, showSymbol: false, lineStyle: { color: p.forecast, width: 2, type: 'dashed' }, itemStyle: { color: p.forecast } },
      { type: 'line', name: t('chart.actual'), data: d.actual, showSymbol: d.dates.length < 60, symbolSize: 4, lineStyle: { color: p.actual, width: 2 }, itemStyle: { color: p.actual } },
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
  </figure>
</template>
