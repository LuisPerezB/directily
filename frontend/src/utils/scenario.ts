import type { ScenarioAssumptions, ScenarioFigures, ScenarioResult } from '@/api/types'

/** Utilidades puras del simulador (la API hace el cálculo; aquí solo se presenta). */

export type RowKind = 'units' | 'money' | 'pct'

export interface ScenarioRow {
  key: keyof ScenarioFigures
  kind: RowKind
  base: number | null
  scenario: number | null
  delta: number | null
  /** Variación relativa (escenario/base − 1), si aplica. */
  deltaPct: number | null
}

const ROWS: { key: keyof ScenarioFigures; kind: RowKind }[] = [
  { key: 'units', kind: 'units' },
  { key: 'revenue', kind: 'money' },
  { key: 'cost', kind: 'money' },
  { key: 'gross_margin', kind: 'money' },
  { key: 'gross_margin_pct', kind: 'pct' },
  { key: 'purchase_need_units', kind: 'units' },
  { key: 'purchase_need_cost', kind: 'money' },
]

export function buildScenarioRows(result: Pick<ScenarioResult, 'base' | 'scenario'>): ScenarioRow[] {
  return ROWS.map(({ key, kind }) => {
    const base = result.base?.[key] ?? null
    const scenario = result.scenario?.[key] ?? null
    const delta = base !== null && scenario !== null ? scenario - base : null
    const deltaPct = kind !== 'pct' && base !== null && scenario !== null && base !== 0 ? scenario / base - 1 : null
    return { key, kind, base, scenario, delta, deltaPct }
  })
}

/** Formulario en porcentajes (−10 = −10 %) → supuestos como proporción (−0.1). */
export interface ScenarioForm {
  price_change: number
  discount: number
  cost_change: number
  demand_change: number
  lead_time_days: number | null
  purchase_budget: number | null
  macro_note: string
}

export const DEFAULT_SCENARIO_FORM: ScenarioForm = {
  price_change: 0,
  discount: 0,
  cost_change: 0,
  demand_change: 0,
  lead_time_days: null,
  purchase_budget: null,
  macro_note: '',
}

export const LIMITS = {
  price_change: { min: -50, max: 100 },
  discount: { min: 0, max: 90 },
  cost_change: { min: -50, max: 100 },
  demand_change: { min: -90, max: 200 },
} as const

function clamp(value: number, min: number, max: number): number {
  if (!Number.isFinite(value)) return 0
  return Math.min(max, Math.max(min, value))
}

function round4(value: number): number {
  return Math.round(value * 10000) / 10000
}

export function formToAssumptions(form: ScenarioForm): ScenarioAssumptions {
  return {
    price_change_pct: round4(clamp(form.price_change, LIMITS.price_change.min, LIMITS.price_change.max) / 100),
    discount_pct: round4(clamp(form.discount, LIMITS.discount.min, LIMITS.discount.max) / 100),
    cost_change_pct: round4(clamp(form.cost_change, LIMITS.cost_change.min, LIMITS.cost_change.max) / 100),
    demand_change_pct: round4(clamp(form.demand_change, LIMITS.demand_change.min, LIMITS.demand_change.max) / 100),
    lead_time_days: form.lead_time_days && form.lead_time_days > 0 ? Math.min(180, Math.round(form.lead_time_days)) : null,
    purchase_budget: form.purchase_budget && form.purchase_budget > 0 ? form.purchase_budget : null,
    macro_note: form.macro_note.trim() || null,
  }
}

export function assumptionsToForm(a: ScenarioAssumptions): ScenarioForm {
  return {
    price_change: Math.round((a.price_change_pct ?? 0) * 1000) / 10,
    discount: Math.round((a.discount_pct ?? 0) * 1000) / 10,
    cost_change: Math.round((a.cost_change_pct ?? 0) * 1000) / 10,
    demand_change: Math.round((a.demand_change_pct ?? 0) * 1000) / 10,
    lead_time_days: a.lead_time_days ?? null,
    purchase_budget: a.purchase_budget ?? null,
    macro_note: a.macro_note ?? '',
  }
}

export function isNeutral(form: ScenarioForm): boolean {
  return form.price_change === 0 && form.discount === 0 && form.cost_change === 0 && form.demand_change === 0 && !form.lead_time_days && !form.purchase_budget
}
