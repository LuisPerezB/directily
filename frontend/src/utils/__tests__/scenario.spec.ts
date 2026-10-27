import { describe, expect, it } from 'vitest'
import { DEFAULT_SCENARIO_FORM, assumptionsToForm, buildScenarioRows, formToAssumptions, isNeutral } from '../scenario'

describe('simulador: conversión de supuestos', () => {
  it('convierte porcentajes del formulario a proporciones y limita rangos', () => {
    const a = formToAssumptions({ ...DEFAULT_SCENARIO_FORM, price_change: -10, discount: 150, cost_change: 8, demand_change: 5, lead_time_days: 400, purchase_budget: 0, macro_note: '  ' })
    expect(a.price_change_pct).toBe(-0.1)
    expect(a.discount_pct).toBe(0.9)
    expect(a.cost_change_pct).toBe(0.08)
    expect(a.demand_change_pct).toBe(0.05)
    expect(a.lead_time_days).toBe(180)
    expect(a.purchase_budget).toBeNull()
    expect(a.macro_note).toBeNull()
  })

  it('ida y vuelta formulario ↔ supuestos', () => {
    const form = { ...DEFAULT_SCENARIO_FORM, price_change: 12.5, demand_change: -20, purchase_budget: 5000 }
    expect(assumptionsToForm(formToAssumptions(form))).toMatchObject({ price_change: 12.5, demand_change: -20, purchase_budget: 5000 })
  })

  it('detecta un escenario neutro', () => {
    expect(isNeutral(DEFAULT_SCENARIO_FORM)).toBe(true)
    expect(isNeutral({ ...DEFAULT_SCENARIO_FORM, discount: 5 })).toBe(false)
  })
})

describe('simulador: filas de resultados', () => {
  it('calcula diferencias absolutas y relativas', () => {
    const rows = buildScenarioRows({
      base: { units: 1000, revenue: 8000, cost: 5200, gross_margin: 2800, gross_margin_pct: 0.35, purchase_need_units: 300, purchase_need_cost: 1560 },
      scenario: { units: 1050, revenue: 7560, cost: 5460, gross_margin: 2100, gross_margin_pct: 0.2778, purchase_need_units: 350, purchase_need_cost: null },
    })
    const units = rows.find((r) => r.key === 'units')!
    expect(units.delta).toBe(50)
    expect(units.deltaPct).toBeCloseTo(0.05)
    const pct = rows.find((r) => r.key === 'gross_margin_pct')!
    expect(pct.deltaPct).toBeNull()
    expect(pct.delta).toBeCloseTo(-0.0722)
    expect(rows.find((r) => r.key === 'purchase_need_cost')!.delta).toBeNull()
  })
})
