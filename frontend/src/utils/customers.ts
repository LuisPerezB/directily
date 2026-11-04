import { isApiError } from '@/api/errors'
import type { CustomerPrediction, CustomerReconciliationRow, CustomerWindow, CustomerWindowStatus, ReconciliationStatus } from '@/api/types'
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

/**
 * 422 `horizon_not_available`: la corrida se abstuvo de ese horizonte (estado `insufficient_data`). Devuelve los horizontes sí disponibles y
 * el motivo unificado (`reason_code`: no_model, coverage_insufficient, insufficient_horizon...) cuando el servidor lo informa.
 */
export function horizonNotAvailable(error: unknown): { availableHorizons: number[]; reasonCode: string | null; message: string | null } | null {
  if (!isApiError(error) || error.status !== 422 || error.code !== 'horizon_not_available') return null
  const body = error.body as { available_horizons?: unknown; reason_code?: unknown; reasons?: unknown } | null
  const raw = body?.available_horizons
  const list = Array.isArray(raw) ? raw.map(Number).filter((n) => Number.isFinite(n)) : []
  const first = Array.isArray(body?.reasons) ? (body.reasons[0] as { message?: unknown } | undefined) : undefined
  return {
    availableHorizons: list,
    reasonCode: typeof body?.reason_code === 'string' ? body.reason_code : null,
    message: typeof first?.message === 'string' ? first.message : null,
  }
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

// ─── V2.5 · W3-D: S05 (nº de pedidos, unidades y ventana de recompra) ──────────────────────────────────────────

/**
 * Banderas de diagnóstico del motor (`docs/ml/CUSTOMER_REPURCHASE.md` §14) que no se muestran al usuario: informan que el cálculo de S05 no estuvo disponible o
 * falló en la fila, y la fila ya lo dice con «No estimado». Las demás —incluidas las de S05 que explican una cifra: `orders_nested_adjusted`, `cold_start`,
 * `window_beyond_validation`— se traducen y se muestran. Una bandera desconocida se muestra (como hasta ahora) en lugar de ocultarse.
 */
export const DIAGNOSTIC_FLAGS: readonly string[] = ['s05_unavailable', 's05_failed']

export function visibleFlags(flags: readonly string[] | null | undefined): string[] {
  return (flags ?? []).filter((f) => !DIAGNOSTIC_FLAGS.includes(f))
}

/**
 * Por qué una fila no tiene las cantidades de S05 (la bandera de diagnóstico del motor, que no se muestra como código): `unavailable` = el modelo servido es
 * anterior a esta función; `failed` = el cálculo falló en la fila. Solo se usa para explicar un «No estimado»; nunca para inventar una cifra.
 */
export function s05Cause(flags: readonly string[] | null | undefined): 'failed' | 'unavailable' | null {
  const list = flags ?? []
  if (list.includes('s05_failed')) return 'failed'
  if (list.includes('s05_unavailable')) return 'unavailable'
  return null
}

export type WindowView =
  /** Rango probable (P10–P90) con la mediana; `overdue` = atrasado respecto a su patrón (no es abandono). */
  | { kind: 'range'; p10: number; p50: number | null; p90: number; overdue: boolean }
  /** Sin cifras: el estado dice por qué (`insufficient_data`, `censored`) o `unavailable` si el motor no lo estimó (corrida anterior a S05). */
  | { kind: 'state'; status: CustomerWindowStatus | 'unavailable' }

/** La ventana se muestra como rango solo con un estado que la respalda y ambos extremos; en cualquier otro caso, solo el estado. Nunca un día exacto. */
export function windowView(window: CustomerWindow | null | undefined): WindowView {
  const status = window?.status ?? null
  if ((status === 'estimated' || status === 'overdue') && typeof window?.p10_days === 'number' && typeof window?.p90_days === 'number') {
    return { kind: 'range', p10: window.p10_days, p50: typeof window.p50_days === 'number' ? window.p50_days : null, p90: window.p90_days, overdue: status === 'overdue' }
  }
  return { kind: 'state', status: status ?? 'unavailable' }
}
