/**
 * Presentación honesta de outcomes y ROI (V2.5 · W5-E; ADR-V25-14). Funciones puras:
 *  - la oportunidad POTENCIAL (por tipo de recomendación, nunca sumada entre tipos), lo OBSERVADO, lo ASOCIADO temporalmente y el INCREMENTO ESTIMADO son
 *    cosas distintas: se devuelven por separado y esta capa NO calcula ningún total que las combine;
 *  - el ROI nunca se presenta como probado (`proven` de la API se ignora a favor de «no demostrado»);
 *  - sin experimento con grupo de control y muestra suficiente no hay cifra de ROI: solo el motivo y el método.
 */
import type { DecisionOutcome, OutcomeExperiment, OutcomeRoi, OutcomeRoiReport, RoiFunnel, RoiPerRecommendation, RoiPotentialCell, RoiStatus, RoiSummary } from '@/api/types'
import { isKnown } from './enterprise'

/** Orden fijo de los tipos de recomendación en las vistas de valor. */
export const KIND_ORDER = ['contact', 'recovery', 'customer_risk', 'replenishment', 'inventory_mismatch', 'budget_constraint', 'scenario'] as const

/**
 * Tipos cuyo importe puede ser una CAÍDA OBSERVADA del gasto del cliente y no un pronóstico: la recuperación a nivel de cliente y el riesgo de cliente. La API no
 * separa en el desglose por tipo lo pronosticado de lo observado, así que estos importes se rotulan aparte y nunca se presentan como valor pronosticado.
 */
export const CUSTOMER_LEVEL_KINDS: readonly string[] = ['recovery', 'customer_risk']

/** Oportunidad potencial de UN tipo de recomendación en UN horizonte (una celda de la API). No existe un total entre tipos ni entre horizontes. */
export interface PotentialCellView {
  horizonDays: number
  recommendations: number
  /** null: ninguna recomendación de la celda tenía costo confiable (la API suma 0, pero ese 0 es «sin datos»). */
  contribution: number | null
  withoutReliableCost: number
  grossValue: number | null
}

/** Las celdas de un tipo, una por horizonte, de menor a mayor. Los horizontes tampoco se suman (los clientes se solapan entre ellos). */
export interface PotentialKindView {
  kind: string
  /** `forecast`: unidades esperadas × precio (supuesto). `customer_level`: puede incluir la caída observada del gasto; no es un pronóstico. */
  grossBasis: 'forecast' | 'customer_level'
  cells: PotentialCellView[]
}

function kindRank(kind: string): number {
  const i = (KIND_ORDER as readonly string[]).indexOf(kind)
  return i === -1 ? KIND_ORDER.length : i
}

function potentialCell(c: RoiPotentialCell): PotentialCellView {
  const without = isKnown(c.recommendations_without_reliable_cost) ? c.recommendations_without_reliable_cost : 0
  // Con el número de recomendaciones se sabe cuántas SÍ tenían costo confiable; si ninguna, el 0 de la API es «sin datos», no una contribución nula.
  const knownContribution = isKnown(c.expected_contribution) && (c.recommendations ?? 0) - without > 0
  return {
    horizonDays: c.horizon_days,
    recommendations: c.recommendations ?? 0,
    contribution: knownContribution ? c.expected_contribution : null,
    withoutReliableCost: without,
    grossValue: isKnown(c.expected_gross_value) ? c.expected_gross_value : null,
  }
}

/**
 * Oportunidad potencial POR TIPO Y HORIZONTE, sin ningún total (una suma de contacto + reposición + presupuesto no significa nada, y los horizontes de un mismo
 * tipo cuentan los mismos clientes). Sale de `summary.potential.cells`. Devuelve null si la API no entregó celdas (no se reconstruye de otro lado).
 */
export function potentialByKind(report: Pick<OutcomeRoiReport, 'summary'>): PotentialKindView[] | null {
  const cells = report.summary?.potential?.cells
  if (!Array.isArray(cells)) return null
  const byKind = new Map<string, PotentialCellView[]>()
  for (const c of cells) {
    const list = byKind.get(c.kind) ?? []
    list.push(potentialCell(c))
    byKind.set(c.kind, list)
  }
  return [...byKind.entries()]
    .map(([kind, list]) => ({
      kind,
      grossBasis: CUSTOMER_LEVEL_KINDS.includes(kind) ? ('customer_level' as const) : ('forecast' as const),
      cells: list.sort((a, b) => a.horizonDays - b.horizonDays),
    }))
    .sort((a, b) => kindRank(a.kind) - kindRank(b.kind))
}

