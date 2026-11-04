import { describe, expect, it } from 'vitest'
import { DIAGNOSTIC_FLAGS, visibleFlags, windowView } from '../customers'
import { decisionTone, gateTone, pairByScope, sampleView, segmentOf, segmentValueLabel, verdictTone, wapeView } from '../modelQuality'
import { i18n } from '@/i18n'

const t = (key: string, named?: Record<string, unknown>): string => String(i18n.global.t(key, named ?? {}))
const te = (key: string): boolean => i18n.global.te(key)
const ctx = { t, te, category: (id: number) => (id === 4 ? 'Cementos' : null), branch: (id: number) => (id === 2 ? 'Matriz Quito' : null) }

describe('WAPE por segmento: nunca una cifra que el motor no respalda', () => {
  const base = { status: 'ok' as const, figures_available: true, wape: 0.21, quality: { wape_status: 'ok', wape_unstable: false } as never }

  it('con denominador suficiente es una cifra', () => {
    expect(wapeView(base)).toEqual({ kind: 'figure', value: 0.21 })
  })

  it('insufficient_data no tiene cifras aunque llegue un valor', () => {
    expect(wapeView({ ...base, status: 'insufficient_data' })).toEqual({ kind: 'insufficient' })
    expect(wapeView({ ...base, figures_available: false })).toEqual({ kind: 'insufficient' })
  })

  it('un WAPE inestable o indefinido no se publica como porcentaje', () => {
    expect(wapeView({ ...base, wape: null, quality: { wape_status: 'unstable', wape_unstable: true } as never })).toEqual({ kind: 'unstable' })
    expect(wapeView({ ...base, wape: null, quality: { wape_status: 'undefined', wape_unstable: true } as never })).toEqual({ kind: 'undefined' })
    expect(wapeView({ ...base, wape: 0.4, quality: { wape_status: 'unstable', wape_unstable: true } as never })).toEqual({ kind: 'unstable' })
  })

  it('sin valor numérico no hay cifra', () => {
    expect(wapeView({ ...base, wape: null, quality: null })).toEqual({ kind: 'unavailable' })
    expect(wapeView({ ...base, wape: Number.NaN, quality: null })).toEqual({ kind: 'unavailable' })
  })
})

describe('cobertura de la muestra', () => {
  it('lista solo las causas de exclusión con días y no cuenta las observaciones sin escala', () => {
    const view = sampleView({ evaluable_days: 840, excluded_days: 30, total_days: 870, exclusions: { stockout_days: 20, missing_source_days: 6, closed_days: 0, scale_undefined: 12 } })
    expect(view).toEqual({ evaluable: 840, excluded: 30, total: 870, exclusions: [{ key: 'stockout_days', days: 20 }, { key: 'missing_source_days', days: 6 }] })
    expect(sampleView(null)).toBeNull()
  })
})

describe('etiquetas de segmento', () => {
  it('clase de demanda, vida, tramo de volumen, categoría y sucursal', () => {
    expect(segmentValueLabel('demand_class', 'intermittent', ctx)).toBe('Intermitente')
    expect(segmentValueLabel('series_age', 'new', ctx)).toBe('Nueva (poca historia)')
    expect(segmentValueLabel('volume_tier', 'top20', ctx)).toBe('Top 20 % por volumen')
    expect(segmentValueLabel('volume_tier', 'top10', ctx)).toBe('Top 10 % por volumen')
    expect(segmentValueLabel('volume_tier', 'rest', ctx)).toBe('Resto')
    expect(segmentValueLabel('category', 'c4', ctx)).toBe('Cementos')
    expect(segmentValueLabel('category', 'c9', ctx)).toBe('Categoría n.º 9')
    expect(segmentValueLabel('category', 'none', ctx)).toBe('Sin categoría')
    expect(segmentValueLabel('branch', 'b2', ctx)).toBe('Matriz Quito')
    expect(segmentValueLabel('frequency', 'high', ctx)).toBe('Alta')
    expect(segmentValueLabel('history', 'cold_start', ctx)).toBe('Pocas compras (arranque en frío)')
  })

  it('un valor desconocido se muestra tal cual en lugar de ocultarse', () => {
    expect(segmentValueLabel('demand_class', 'rara', ctx)).toBe('rara')
    expect(segmentValueLabel('otra', 'x', ctx)).toBe('x')
  })

  it('el segmento sale de la fila o de su clave', () => {
    expect(segmentOf({ segment: { dimension: 'branch', value: 'b2' }, level_key: 'branch:b2' })).toEqual({ dimension: 'branch', value: 'b2' })
    expect(segmentOf({ segment: null, level_key: 'demand_class:smooth' })).toEqual({ dimension: 'demand_class', value: 'smooth' })
    expect(segmentOf({ segment: null, level_key: 'window' })).toBeNull()
    expect(segmentOf({ segment: null, level_key: null })).toBeNull()
  })
})

