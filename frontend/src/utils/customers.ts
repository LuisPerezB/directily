import { isApiError } from '@/api/errors'
import type { CustomerPrediction, CustomerReconciliationRow, ReconciliationStatus } from '@/api/types'
import { DASH, formatCurrency } from './format'

/** Importe esperado: SIEMPRE una estimación con supuesto de precio, nunca una venta confirmada. */
export function formatExpectedAmount(amount: { value: number | null; currency: string | null } | null | undefined, fallbackCurrency = 'USD'): string {
  if (!amount || amount.value == null) return DASH
  return formatCurrency(amount.value, amount.currency ?? fallbackCurrency, 2)
}

/** Una predicción sin probabilidad es una abstención: no se muestra ningún número en su lugar. */
export function predictionAbstains(p: Pick<CustomerPrediction, 'purchase_probability'>): boolean {
  return p.purchase_probability == null
}

/** 422 `horizon_not_available`: la corrida se abstuvo de ese horizonte. Devuelve los horizontes sí disponibles. */
export function horizonNotAvailable(error: unknown): { availableHorizons: number[] } | null {
  if (!isApiError(error) || error.status !== 422 || error.code !== 'horizon_not_available') return null
  const raw = (error.body as { available_horizons?: unknown } | null)?.available_horizons
  const list = Array.isArray(raw) ? raw.map(Number).filter((n) => Number.isFinite(n)) : []
  return { availableHorizons: list }
}

const SEVERITY_ORDER: Record<string, number> = { major: 0, minor: 1 }

/** Inconsistencias primero (graves antes que leves); el resto conserva el orden recibido. */
export function sortReconciliation(rows: readonly CustomerReconciliationRow[]): CustomerReconciliationRow[] {
  const rank = (r: CustomerReconciliationRow): number => (r.status === 'inconsistent' ? SEVERITY_ORDER[r.severity ?? 'minor'] ?? 1 : 2)
  return rows.map((r, i) => ({ r, i })).sort((a, b) => rank(a.r) - rank(b.r) || a.i - b.i).map((x) => x.r)
}

export const RECONCILIATION_STATUSES: ReconciliationStatus[] = ['ok', 'inconsistent', 'aggregate_unavailable', 'aggregate_incomplete']

export interface ReconciliationParts {
  aggregate: number | null
  buyers: number | null
  committedOutside: number | null
  residual: number | null
  /** Solo hay desglose confiable si existe el agregado y la fila no es una inconsistencia o un faltante de datos. */
  decomposable: boolean
  /** Σ componentes − agregado (debe ser ≈ 0 en filas `ok`). */
  sumGap: number | null
}

export function reconciliationParts(r: CustomerReconciliationRow): ReconciliationParts {
  const decomposable = (r.status === 'ok' || r.status === 'inconsistent') && r.aggregate_quantity != null
  const buyers = r.buyers_total_quantity
  const outside = r.committed_outside_model
  const residual = r.residual_quantity
  let sumGap: number | null = null
  if (decomposable && r.status === 'ok' && buyers != null && outside != null && residual != null) {
    sumGap = buyers + outside + residual - (r.aggregate_quantity as number)
  }
  return { aggregate: r.aggregate_quantity, buyers, committedOutside: outside, residual, decomposable, sumGap }
}

/** Clave de la curva/nivel de métricas del Customer Core: `h30:probability`, `h30:ranking`... */
export function evaluationKey(horizon: number, objective: 'probability' | 'quantity_if_purchase' | 'expected_quantity' | 'expected_amount' | 'ranking'): string {
  return `h${horizon}:${objective}`
}

export const RANKING_KS = [1, 3, 5, 10] as const

export function rankingAtK(
  metrics: Record<string, number | undefined> | null | undefined,
  kind: 'precision' | 'recall',
  k: number,
): number | null {
  const v = metrics?.[`${kind}_at_${k}`]
  return typeof v === 'number' && Number.isFinite(v) ? v : null
}