/** Suma por recomendación (detalle, NO aditiva: varias recomendaciones pueden observar la misma venta). */
export interface PerRecommendationView {
  rows: number
  units: number
  netAmount: number
  /** null si ninguna de las recomendaciones tenía costo confiable. */
  contributionKnown: number | null
  rowsUnknown: number
}

function perRecommendation(p: RoiPerRecommendation | undefined): PerRecommendationView | null {
  if (!p || !isKnown(p.rows)) return null
  return {
    rows: p.rows,
    units: p.units,
    netAmount: p.net_amount,
    contributionKnown: p.rows_with_known_contribution > 0 ? p.contribution_known : null,
    rowsUnknown: p.rows_with_unknown_contribution,
  }
}

/**
 * La contribución conocida de un total deduplicado es «sin datos» cuando hay compras y ningún par cliente × producto tenía costo confiable: la API suma 0 en ese
 * caso y ese 0 no es una contribución nula. Con pares sin costo y otros con costo, la cifra es parcial (la interfaz lo dice).
 */
export function knownContribution(units: number, contributionKnown: number, pairsUnknown: number): number | null {
  if (!isKnown(contributionKnown)) return null
  return units > 0 && pairsUnknown > 0 && contributionKnown === 0 ? null : contributionKnown
}

export interface ObservedView {
  /** Clientes distintos con compra en la ventana. */
  customers: number
  /** Líneas de venta distintas: una venta observada por dos recomendaciones cuenta una vez. */
  purchaseLines: number
  units: number
  netAmount: number
  contributionKnown: number | null
  /** Pares cliente × producto sin costo confiable: su contribución no entra en la cifra. */
  pairsUnknown: number
  recommendationsWithPurchase: number
  /** Resultados sin detalle de líneas: se sumaron por recomendación y pueden contar dos veces una misma venta. */
  rowsWithoutLineDetail: number
  perRecommendation: PerRecommendationView | null
}

export interface AssociatedView {
  recommendations: number
  customers: number
  units: number
  netAmount: number
  contributionKnown: number | null
  pairsUnknown: number
  rowsWithoutLineDetail: number
  perRecommendation: PerRecommendationView | null
}

export interface ValueKinds {
  /** Oportunidad potencial por tipo y horizonte (atribución no demostrada). Null si la API no la entrega por celdas. */
  potential: PotentialKindView[] | null
  /** Lo ocurrido en la ventana completa, con o sin acción, sin contar dos veces una misma línea de venta. */
  observed: ObservedView
  /** SOLO lo posterior a la acción registrada, sin contrafactual: NO es beneficio atribuible. */
  associated: AssociatedView
  /** Incremento estimado por intención de tratamiento: solo existe con un experimento con control analizado y contribución estimable; null si no hay cifra monetaria. */
  incremental: { contribution: number; ciLow: number; ciHigh: number; treatedUnits: number } | null
  /** Estado del ROI: con `incremental_conversion_only` hay incremento de conversión pero NO monetario. */
  incrementalStatus: RoiStatus
  /** El intervalo monetario es solo indicativo (compradores muy concentrados). */
  heavyTailed: boolean
}

/**
 * Los cuatro tipos de valor, cada uno con sus propias cifras. NO hay un campo `total` ni se suman entre sí (sumar potencial + observado contaría dos veces la
 * misma venta) y la oportunidad potencial va POR TIPO Y HORIZONTE (no existe un total entre tipos ni entre horizontes).
 */
export function valueKinds(report: Pick<OutcomeRoiReport, 'summary' | 'roi'>): ValueKinds {
  const { observed, associated } = report.summary
  const roi = report.roi
  const inc = roi?.status === 'incremental_estimated' ? roi.incremental_contribution ?? null : null
  return {
    potential: potentialByKind(report),
    observed: {
      customers: observed.customers,
      purchaseLines: observed.purchase_lines,
      units: observed.units,
      netAmount: observed.net_amount,
      contributionKnown: knownContribution(observed.units, observed.contribution_known, observed.pairs_with_unknown_contribution),
      pairsUnknown: observed.pairs_with_unknown_contribution,
      recommendationsWithPurchase: observed.recommendations_with_purchase,
      rowsWithoutLineDetail: observed.rows_without_line_detail,
      perRecommendation: perRecommendation(observed.per_recommendation),
    },
    associated: {
      recommendations: associated.recommendations,
      customers: associated.customers,
      units: associated.units,
      netAmount: associated.net_amount,
      contributionKnown: knownContribution(associated.units, associated.contribution_known, associated.pairs_with_unknown_contribution),
      pairsUnknown: associated.pairs_with_unknown_contribution,
      rowsWithoutLineDetail: associated.rows_without_line_detail,
      perRecommendation: perRecommendation(associated.per_recommendation),
    },
    incremental: inc && isKnown(inc.contribution) ? { contribution: inc.contribution, ciLow: inc.ci_low, ciHigh: inc.ci_high, treatedUnits: inc.treated_units } : null,
    incrementalStatus: roi?.status ?? 'potential_unproven',
    heavyTailed: (roi?.reasons ?? []).includes(HEAVY_TAILED_REASON),
  }
}

