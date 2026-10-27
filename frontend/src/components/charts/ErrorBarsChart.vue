<script setup lang="ts">
/** Barras agrupadas de error (WAPE) del modelo seleccionado vs. el método de referencia. */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import VChart from 'vue-echarts'
import type { EChartsCoreOption } from 'echarts/core'
import './echarts'
import { baseGrid, useChartPalette } from './echarts'
import { formatPercent } from '@/utils/format'

const props = withDefaults(
  defineProps<{ categories: string[]; selected: (number | null)[]; baseline: (number | null)[]; title: string; height?: number }>(),
  { height: 260 },
)
const { t } = useI18n()
const palette = useChartPalette()

const ariaLabel = computed(() =>
  `${props.title}. ` +
  props.categories
    .map((c, i) => `${c}: ${t('accuracy.selectedModel')} ${formatPercent(props.selected[i] ?? null)}, ${t('accuracy.baseline')} ${formatPercent(props.baseline[i] ?? null)}`)
    .join('; '),
)

const option = computed<EChartsCoreOption>(() => {
  const p = palette.value
  return {
    grid: baseGrid(),
    legend: { top: 0, left: 0, textStyle: { color: p.muted } },
    tooltip: { trigger: 'axis', valueFormatter: (v: number) => formatPercent(v), backgroundColor: p.surface, borderColor: p.grid, textStyle: { color: p.text } },
    xAxis: { type: 'category', data: props.categories, axisLabel: { color: p.muted }, axisLine: { lineStyle: { color: p.grid } } },
    yAxis: { type: 'value', min: 0, axisLabel: { color: p.muted, formatter: (v: number) => formatPercent(v, 0) }, splitLine: { lineStyle: { color: p.grid } } },
    series: [
      { type: 'bar', name: t('accuracy.selectedModel'), data: props.selected, itemStyle: { color: p.forecast, borderRadius: [4, 4, 0, 0] }, barMaxWidth: 36 },
      { type: 'bar', name: t('accuracy.baseline'), data: props.baseline, itemStyle: { color: p.history, borderRadius: [4, 4, 0, 0] }, barMaxWidth: 36 },
    ],
  }
})
</script>

<template>
  <figure class="m-0">
    <div role="img" :aria-label="ariaLabel">
      <VChart :option="option" autoresize :style="{ height: `${height}px`, width: '100%' }" />
    </div>
  </figure>
</template>
