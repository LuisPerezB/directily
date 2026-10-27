import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ScenarioResults from '../forecast/ScenarioResults.vue'
import type { ScenarioResult } from '@/api/types'

const result: ScenarioResult = {
  type: 'simulation',
  disclaimer: 'Simulación matemática basada en supuestos ingresados por el usuario.',
  base: { units: 1000, revenue: 8000, cost: 5200, gross_margin: 2800, gross_margin_pct: 0.35, purchase_need_units: 300, purchase_need_cost: 1560 },
  scenario: { units: 1100, revenue: 7920, cost: 5720, gross_margin: 2200, gross_margin_pct: 0.2778, purchase_need_units: 400, purchase_need_cost: 2080 },
  delta: { units: 100, revenue: -80, gross_margin: -600, gross_margin_pct: -0.0722 },
  budget: { purchase_budget: 1000, coverage_pct: 0.48, shortfall: 1080 },
  risks: [{ code: 'budget_shortfall', severity: 'warning', message: 'El presupuesto no cubre la compra necesaria.' }],
  assumptions_used: {},
  base_run: { id: 12, version: 5 },
}

describe('ScenarioResults', () => {
  it('muestra el aviso de que es una simulación', () => {
    const wrapper = mount(ScenarioResults, { props: { result, currency: 'USD' } })
    expect(wrapper.get('[data-testid="scenario-disclaimer"]').text()).toContain('Simulación matemática')
    expect(wrapper.text()).toContain('Simulación con supuestos')
  })

  it('muestra diferencias con signo y color según convenga', () => {
    const wrapper = mount(ScenarioResults, { props: { result, currency: 'USD' } })
    const units = wrapper.get('[data-testid="delta-units"]')
    expect(units.text()).toMatch(/^\+100/)
    expect(units.classes()).toContain('text-success')
    const margin = wrapper.get('[data-testid="delta-gross_margin"]')
    expect(margin.text()).toMatch(/^−/)
    expect(margin.classes()).toContain('text-danger')
    // Más costo de compra es desfavorable aunque el número suba.
    expect(wrapper.get('[data-testid="delta-purchase_need_cost"]').classes()).toContain('text-danger')
    expect(wrapper.get('[data-testid="delta-gross_margin_pct"]').text()).toMatch(/pp/)
  })

  it('informa cobertura del presupuesto y riesgos', () => {
    const wrapper = mount(ScenarioResults, { props: { result, currency: 'USD' } })
    expect(wrapper.text()).toContain('48')
    expect(wrapper.text()).toContain('El presupuesto no cubre la compra necesaria.')
    expect(wrapper.text()).toContain('versión 5')
  })
})
