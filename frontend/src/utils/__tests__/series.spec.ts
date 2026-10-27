import { describe, expect, it } from 'vitest'
import { buildComparisonChartData, buildForecastChartData, describeSeries, summarizeSeries, wape } from '../series'
import type { ForecastPoint, HistoryPoint } from '@/api/types'

const history: HistoryPoint[] = [
  { date: '2026-10-02', units: 12, revenue: 120, stockout: false },
  { date: '2026-10-01', units: 10, revenue: 100, stockout: false },
  { date: '2026-10-03', units: 0, revenue: 0, stockout: true },
]
const forecast: ForecastPoint[] = [
  { date: '2026-10-05', yhat: 13, lo_80: 10, hi_80: 16, lo_95: 8, hi_95: 19, revenue: 130 },
  { date: '2026-10-04', yhat: 11, lo_80: 9, hi_80: 14, lo_95: 7, hi_95: 16, revenue: 110 },
  { date: '2026-10-03', yhat: 99, lo_80: null, hi_80: null, lo_95: null, hi_95: null, revenue: null },
]

describe('buildForecastChartData', () => {
  const data = buildForecastChartData(history, forecast)

  it('ordena por fecha y descarta pronósticos que se solapan con el histórico', () => {
    expect(data.dates).toEqual(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'])
    expect(data.cutoffIndex).toBe(2)
  })

  it('alinea histórico y pronóstico con nulos', () => {
    expect(data.history).toEqual([10, 12, 0, null, null])
    expect(data.yhat).toEqual([null, null, null, 11, 13])
  })

  it('calcula bandas como base + ancho (superior − inferior)', () => {
    expect(data.lo80).toEqual([null, null, null, 9, 10])
    expect(data.band80).toEqual([null, null, null, 5, 6])
    expect(data.band95).toEqual([null, null, null, 9, 11])
    expect(data.hasIntervals).toBe(true)
  })

  it('marca días con quiebre de stock', () => {
    expect(data.stockout).toEqual([false, false, true, false, false])
  })

  it('en ingresos no dibuja intervalos (están en unidades)', () => {
    const rev = buildForecastChartData(history, forecast, 'revenue')
    expect(rev.history).toEqual([100, 120, 0, null, null])
    expect(rev.yhat).toEqual([null, null, null, 110, 130])
    expect(rev.hasIntervals).toBe(false)
  })

  it('maneja ausencia de histórico', () => {
    const only = buildForecastChartData([], forecast.slice(0, 2))
    expect(only.cutoffIndex).toBe(-1)
    expect(only.dates).toEqual(['2026-10-04', '2026-10-05'])
  })

  it('resume y describe para lectores de pantalla', () => {
    const s = summarizeSeries(data)
    expect(s.historyTotal).toBe(22)
    expect(s.forecastTotal).toBe(24)
    expect(s.stockoutPoints).toBe(1)
    expect(describeSeries(data, 'unidades')).toMatch(/quiebre de stock/)
  })
})

describe('comparación real vs pronóstico', () => {
  it('calcula WAPE ignorando días sin dato real', () => {
    expect(wape([10, 20, null], [12, 18, 5])).toBeCloseTo(4 / 30)
    expect(wape([0, 0], [1, 1])).toBeNull()
  })

  it('construye la serie de comparación', () => {
    const d = buildComparisonChartData([
      { date: '2026-09-02', yhat: 5, lo_80: 3, hi_80: 8, actual: 6 },
      { date: '2026-09-01', yhat: 4, lo_80: null, hi_80: null, actual: null },
    ])
    expect(d.dates).toEqual(['2026-09-01', '2026-09-02'])
    expect(d.band80).toEqual([null, 5])
    expect(d.actual).toEqual([null, 6])
  })
})
