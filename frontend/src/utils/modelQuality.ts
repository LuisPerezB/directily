/**
 * Calidad ML en la UI (V2.5 · W3-D): lectura de las evaluaciones por segmento y horizonte, las decisiones de promoción y el shadow.
 * Regla de honestidad: nunca una cifra donde el motor no la respalda. Un segmento con pocas observaciones (`insufficient_data`) no muestra error;
 * un WAPE con denominador insuficiente o cero no se muestra como porcentaje y se explica con su métrica alternativa.
 */
import type {
  ComparisonVerdict,
  CustomerSegmentDimension,
  DemandSegmentDimension,
  ModelQualityEvaluation,
  PromotionDecisionKind,
  PromotionGateStatus,
  QualitySample,
} from '@/api/types'
import type { Tone } from './envelope'

/** Dimensiones de segmento de Demand, en el orden en que se ofrecen. */
export const DEMAND_DIMENSIONS: DemandSegmentDimension[] = ['demand_class', 'volume_tier', 'series_age', 'category', 'branch']
/** Dimensiones de segmento de Customer. */
export const CUSTOMER_DIMENSIONS: CustomerSegmentDimension[] = ['frequency', 'value', 'tenure', 'history']

export type WapeView =
  | { kind: 'figure'; value: number }
  /** Denominador menor que el mínimo: el WAPE no es estable, no se publica como porcentaje. */
  | { kind: 'unstable' }
  /** Denominador 0: el WAPE no está definido. */
  | { kind: 'undefined' }
  /** Segmento con pocas observaciones: sin cifras de error. */
  | { kind: 'insufficient' }
  | { kind: 'unavailable' }

export function wapeView(row: Pick<ModelQualityEvaluation, 'status' | 'figures_available' | 'wape' | 'quality'>): WapeView {
  if (row.status === 'insufficient_data' || row.figures_available === false) return { kind: 'insufficient' }
  const status = row.quality?.wape_status ?? null
  if (status === 'undefined') return { kind: 'undefined' }
  if (status === 'unstable' || row.quality?.wape_unstable) return { kind: 'unstable' }
  return typeof row.wape === 'number' && Number.isFinite(row.wape) ? { kind: 'figure', value: row.wape } : { kind: 'unavailable' }
}

export interface SampleView {
  evaluable: number | null
  excluded: number | null
  total: number | null
  /** Días excluidos por causa (quiebre, fuente ausente, cierre), solo las causas con días. */
  exclusions: { key: string; days: number }[]
}

/** Cobertura de la muestra: cuántos días entraron a la métrica y cuántos se excluyeron y por qué. */
export function sampleView(sample: QualitySample | null | undefined): SampleView | null {
  if (!sample) return null
  const exclusions = Object.entries(sample.exclusions ?? {})
    .filter(([key, days]) => key !== 'scale_undefined' && typeof days === 'number' && days > 0)
    .map(([key, days]) => ({ key, days }))
  return { evaluable: sample.evaluable_days ?? null, excluded: sample.excluded_days ?? null, total: sample.total_days ?? null, exclusions }
}

/** Valor del segmento (`demand_class:smooth` → `smooth`) a partir de la clave del nivel. */
export function segmentOf(row: Pick<ModelQualityEvaluation, 'segment' | 'level_key'>): { dimension: string; value: string } | null {
  if (row.segment) return row.segment
  const key = row.level_key
  if (!key || !key.includes(':')) return null
  const [dimension, ...rest] = key.split(':')
  return { dimension: dimension!, value: rest.join(':') }
}

export interface SegmentLabelContext {
  t: (key: string, named?: Record<string, unknown>) => string
  te: (key: string) => boolean
  category?: (id: number) => string | null | undefined
  branch?: (id: number) => string | null | undefined
}

/** Etiqueta legible del valor de un segmento: clase de demanda, tramo de volumen, vida de la serie, categoría o sucursal (con su nombre si se conoce). */
export function segmentValueLabel(dimension: string, value: string, ctx: SegmentLabelContext): string {
  if (dimension === 'volume_tier') {
    const top = /^top(\d+)$/.exec(value)
    if (top) return ctx.t('segments.values.volume_tier.top', { n: top[1] })
  }
  if (dimension === 'category' || dimension === 'branch') {
    if (value === 'none') return ctx.t('segments.values.category.none')
    const id = /^[cb](\d+)$/.exec(value)
    if (id) {
      const name = (dimension === 'category' ? ctx.category : ctx.branch)?.(Number(id[1]))
      return name ? name : ctx.t(`segments.values.${dimension}.unnamed`, { id: id[1] })
    }
  }
  const key = `segments.values.${dimension}.${value}`
  if (ctx.te(key)) return ctx.t(key)
  if (dimension === 'demand_class' && ctx.te(`demandClass.${value}`)) return ctx.t(`demandClass.${value}`)
  return value
}

export function verdictTone(verdict: ComparisonVerdict | null | undefined): Tone {
  switch (verdict) {
    case 'better': return 'success'
    case 'worse': return 'danger'
    case 'similar': return 'info'
    default: return 'neutral'
  }
}

export function gateTone(status: PromotionGateStatus | string | null | undefined): Tone {
  switch (status) {
    case 'pass': return 'success'
    case 'fail': return 'danger'
    case 'pending': return 'warning'
    case 'not_evaluable': return 'warning'
    default: return 'neutral'
  }
}

export function decisionTone(decision: PromotionDecisionKind | string | null | undefined): Tone {
  switch (decision) {
    case 'promote': return 'success'
    case 'keep_champion': return 'info'
    case 'publish_baseline': return 'warning'
    case 'shadow': return 'info'
    case 'reject': return 'danger'
    default: return 'neutral'
  }
}

export const TONE_CLASS: Record<Tone, string> = { success: 'badge-success', info: 'badge-info', warning: 'badge-warning', danger: 'badge-danger', neutral: '' }

export interface ScopeSort { rank: number; label: string }

/** Orden de los alcances: global, clases de demanda y horizontes. */
export function scopeOrder(scopeKey: string): number {
  if (scopeKey === 'global') return 0
  if (scopeKey.startsWith('class:')) return 1
  return 2
}

export interface DecisionPair<D> { scope_key: string; informational: boolean; legacy: D | null; v25: D | null }

/** Agrupa las decisiones por alcance, una columna por política (la heredada y la V2.5 lado a lado). */
export function pairByScope<D extends { scope_key: string; policy: string; informational: boolean }>(decisions: readonly D[]): DecisionPair<D>[] {
  const map = new Map<string, DecisionPair<D>>()
  for (const d of decisions) {
    const entry = map.get(d.scope_key) ?? { scope_key: d.scope_key, informational: d.informational, legacy: null, v25: null }
    if (d.policy === 'legacy') entry.legacy = d
    else if (d.policy === 'v25') entry.v25 = d
    map.set(d.scope_key, entry)
  }
  return [...map.values()].sort((a, b) => scopeOrder(a.scope_key) - scopeOrder(b.scope_key) || a.scope_key.localeCompare(b.scope_key, 'es', { numeric: true }))
}
