/**
 * Registro modular de ECharts (solo lo que se usa, para reducir el tamaño del bundle).
 */
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart, LineChart, ScatterChart } from 'echarts/charts'
import { DataZoomComponent, GridComponent, LegendComponent, MarkLineComponent, TooltipComponent } from 'echarts/components'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatMonth } from '@/utils/format'
import type { Granularity } from '@/api/types'

use([CanvasRenderer, LineChart, BarChart, ScatterChart, GridComponent, TooltipComponent, LegendComponent, MarkLineComponent, DataZoomComponent])

export function cssVar(name: string, fallback = '#888'): string {
  if (typeof document === 'undefined') return fallback
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()
  return value || fallback
}

export interface ChartPalette {
  history: string
  forecast: string
  band80: string
  band95: string
  actual: string
  grid: string
  text: string
  muted: string
  surface: string
  danger: string
  violet: string
  accent: string
}

export function readPalette(): ChartPalette {
  return {
    history: cssVar('--p-chart-history', '#3b4b63'),
    forecast: cssVar('--p-chart-forecast', '#0b7a83'),
    band80: cssVar('--p-chart-band80', 'rgba(11,122,131,.22)'),
    band95: cssVar('--p-chart-band95', 'rgba(11,122,131,.1)'),
    actual: cssVar('--p-chart-actual', '#d9822b'),
    grid: cssVar('--p-chart-grid', '#e3e8ee'),
    text: cssVar('--p-fg', '#0f1b2d'),
    muted: cssVar('--p-fg-muted', '#4a5a70'),
    surface: cssVar('--p-surface', '#ffffff'),
    danger: cssVar('--p-danger', '#b83227'),
    violet: cssVar('--p-violet', '#6d5bd0'),
    accent: cssVar('--p-accent', '#d9822b'),
  }
}

/** Devuelve la paleta y la recalcula cuando cambia el tema (preferencia o sistema). */
export function useChartPalette() {
  const palette = ref<ChartPalette>(readPalette())
  const ui = useUiStore()
  const refresh = () => {
    // Espera a que el atributo data-theme se aplique.
    requestAnimationFrame(() => (palette.value = readPalette()))
  }
  let media: MediaQueryList | null = null
  onMounted(() => {
    media = globalThis.matchMedia?.('(prefers-color-scheme: dark)') ?? null
    media?.addEventListener?.('change', refresh)
    refresh()
  })
  onBeforeUnmount(() => media?.removeEventListener?.('change', refresh))
  watch(() => ui.theme, refresh)
  return palette
}

export function formatAxisDate(value: string, granularity: Granularity): string {
  if (granularity === 'month') return formatMonth(value)
  return formatDate(value, 'short')
}

export function baseGrid() {
  return { left: 8, right: 16, top: 40, bottom: 8, containLabel: true }
}
