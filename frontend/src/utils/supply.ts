import type { CausalCheck, PlanInfeasibility, PlanRequest, ScenarioStrategyResult, SolverStatus, SupplyPlanSummary } from '@/api/types'
import { DASH, formatPercent } from './format'

/**
 * El plan de compras NUNCA se presenta como "óptimo" salvo que el solver lo haya probado (`optimal`).
 */
export function isProvenOptimal(status: SolverStatus | string | null | undefined): boolean {
  return status === 'optimal'
}

/** Un plan con líneas utilizables (aunque no sea óptimo). `infeasible` y `not_solved` no tienen plan. */
export function solverHasPlan(status: SolverStatus | string | null | undefined): boolean {
  return status === 'optimal' || status === 'feasible_with_gap' || status === 'time_limit' || status === 'heuristic_fallback'
}

export type SolverTone = 'success' | 'info' | 'warning' | 'danger'

export function solverTone(status: SolverStatus | string | null | undefined): SolverTone {
  switch (status) {
    case 'optimal':
      return 'success'
    case 'feasible_with_gap':
      return 'info'
    case 'infeasible':
    case 'not_solved':
      return 'danger'
    default:
      return 'warning'
  }
}

/** Brecha del solver: "0,0013 %". Desconocida → guion (no se inventa 0 %). */
export function formatGap(gap: number | null | undefined): string {
  if (gap == null || !Number.isFinite(gap)) return DASH
  if (gap === 0) return formatPercent(0, 2)
  return formatPercent(gap, gap < 0.0001 ? 4 : 2)
}

/**
 * Fecha de corte de un plan frente a HOY en la zona horaria de la empresa (calculada por la API: `as_of_age_days`).
 * `actionable_now` de una línea significa «se emite en la fecha de corte», no «hoy»: solo es «emitir ya» si el corte es hoy.
 */
export interface PlanCutoff {
  /** Último día con datos que usó el plan; null si se desconoce. */
  asOf: string | null
  /** Días entre la fecha de corte y hoy (0 = hoy); null si se desconoce. */
  ageDays: number | null
  /** El plan se calculó con datos de hoy: sus líneas del día de corte sí se pueden emitir ya. */
  isToday: boolean
  /** El plan se calculó con datos anteriores a hoy: conviene regenerarlo. */
  isStale: boolean
}

export function planCutoff(plan: Pick<SupplyPlanSummary, 'as_of' | 'as_of_age_days'> | null | undefined): PlanCutoff {
  const asOf = plan?.as_of ?? null
  const age = asOf != null && typeof plan?.as_of_age_days === 'number' && Number.isFinite(plan.as_of_age_days) ? plan.as_of_age_days : null
  return { asOf, ageDays: age, isToday: age !== null && age <= 0, isStale: age !== null && age > 0 }
}

export const SHORTFALL_REASONS = ['lead_time', 'budget', 'supplier_capacity', 'storage_capacity', 'resource_capacity', 'economic', 'no_offer', 'mixed'] as const

/** Causas de infactibilidad ordenadas por demanda recuperable al relajar cada restricción. */
export function infeasibilityCauses(inf: PlanInfeasibility | null | undefined): PlanInfeasibility['causes'] {
  return [...(inf?.causes ?? [])].sort((a, b) => b.units_recovered - a.units_recovered)
}

// ─── Formulario del plan ────────────────────────────────────────────────────

export interface PlanForm {
  objective: 'min_cost' | 'max_margin'
  coverage_mode: 'soft' | 'hard'
  horizon_days: number | null
  period_days: 1 | 7 | 14 | 30 | null
  shortage_penalty_multiplier: number | null
  safety_stock_weight: number | null
  holding_cost_rate_annual: number | null
  solver_time_limit_seconds: number | null
  use_implicit_offers: boolean
}

export const DEFAULT_PLAN_FORM: PlanForm = {
  objective: 'min_cost',
  coverage_mode: 'soft',
  horizon_days: 84,
  period_days: 7,
  shortage_penalty_multiplier: null,
  safety_stock_weight: null,
  holding_cost_rate_annual: null,
  solver_time_limit_seconds: null,
  use_implicit_offers: true,
}

export type PlanFormErrors = Partial<Record<'horizon_days' | 'shortage_penalty_multiplier' | 'safety_stock_weight' | 'holding_cost_rate_annual' | 'solver_time_limit_seconds', string>>

/** Mismas reglas que valida la API (PlanController): se anticipan para dar el error junto al campo. */
export function validatePlanForm(f: PlanForm): PlanFormErrors {
  const e: PlanFormErrors = {}
  if (f.horizon_days != null && (!Number.isInteger(f.horizon_days) || f.horizon_days < 14 || f.horizon_days > 365)) e.horizon_days = 'range14_365'
  if (f.shortage_penalty_multiplier != null && !(f.shortage_penalty_multiplier > 1 && f.shortage_penalty_multiplier <= 100)) e.shortage_penalty_multiplier = 'gt1'
  if (f.safety_stock_weight != null && !(f.safety_stock_weight >= 0 && f.safety_stock_weight < 1)) e.safety_stock_weight = 'lt1'
  if (f.holding_cost_rate_annual != null && !(f.holding_cost_rate_annual >= 0 && f.holding_cost_rate_annual <= 5)) e.holding_cost_rate_annual = 'range0_5'
  if (f.solver_time_limit_seconds != null && (!Number.isInteger(f.solver_time_limit_seconds) || f.solver_time_limit_seconds < 1 || f.solver_time_limit_seconds > 300)) e.solver_time_limit_seconds = 'range1_300'
  return e
}

/** Solicitud: solo se envía lo que la persona definió; lo demás lo decide la configuración efectiva. */
export function planRequestFromForm(f: PlanForm): PlanRequest {
  const config: NonNullable<PlanRequest['config']> = {}
  if (f.shortage_penalty_multiplier != null) config.shortage_penalty_multiplier = f.shortage_penalty_multiplier
  if (f.safety_stock_weight != null) config.safety_stock_weight = f.safety_stock_weight
  if (f.holding_cost_rate_annual != null) config.holding_cost_rate_annual = f.holding_cost_rate_annual
  if (f.solver_time_limit_seconds != null) config.solver_time_limit_seconds = f.solver_time_limit_seconds
  if (!f.use_implicit_offers) config.use_implicit_offers = false
  return {
    objective: f.objective,
    coverage_mode: f.coverage_mode,
    ...(f.horizon_days != null ? { horizon_days: f.horizon_days } : {}),
    ...(f.period_days != null ? { period_days: f.period_days } : {}),
    ...(Object.keys(config).length ? { config } : {}),
  }
}

// ─── Escenarios ────────────────────────────────────────────────────────────

export type ClassificationCounts = { observed: number; forecasted: number; assumed: number }

export function classificationCounts(result: ScenarioStrategyResult): ClassificationCounts {
  const out: ClassificationCounts = { observed: 0, forecasted: 0, assumed: 0 }
  for (const i of result.inputs ?? []) out[i.classification] += 1
  return out
}

// ─── Causalidad ────────────────────────────────────────────────────────────

export function causalCounts(check: CausalCheck | null | undefined): { met: number; notMet: number; declared: number } {
  const reqs = check?.requirements ?? []
  return {
    met: reqs.filter((r) => r.status === 'met').length,
    notMet: reqs.filter((r) => r.status === 'not_met').length,
    declared: reqs.filter((r) => r.status === 'declared_not_verifiable').length,
  }
}
