import { describe, expect, it } from 'vitest'
import {
  causalCounts, classificationCounts, DEFAULT_PLAN_FORM, formatGap, infeasibilityCauses, isProvenOptimal, planRequestFromForm, planTotals,
  shortfallTotals, solverHasPlan, solverTone, sortPlanLines, validatePlanForm,
} from '../supply'
import type { CausalCheck, PlanLine, ScenarioStrategyResult } from '@/api/types'

const line = (over: Partial<PlanLine> = {}): PlanLine => ({
  line_type: 'purchase', branch_id: 1, product_id: 1, supplier_id: 1, offer_id: 1, quantity: 10, unit: 'saco', offer_quantity: 10, offer_unit: 'saco',
  order_date: '2026-11-02', expected_receipt_date: '2026-11-09', unit_cost: 8, line_cost: 80, currency: 'USD', actionable_now: false, binding_constraints: [], justification: null, assumptions: null, ...over,
})

describe('estado del solver: nunca se llama óptimo a lo que no lo es', () => {
  it('solo "optimal" es un óptimo probado', () => {
    expect(isProvenOptimal('optimal')).toBe(true)
    for (const s of ['feasible_with_gap', 'time_limit', 'infeasible', 'heuristic_fallback', 'not_solved', null, undefined, 'x']) expect(isProvenOptimal(s)).toBe(false)
  })

  it('infeasible y not_solved no tienen plan; los demás sí', () => {
    expect(solverHasPlan('optimal')).toBe(true)
    expect(solverHasPlan('feasible_with_gap')).toBe(true)
    expect(solverHasPlan('time_limit')).toBe(true)
    expect(solverHasPlan('heuristic_fallback')).toBe(true)
    expect(solverHasPlan('infeasible')).toBe(false)
    expect(solverHasPlan('not_solved')).toBe(false)
  })

  it('tonos: éxito solo para el óptimo; peligro para sin plan', () => {
    expect(solverTone('optimal')).toBe('success')
    expect(solverTone('infeasible')).toBe('danger')
    expect(solverTone('time_limit')).toBe('warning')
    expect(solverTone('feasible_with_gap')).toBe('info')
  })

  it('la brecha desconocida no se muestra como 0 %', () => {
    expect(formatGap(null)).toBe('—')
    expect(formatGap(undefined)).toBe('—')
    expect(formatGap(0.0013)).toMatch(/0,13\s?%/)
    expect(formatGap(0.00001)).toMatch(/0,001\s?%/)
  })
})

describe('líneas del plan', () => {
  it('lo que se puede emitir hoy va primero y luego por fecha de emisión', () => {
    const sorted = sortPlanLines([
      line({ product_id: 1, order_date: '2026-11-09' }), line({ product_id: 2, order_date: '2026-11-02', actionable_now: true }),
      line({ product_id: 3, order_date: '2026-11-04' }), line({ product_id: 4, order_date: '2026-10-30', actionable_now: true }),
    ])
    expect(sorted.map((l) => l.product_id)).toEqual([4, 2, 3, 1])
  })

  it('totales: líneas, emisibles hoy y costo; sin costos conocidos el total es desconocido', () => {
    expect(planTotals([line({ line_cost: 80, actionable_now: true }), line({ line_cost: 20 })])).toEqual({ lines: 2, actionableNow: 1, cost: 100 })
    expect(planTotals([line({ line_cost: null })]).cost).toBeNull()
    expect(planTotals([])).toEqual({ lines: 0, actionableNow: 0, cost: null })
  })

  it('demanda no atendida: totales y proporción', () => {
    expect(shortfallTotals([{ unmet: 10, demand: 40 }, { unmet: 0, demand: 60 }])).toEqual({ unmet: 10, demand: 100, share: 0.1 })
    expect(shortfallTotals([]).share).toBeNull()
  })

  it('las causas de infactibilidad se ordenan por unidades recuperables', () => {
    const causes = infeasibilityCauses({ message: 'x', alternatives: [], causes: [
      { family: 'budget', label: 'Presupuesto', units_recovered: 5, unmet_with_constraint: 50, unmet_if_relaxed: 45 },
      { family: 'storage', label: 'Almacén', units_recovered: 30, unmet_with_constraint: 50, unmet_if_relaxed: 20 },
    ] })
    expect(causes.map((c) => c.family)).toEqual(['storage', 'budget'])
    expect(infeasibilityCauses(null)).toEqual([])
  })
})

describe('formulario del plan', () => {
  it('los valores por defecto son válidos', () => {
    expect(validatePlanForm(DEFAULT_PLAN_FORM)).toEqual({})
  })

  it('anticipa las reglas de la API junto al campo', () => {
    const e = validatePlanForm({ ...DEFAULT_PLAN_FORM, horizon_days: 10, shortage_penalty_multiplier: 1, safety_stock_weight: 1, holding_cost_rate_annual: 6, solver_time_limit_seconds: 0 })
    expect(Object.keys(e).sort()).toEqual(['holding_cost_rate_annual', 'horizon_days', 'safety_stock_weight', 'shortage_penalty_multiplier', 'solver_time_limit_seconds'])
    expect(validatePlanForm({ ...DEFAULT_PLAN_FORM, shortage_penalty_multiplier: 2, safety_stock_weight: 0.9 })).toEqual({})
  })

  it('solo envía lo que la persona definió', () => {
    expect(planRequestFromForm(DEFAULT_PLAN_FORM)).toEqual({ objective: 'min_cost', coverage_mode: 'soft', horizon_days: 84, period_days: 7 })
    const req = planRequestFromForm({ ...DEFAULT_PLAN_FORM, coverage_mode: 'hard', shortage_penalty_multiplier: 3, use_implicit_offers: false })
    expect(req.coverage_mode).toBe('hard')
    expect(req.config).toEqual({ shortage_penalty_multiplier: 3, use_implicit_offers: false })
  })
})

describe('escenarios y causalidad', () => {
  it('cuenta las entradas observadas, pronosticadas y supuestas', () => {
    const r = { inputs: [
      { input: 'demand_base', classification: 'forecasted', source: '' }, { input: 'price_base', classification: 'observed', source: '' },
      { input: 'opening_inventory', classification: 'observed', source: '' }, { input: 'demand_change', classification: 'assumed', source: '' },
    ] } as unknown as ScenarioStrategyResult
    expect(classificationCounts(r)).toEqual({ observed: 2, forecasted: 1, assumed: 1 })
  })

  it('cuenta requisitos causales por estado', () => {
    const check = { requirements: [{ status: 'met' }, { status: 'not_met' }, { status: 'not_met' }, { status: 'declared_not_verifiable' }] } as unknown as CausalCheck
    expect(causalCounts(check)).toEqual({ met: 1, notMet: 2, declared: 1 })
    expect(causalCounts(null)).toEqual({ met: 0, notMet: 0, declared: 0 })
  })
})
