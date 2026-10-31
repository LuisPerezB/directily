import { describe, expect, it } from 'vitest'
import { abstentionSummary, bandLabelParams, horizonBand, splitHorizonSupport } from '../accuracy'
import { buildForecastChartData } from '../series'
import type { ForecastPoint, HistoryPoint, HorizonSupport } from '@/api/types'

describe('tramos de evaluación', () => {
  it('lee los tramos nuevos h1-7 … h61-90', () => {
    expect(horizonBand('h8-14')).toEqual({ from: 8, to: 14 })
    expect(horizonBand('h61-90')).toEqual({ from: 61, to: 90 })
    expect(horizonBand('H30')).toBeNull()
    expect(horizonBand(undefined)).toBeNull()
  })
  it('las ejecuciones antiguas conservan la etiqueta por horizonte', () => {
    expect(bandLabelParams({ level_key: 'h15-30', horizon_days: 30 })).toEqual({ key: 'accuracy.bandLabel', from: 15, to: 30 })
    expect(bandLabelParams({ horizon_days: 30 })).toEqual({ key: 'accuracy.horizonLabel', n: 30 })
  })
  it('separa horizontes respaldados de los que no', () => {
    const s = (h: number, status: HorizonSupport['status']): HorizonSupport => ({ horizon: h, status, reasons: [] })
    const { supported, unsupported } = splitHorizonSupport({ '90': s(90, 'insufficient_data'), '7': s(7, 'ok'), '30': s(30, 'degraded'), '60': s(60, 'not_computed') })
    expect(supported.map((h) => h.horizon)).toEqual([7, 30])
    expect(unsupported.map((h) => h.horizon)).toEqual([60, 90])
  })
})

describe('mediana (P50) en la serie', () => {
  const history: HistoryPoint[] = [{ date: '2026-10-01', units: 5, revenue: 50, stockout: false }]
  const point = (date: string, p50: number | null | undefined): ForecastPoint => ({ date, yhat: 4, lo_80: 2, hi_80: 7, lo_95: 1, hi_95: 9, revenue: 40, p50 })

  it('solo se dibuja si el motor la publicó (algún valor no nulo)', () => {
    const none = buildForecastChartData(history, [point('2026-10-02', null), point('2026-10-03', undefined)])
    expect(none.hasP50).toBe(false)
    expect(none.p50).toEqual([null, null, null])
    const some = buildForecastChartData(history, [point('2026-10-02', 3.5), point('2026-10-03', 4)])
    expect(some.hasP50).toBe(true)
    expect(some.p50).toEqual([null, 3.5, 4])
  })

  it('nunca se sustituye por el pronóstico central', () => {
    const d = buildForecastChartData(history, [point('2026-10-02', null)])
    expect(d.p50[1]).toBeNull()
    expect(d.yhat[1]).toBe(4)
  })

  it('en ingresos no hay mediana', () => {
    expect(buildForecastChartData(history, [point('2026-10-02', 3)], 'revenue').hasP50).toBe(false)
  })
})

describe('series sin pronóstico (abstención)', () => {
  it('suma las abstenciones del resumen de casos especiales y conserva los conteos de series marcadas', () => {
    const s = abstentionSummary({ special_cases: {
      label_counts: { new_product_category_median: 4, discontinued_zero: 2, sparse_history: 0 }, no_recent_sales: { abstained: 7, after_days: 180 },
      discontinued: { explicit_zero: 2, abstained: 3, lookback_days: 365 }, branch_closed_declared: { branches: [2], explicit_zero: 1, abstained: 1 },
    } })!
    expect(s.total).toBe(11)
    expect(s.noRecentSales).toBe(7)
    expect(s.afterDays).toBe(180)
    expect(s.labelled).toEqual({ new_product_category_median: 4, discontinued_zero: 2 })
  })
  it('una ejecución antigua sin el resumen no inventa conteos', () => {
    expect(abstentionSummary({})).toBeNull()
    expect(abstentionSummary(null)).toBeNull()
  })
})
