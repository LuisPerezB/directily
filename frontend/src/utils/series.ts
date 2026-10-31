import type { ComparisonPoint, ForecastPoint, HistoryPoint } from '@/api/types'
import { formatNumber, formatPercent } from './format'

/**
 * Transformaciones de series para gráficos (independientes de ECharts para poder probarlas).
 * Las bandas de intervalo se dibujan como áreas apiladas: base = límite inferior,
 * banda = superior − inferior.
 */

export type Metric = 'units' | 'revenue'
type Num = number | null

export interface ForecastChartData {
  dates: string[]
  history: Num[]
  stockout: boolean[]
  yhat: Num[]
  /** Mediana (P50): solo se dibuja si el motor la publicó (algún valor no nulo). */
  p50: Num[]
  lo80: Num[]
  band80: Num[]
  lo95: Num[]
  band95: Num[]
  /** Índice del último punto histórico (−1 si no hay histórico). */
  cutoffIndex: number
  hasIntervals: boolean
  hasP50: boolean
}

function bandWidth(lo: Num, hi: Num): Num {
  if (lo === null || hi === null) return null
  return Math.max(0, hi - lo)
}

export function buildForecastChartData(history: HistoryPoint[], forecast: ForecastPoint[], metric: Metric = 'units'): ForecastChartData {
  const sortedHistory = [...history].sort((a, b) => a.date.localeCompare(b.date))
  const lastHistoryDate = sortedHistory.at(-1)?.date ?? ''
  // Se ignoran puntos de pronóstico que se solapen con el histórico.
  const sortedForecast = [...forecast].filter((p) => p.date > lastHistoryDate).sort((a, b) => a.date.localeCompare(b.date))

  const dates: string[] = []
  const out: ForecastChartData = {
    dates,
    history: [],
    stockout: [],
    yhat: [],
    p50: [],
    lo80: [],
    band80: [],
    lo95: [],
    band95: [],
    cutoffIndex: sortedHistory.length - 1,
    hasIntervals: false,
    hasP50: false,
  }

  for (const h of sortedHistory) {
    dates.push(h.date)
    out.history.push(metric === 'units' ? h.units : h.revenue)
    out.stockout.push(Boolean(h.stockout))
    out.yhat.push(null)
    out.p50.push(null)
    out.lo80.push(null)
    out.band80.push(null)
    out.lo95.push(null)
    out.band95.push(null)
  }

  const useIntervals = metric === 'units'
  for (const f of sortedForecast) {
    dates.push(f.date)
    out.history.push(null)
    out.stockout.push(false)
    out.yhat.push(metric === 'units' ? f.yhat : f.revenue)
    // La mediana solo existe en unidades y cuando el motor la publicó; nunca se sustituye por yhat.
    const p50 = metric === 'units' && typeof f.p50 === 'number' ? f.p50 : null
    out.p50.push(p50)
    if (p50 !== null) out.hasP50 = true
    const lo80 = useIntervals ? f.lo_80 : null
    const hi80 = useIntervals ? f.hi_80 : null
    const lo95 = useIntervals ? f.lo_95 : null
    const hi95 = useIntervals ? f.hi_95 : null
    out.lo80.push(lo80)
    out.band80.push(bandWidth(lo80, hi80))
    out.lo95.push(lo95)
    out.band95.push(bandWidth(lo95, hi95))
    if (lo80 !== null && hi80 !== null) out.hasIntervals = true
  }
  return out
}

function sum(values: Num[]): number {
  return values.reduce<number>((acc, v) => acc + (v ?? 0), 0)
}

export interface SeriesSummary {
  historyPoints: number
  historyTotal: number
  historyAverage: number | null
  forecastPoints: number
  forecastTotal: number
  forecastLo80Total: number | null
  forecastHi80Total: number | null
  stockoutPoints: number
}

/**
 * Resumen numérico. Ojo: la suma de límites diarios NO es un intervalo válido del total;
 * por eso aquí solo se informa el total central y el intervalo se toma de la API.
 */
export function summarizeSeries(data: ForecastChartData): SeriesSummary {
  const historyValues = data.history.filter((v): v is number => v !== null)
  const forecastValues = data.yhat.filter((v): v is number => v !== null)
  return {
    historyPoints: historyValues.length,
    historyTotal: sum(historyValues),
    historyAverage: historyValues.length ? sum(historyValues) / historyValues.length : null,
    forecastPoints: forecastValues.length,
    forecastTotal: sum(forecastValues),
    forecastLo80Total: null,
    forecastHi80Total: null,
    stockoutPoints: data.stockout.filter(Boolean).length,
  }
}

/** Texto alternativo para lectores de pantalla. */
export function describeSeries(data: ForecastChartData, unitLabel: string): string {
  const s = summarizeSeries(data)
  const parts: string[] = []
  if (s.historyPoints) {
    parts.push(`Histórico: ${s.historyPoints} períodos, total ${formatNumber(s.historyTotal, 0)} ${unitLabel}, promedio ${formatNumber(s.historyAverage, 1)} por período.`)
  } else {
    parts.push('Sin histórico de ventas para este filtro.')
  }
  if (s.forecastPoints) {
    parts.push(`Pronóstico: ${s.forecastPoints} períodos, total estimado ${formatNumber(s.forecastTotal, 0)} ${unitLabel}.`)
  } else {
    parts.push('Sin pronóstico disponible.')
  }
  if (s.stockoutPoints) parts.push(`${s.stockoutPoints} períodos con quiebre de stock (la venta puede subestimar la demanda).`)
  return parts.join(' ')
}

export interface ComparisonChartData {
  dates: string[]
  actual: Num[]
  yhat: Num[]
  lo80: Num[]
  band80: Num[]
}

export function buildComparisonChartData(points: ComparisonPoint[]): ComparisonChartData {
  const sorted = [...points].sort((a, b) => a.date.localeCompare(b.date))
  return {
    dates: sorted.map((p) => p.date),
    actual: sorted.map((p) => p.actual),
    yhat: sorted.map((p) => p.yhat),
    lo80: sorted.map((p) => p.lo_80),
    band80: sorted.map((p) => bandWidth(p.lo_80, p.hi_80)),
  }
}

/** WAPE calculado en el cliente (solo para verificar/visualizar; la fuente es la API). */
export function wape(actual: Num[], predicted: Num[]): number | null {
  let absErr = 0
  let total = 0
  for (let i = 0; i < actual.length; i++) {
    const a = actual[i]
    const p = predicted[i]
    if (a === null || a === undefined || p === null || p === undefined) continue
    absErr += Math.abs(a - p)
    total += Math.abs(a)
  }
  return total > 0 ? absErr / total : null
}

export function describeComparison(data: ComparisonChartData): string {
  const observed = data.actual.filter((v) => v !== null).length
  const w = wape(data.actual, data.yhat)
  return `Comparación de ${data.dates.length} días; ${observed} con venta real observada. Error WAPE ${formatPercent(w)}.`
}