describe('tonos y orden de las decisiones', () => {
  it('veredictos, gates y decisiones tienen un tono; lo desconocido es neutro', () => {
    expect(verdictTone('better')).toBe('success')
    expect(verdictTone('worse')).toBe('danger')
    expect(verdictTone('not_comparable')).toBe('neutral')
    expect(gateTone('pass')).toBe('success')
    expect(gateTone('fail')).toBe('danger')
    expect(gateTone('pending')).toBe('warning')
    expect(gateTone('algo')).toBe('neutral')
    expect(decisionTone('publish_baseline')).toBe('warning')
    expect(decisionTone('reject')).toBe('danger')
    expect(decisionTone(undefined)).toBe('neutral')
  })

  it('agrupa por alcance con las dos políticas lado a lado: global, clases y horizontes', () => {
    const d = (scope_key: string, policy: string, informational = false) => ({ scope_key, policy, informational })
    const pairs = pairByScope([d('horizon:30', 'legacy', true), d('class:smooth', 'v25'), d('global', 'v25'), d('class:smooth', 'legacy'), d('global', 'legacy'), d('class:erratic', 'legacy')])
    expect(pairs.map((p) => p.scope_key)).toEqual(['global', 'class:erratic', 'class:smooth', 'horizon:30'])
    expect(pairs[0]!.legacy?.policy).toBe('legacy')
    expect(pairs[0]!.v25?.policy).toBe('v25')
    expect(pairs[1]!.v25).toBeNull()
    expect(pairs[3]!.informational).toBe(true)
  })
})

describe('Customer: ventana y banderas', () => {
  it('la ventana es un rango solo con un estado que la respalda y ambos extremos', () => {
    expect(windowView({ status: 'estimated', p10_days: 6, p50_days: 11.5, p90_days: 19 })).toEqual({ kind: 'range', p10: 6, p50: 11.5, p90: 19, overdue: false })
    expect(windowView({ status: 'overdue', p10_days: 20, p50_days: null, p90_days: 60 })).toEqual({ kind: 'range', p10: 20, p50: null, p90: 60, overdue: true })
  })

  it('sin estado que la respalde solo hay estado, aunque lleguen cuantiles', () => {
    expect(windowView({ status: 'insufficient_data', p10_days: 5, p50_days: 9, p90_days: 14 })).toEqual({ kind: 'state', status: 'insufficient_data' })
    expect(windowView({ status: 'censored', p10_days: null, p50_days: null, p90_days: null })).toEqual({ kind: 'state', status: 'censored' })
    expect(windowView({ status: null, p10_days: null, p50_days: null, p90_days: null })).toEqual({ kind: 'state', status: 'unavailable' })
    expect(windowView(undefined)).toEqual({ kind: 'state', status: 'unavailable' })
    // Estimada pero sin extremos: no se inventa un rango.
    expect(windowView({ status: 'estimated', p10_days: null, p50_days: 9, p90_days: null })).toEqual({ kind: 'state', status: 'estimated' })
  })

  it('las banderas de diagnóstico del motor no se muestran; las demás sí (incluidas las desconocidas)', () => {
    expect(DIAGNOSTIC_FLAGS).toEqual(['s05_unavailable', 's05_failed'])
    expect(visibleFlags(['orders_nested_adjusted', 'cold_start', 's05_failed', 'window_beyond_validation', 's05_unavailable', 'price_unknown', 'bandera_nueva'])).toEqual(
      ['orders_nested_adjusted', 'cold_start', 'window_beyond_validation', 'price_unknown', 'bandera_nueva'])
    expect(visibleFlags(null)).toEqual([])
    expect(visibleFlags(undefined)).toEqual([])
  })

  it('toda bandera de S05 tiene traducción al español (también las de diagnóstico, que no se muestran)', () => {
    for (const flag of ['orders_nested_adjusted', 'cold_start', 'window_beyond_validation', 's05_unavailable', 's05_failed']) {
      expect(te(`customerFlags.${flag}`), flag).toBe(true)
    }
    for (const flag of ['orders_nested_adjusted', 'cold_start', 'window_beyond_validation']) expect(te(`customerFlagHints.${flag}`), flag).toBe(true)
  })
})