/** Motivo (`roi.reasons`) con que la API avisa que el intervalo monetario es solo indicativo. */
export const HEAVY_TAILED_REASON = 'heavy_tailed_contribution'

export interface ReportPeriod {
  from: string | null
  to: string | null
  days: number | null
  /** El servidor completó el periodo por omisión (no se indicó «desde» o «hasta»). */
  defaulted: boolean
  defaultDays: number | null
  maxDays: number | null
}

/** El periodo que el servidor aplicó de verdad (no el que se pidió): la respuesta lo declara. */
export function reportPeriod(report: Pick<OutcomeRoiReport, 'filters'>): ReportPeriod {
  const f = report.filters ?? {}
  return {
    from: f.from ?? null,
    to: f.to ?? null,
    days: isKnown(f.period_days) ? f.period_days : null,
    defaulted: f.period_defaulted === true,
    defaultDays: isKnown(f.period_default_days) ? f.period_default_days : null,
    maxDays: isKnown(f.period_max_days) ? f.period_max_days : null,
  }
}

/**
 * Conteos del embudo coherentes con lo que el rol puede listar: quien aprueba ve todas las medidas; el resto, las accionables (las que la API ya separa de
 * las retenidas para medición). No se restan ni se derivan cifras: se usa el campo que la API entrega.
 */
export function funnelCounts(funnel: RoiFunnel, canApprove: boolean): { identified: number; measured: number } {
  if (canApprove) return { identified: funnel.identified, measured: funnel.measured }
  return { identified: funnel.actionable ?? funnel.identified, measured: funnel.measured_actionable ?? funnel.measured }
}

/**
 * Conteos por clasificación. La API ya no mezcla la retención en los conteos de quien no aprueba (sus filas de control ni se leen); si por alguna razón trajera
 * `funnel.control_held_out` mayor que 0 para ese rol, los contadores incluirían unidades que no puede listar y se omiten (null) en lugar de mostrarse.
 */
export function classificationCounts(summary: Pick<RoiSummary, 'funnel' | 'by_classification'>, canApprove: boolean): Record<string, number> | null {
  if (!canApprove && (summary.funnel?.control_held_out ?? 0) > 0) return null
  return summary.by_classification ?? {}
}

/** Mayor corte de conocimiento de ventas entre resultados medidos: hasta cuándo se leyeron las ventas. Null si no hay ninguno. */
export function latestKnowledgeCutoff(rows: readonly Pick<DecisionOutcome, 'window' | 'evidence'>[]): string | null {
  let best: string | null = null
  for (const row of rows) {
    const c = row.window?.knowledge_cutoff ?? row.evidence?.source?.knowledge_cutoff ?? row.evidence?.window?.knowledge_cutoff ?? null
    if (typeof c === 'string' && c !== '' && (best === null || c > best)) best = c
  }
  return best
}

export interface RoiConversionView {
  rateTreatment: number | null
  rateControl: number | null
  difference: number | null
  ciLow: number | null
  ciHigh: number | null
  significant: boolean | null
}

export interface RoiView {
  status: RoiStatus
  /** Siempre false: la interfaz nunca declara el ROI como probado, diga lo que diga `roi.proven`. */
  proven: false
  /** Hay una cifra neta con intervalo que mostrar (solo con estimación por intención de tratamiento y costo de contacto conocido). */
  hasNetFigure: boolean
  net: { estimate: number; ciLow: number; ciHigh: number; significant: boolean } | null
  ratio: { estimate: number; ciLow: number; ciHigh: number } | null
  /** Solo con `incremental_conversion_only` (o con estimación): incremento de CONVERSIÓN, que no es una cifra monetaria. Quien no aprueba no la recibe. */
  conversion: RoiConversionView | null
  reasons: string[]
  /** El intervalo monetario es solo indicativo (`heavy_tailed_contribution`). */
  heavyTailed: boolean
  /** Experimentos con estimación (`experimento|horizonte`) cuando hay varios y por eso no hay una cifra única. */
  multiple: { key: string; horizonDays: number | null }[]
  message: string
  originWarning: string | null
}

export function roiView(roi: OutcomeRoi): RoiView {
  const net = roi.status === 'incremental_estimated' ? roi.net_incremental_contribution ?? null : null
  const ratio = net ? roi.roi_ratio ?? null : null
  const conv = roi.status === 'incremental_estimated' || roi.status === 'incremental_conversion_only' ? roi.conversion ?? null : null
  const reasons = [...new Set(roi.reasons ?? [])]
  return {
    status: roi.status,
    proven: false,
    hasNetFigure: net !== null && isKnown(net.estimate),
    net: net && isKnown(net.estimate) ? { estimate: net.estimate, ciLow: net.ci_low, ciHigh: net.ci_high, significant: net.significant } : null,
    ratio: ratio && isKnown(ratio.estimate) ? { estimate: ratio.estimate, ciLow: ratio.ci_low, ciHigh: ratio.ci_high } : null,
    conversion: conv
      ? {
          rateTreatment: isKnown(conv.rate_treatment) ? conv.rate_treatment : null,
          rateControl: isKnown(conv.rate_control) ? conv.rate_control : null,
          difference: isKnown(conv.difference) ? conv.difference : null,
          ciLow: isKnown(conv.ci_low) ? conv.ci_low : null,
          ciHigh: isKnown(conv.ci_high) ? conv.ci_high : null,
          significant: typeof conv.significant === 'boolean' ? conv.significant : null,
        }
      : null,
    reasons,
    heavyTailed: reasons.includes(HEAVY_TAILED_REASON),
    multiple: roi.status === 'multiple_experiments' ? (roi.experiments ?? []).map((e) => {
      const [key, h] = e.split('|')
      return { key: key ?? e, horizonDays: h !== undefined && h !== '' && Number.isFinite(Number(h)) ? Number(h) : null }
    }) : [],
    message: roi.message ?? '',
    originWarning: roi.data_origin_warning ?? null,
  }
}

/** ¿El experimento tiene estimación (con intervalo)? Si no, no hay diferencia ni intervalo que mostrar. */
export function experimentEstimated(exp: Pick<OutcomeExperiment, 'status'>): boolean {
  return exp.status === 'estimated'
}

export type ClassificationTone = 'neutral' | 'info' | 'warning' | 'success'

/**
 * Tono visual de cada clasificación de una FILA. Ninguna se pinta como «éxito probado»: `incremental_estimated` no es una clasificación de filas (es un resultado
 * agregado del análisis por experimento) y `causally_attributable` no lo produce esta versión; ambas quedan en el tono neutro.
 */
export function classificationTone(classification: string): ClassificationTone {
  switch (classification) {
    case 'observed': return 'info'
    case 'temporally_associated': return 'warning'
    default: return 'neutral'
  }
}

/** Clasificaciones que el servidor ESCRIBE en una fila de resultado (`OutcomeVocabulary::WRITTEN`): la fila nunca es incremental ni causal. */
export const ROW_CLASSIFICATIONS = ['observed', 'temporally_associated'] as const

/** Clasificaciones con conteo en el resumen, de lo menos a lo más fuerte: previsto (virtual, aún sin medir) y las dos que escribe el servidor. */
export const CLASSIFICATION_ORDER = ['forecast', ...ROW_CLASSIFICATIONS] as const

export const ACTION_KINDS = ['contacted', 'executed', 'not_contacted', 'not_executed', 'rejected', 'expired', 'control'] as const

/** Eventos observados (`OutcomeVocabulary::EVENTS`). `no_sell_through` = la disponibilidad no bajó al cierre de la ventana. */
export const OBSERVED_EVENTS = ['purchase', 'no_purchase', 'product_sales', 'stockout', 'no_sell_through'] as const

/** Etiqueta de la acción sin delatar el grupo de control (esas filas solo las envía la API a quien aprueba): «retenida para medición». */
export function actionLabelKey(action: string): string {
  return action === 'control' ? 'roi.actions.held' : `roi.actions.${action}`
}
