import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import { setupTenant } from '@/test/helpers'
import type { CausalCheck, PlanDetail, PlanLine, ResultEnvelope, ScenarioEvaluationResult } from '@/api/types'

const api = vi.hoisted(() => ({ plan: vi.fn(), causalCheck: vi.fn(), evaluateScenarios: vi.fn(), plans: vi.fn() }))
vi.mock('@/api', async (orig) => ({ ...(await orig<typeof import('@/api')>()), supplyApi: { plan: api.plan, causalCheck: api.causalCheck, evaluateScenarios: api.evaluateScenarios, plans: api.plans } }))

import SolverStatus from '../supply/SolverStatus.vue'
import PlanResults from '../supply/PlanResults.vue'
import CausalTab from '../supply/CausalTab.vue'
import ScenarioEvalTab from '../supply/ScenarioEvalTab.vue'

const envelope = (over: Partial<ResultEnvelope> = {}): ResultEnvelope => ({
  run_id: 3, core: 'supply', as_of: '2026-10-26', horizon_start: '2026-10-27', horizon_end: '2027-01-19', target_definition: null, entity_keys: ['branch_id', 'product_id', 'supplier_id'], unit: 'product_unit', currency: 'USD',
  result_type: 'optimized_plan', model_version: null, data_snapshot_id: 4, config_version: 'cfg-supply-1', assumptions: ['Costo de mantener: 20 % anual (supuesto).'], limitations: ['Solo ventas perdidas, sin pedidos pendientes de clientes.'],
  quality_status: 'ok', uncertainty_source: 'model', ...over,
})
const line = (over: Partial<PlanLine> = {}): PlanLine => ({
  line_type: 'purchase', branch_id: 1, branch: { id: 1, code: '001', name: 'Matriz' }, product_id: 1, product: { id: 1, sku: 'CEM-50', name: 'Cemento 50 kg' }, supplier_id: 2, supplier: { id: 2, code: 'P-2', name: 'Cementos del Sur' },
  offer_id: 5, quantity: 200, unit: 'saco', offer_quantity: 4, offer_unit: 'pallet', order_date: '2026-10-27', expected_receipt_date: '2026-11-03', unit_cost: 8.1, line_cost: 1620, currency: 'USD',
  actionable_now: false, binding_constraints: [], justification: 'Menor costo puesto en bodega y llega a tiempo.', assumptions: null, ...over,
})
const detail = (over: Partial<PlanDetail> = {}, planOver: Record<string, unknown> = {}): PlanDetail => ({
  run: { id: 3, error_message: null } as never,
  plan: { id: 1, objective: 'min_cost', coverage_mode: 'soft', solver_status: 'feasible_with_gap', mip_gap: 0.0042, objective_value: 1000, solve_seconds: 12.4, currency: 'USD', total_purchase_cost: 5000, total_holding_cost: 300, total_shortage_penalty: 120,
    unmet_units_total: 40, demand_units_total: 800, forecast_run_id: 9, is_optimal: false, note: 'Sugerencias.', ...planOver } as never,
  lines: [line({ actionable_now: true, binding_constraints: [{ family: 'budget', ref: 1, period: 'x', used: 5000, limit: 5000 }] }), line({ product_id: 2, order_date: '2026-11-03' })],
  shortfalls: [{ branch_id: 1, product_id: 4, product: { id: 4, sku: 'CLA-1', name: 'Clavos' }, period_start: '2026-10-27', period_end: '2026-11-02', demand: 50, unmet: 40, reason: 'lead_time' }],
  constraints: [{ family: 'budget', ref: 1, period: 'x', used: 5000, limit: 5000, binding: true }], solver: { status: 'feasible_with_gap', notes: ['Brecha dentro de la tolerancia pedida.'] }, diagnosis: null, infeasibility: null, alternative_plan: null,
  offers: [{ offer_id: 9, supplier_id: 3, product_id: 1, status: 'excluded', reasons: ['no_fx_rate'], messages: [] }, { offer_id: 5, supplier_id: 2, product_id: 1, status: 'comparable', reasons: [], messages: [] }],
  comparison_coverage: { offers_received: 2, offers_comparable: 1, offers_excluded: 1, comparable_share: 0.5 }, offer_diagnostics: [], excluded_nodes: [], production: { status: 'not_configured' }, periods: [], ...over,
})

beforeEach(() => { for (const f of Object.values(api)) f.mockReset() })

describe('SolverStatus: nunca «óptimo» si no lo es', () => {
  it('el óptimo probado se llama así y no lleva advertencia', () => {
    const w = mount(SolverStatus, { props: { status: 'optimal', gap: 0.00001 } })
    expect(w.get('[data-test="solver-label"]').text()).toBe('Óptimo probado')
    expect(w.find('[data-test="solver-warning"]').exists()).toBe(false)
  })

  it.each(['feasible_with_gap', 'time_limit', 'heuristic_fallback', 'infeasible', 'not_solved', 'inventado'])('%s no se llama óptimo y advierte que no está probado', (status) => {
    const w = mount(SolverStatus, { props: { status, gap: 0.02 } })
    expect(w.get('[data-test="solver-label"]').text()).not.toMatch(/ptimo/)
    expect(w.get('[data-test="solver-warning"]').text()).toContain('no tiene un óptimo probado')
  })

  it('muestra la brecha cuando se conoce y no la inventa cuando no', () => {
    expect(mount(SolverStatus, { props: { status: 'feasible_with_gap', gap: 0.0042 } }).get('[data-test="solver-gap"]').text()).toMatch(/0,42/)
    expect(mount(SolverStatus, { props: { status: 'time_limit', gap: null } }).find('[data-test="solver-gap"]').exists()).toBe(false)
  })
})

describe('PlanResults', () => {
  it('muestra las líneas con proveedor, fechas de emisión y recepción, costo, restricciones y lo que se puede emitir hoy', async () => {
    api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
    const w = mount(PlanResults, { props: { runId: 3 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="no-orders-notice"]').text()).toContain('no envía pedidos ni compromete fondos')
    expect(w.get('[data-test="solver-label"]').text()).toBe('Factible con brecha')
    const rows = w.findAll('[data-test="plan-lines"] tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.attributes('data-actionable')).toBe('true')
    expect(rows[0]!.text()).toContain('Cementos del Sur')
    expect(rows[0]!.text()).toContain('Emitir hoy')
    expect(rows[0]!.text()).toMatch(/1\.?620/)
    expect(rows[0]!.text()).toContain('pallet')
    expect(rows[0]!.get('[data-test="binding"]').text()).toBe('Presupuesto')
    expect(rows[0]!.text()).toContain('Menor costo puesto en bodega')
    expect(w.findAll('[data-test="actionable-now"]')).toHaveLength(1)
  })

  it('la demanda no atendida se explica con su causa y los costos asumidos se rotulan como supuestos', async () => {
    api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
    const w = mount(PlanResults, { props: { runId: 3 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="shortfall-reason"]').text()).toBe('El proveedor tarda más de lo que se puede esperar')
    expect(w.get('[data-test="shortfalls"]').text()).toContain('Clavos')
    expect(w.get('[data-test="unmet-total"]').text()).toBe('40')
    expect(w.get('[data-test="plan-totals"]').text()).toContain('supuesto, no costo observado')
  })

  it('lista las ofertas excluidas con su motivo y declara la producción sin configurar', async () => {
    api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
    const w = mount(PlanResults, { props: { runId: 3 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="excluded-offers"]').text()).toContain('Falta una tasa de cambio explícita')
    expect(w.get('[data-test="offers-coverage"]').text()).toContain('2 ofertas recibidas: 1 comparables y 1 excluidas')
    expect(w.get('[data-test="production-not-configured"]').text()).toContain('Producción no configurada')
  })

  it('muestra la calidad, las limitaciones y los supuestos de la envoltura', async () => {
    api.plan.mockResolvedValue({ data: detail(), envelope: envelope({ quality_status: 'degraded' }) })
    const w = mount(PlanResults, { props: { runId: 3 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="quality-badge"]').text()).toBe('Con reservas')
    expect(w.get('[data-test="envelope-limitations"]').text()).toContain('Solo ventas perdidas')
    expect(w.get('[data-test="envelope-assumptions"]').text()).toContain('supuesto')
  })

  it('infactible: bloque con causas ordenadas, requisito y plan de máxima cobertura; sin tabla de líneas', async () => {
    api.plan.mockResolvedValue({
      data: detail({
        lines: [], shortfalls: [],
        infeasibility: { message: 'Con cobertura obligatoria no existe un plan que respete todas las restricciones.', alternatives: [], structural: { family: 'structural', label: 'plazos de entrega', unmet_even_without_constraints: 10 }, causes: [
          { family: 'budget', label: 'presupuesto de compra', units_recovered: 5, unmet_with_constraint: 60, unmet_if_relaxed: 55 },
          { family: 'storage', label: 'capacidad de almacenamiento', units_recovered: 45, unmet_with_constraint: 60, unmet_if_relaxed: 15, required: [{ needed: 900, declared: 500 }] }] },
        alternative_plan: { description: 'Plan de máxima cobertura factible: respeta todas las restricciones y declara lo no atendido.', summary: { unmet_units_total: 60 }, lines: [line()], shortfalls: [] },
      }, { solver_status: 'infeasible', mip_gap: null, total_purchase_cost: null, is_optimal: false }),
      envelope: envelope({ quality_status: 'degraded' }),
    })
    const w = mount(PlanResults, { props: { runId: 3 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="solver-label"]').text()).toBe('Sin plan posible')
    const block = w.get('[data-test="infeasibility"]')
    expect(block.text()).toContain('No existe un plan que respete todas las restricciones')
    const causes = block.findAll('tbody tr')
    expect(causes[0]!.text()).toContain('capacidad de almacenamiento')
    expect(causes[0]!.text()).toContain('se necesitan 900 y se declararon 500')
    expect(block.get('[data-test="alternative-plan"]').text()).toContain('máxima cobertura')
    expect(w.find('[data-test="plan-lines"]').exists()).toBe(false)
  })

  it('sin plan (datos insuficientes): abstención explícita y calidad visible', async () => {
    api.plan.mockResolvedValue({ data: detail({ plan: null, lines: [], shortfalls: [], run: { id: 3, error_message: 'No hay un pronóstico de demanda completado.' } as never }), envelope: envelope({ quality_status: 'insufficient_data', limitations: ['No hay un pronóstico de demanda completado.'] }) })
    const w = mount(PlanResults, { props: { runId: 3 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="plan-abstention"]').text()).toContain('No hay plan')
    expect(w.text()).toContain('No hay un pronóstico de demanda completado.')
    expect(w.find('[data-test="plan-lines"]').exists()).toBe(false)
    expect(w.get('[data-test="quality-badge"]').text()).toBe('Datos insuficientes')
  })
})

describe('Verificación causal', () => {
  const check: CausalCheck = {
    contract_version: 'causal-contract-2026.10', verdict: 'insufficient_evidence', effect: null, estimator_enabled: false, eligible_for_estimator_evaluation: false, reason_codes: ['price_variation:not_met'],
    requirements: [
      { id: 'effect_definition', label: 'Definición del efecto', status: 'met', evidence: {}, how_to_fix: null },
      { id: 'price_variation', label: 'Variación de precios', status: 'not_met', evidence: {}, how_to_fix: 'Se necesita más historia con cambios de precio distintos y repetidos.' },
      { id: 'identification', label: 'Supuestos de identificación', status: 'declared_not_verifiable', evidence: {}, how_to_fix: null },
      { id: 'estimator', label: 'Estimador', status: 'not_met', evidence: {}, how_to_fix: 'Se habilita solo tras cumplir los requisitos anteriores.' },
    ],
    message: 'No se publica ningún efecto causal: no se cumplen los requisitos de identificación (ver requisitos).',
    conditional_simulation: { available: true, endpoint: '/api/v1/supply/scenarios/evaluate', note: 'La simulación condicionada usa supuestos declarados.' },
    predictive_vs_causal: 'El error predictivo no estima la reacción causal de la demanda al precio.',
  }

  it('siempre «evidencia insuficiente»: sin efecto numérico, con la lista de requisitos y cómo corregirlos', async () => {
    api.causalCheck.mockResolvedValue({ data: check, envelope: envelope({ result_type: 'causal_estimate', quality_status: 'insufficient_evidence' }) })
    const w = mount(CausalTab, { global: { plugins: [setupTenant('analyst')] } })
    await w.get('form').trigger('submit')
    await flushPromises()
    const verdict = w.get('[data-test="causal-verdict"]')
    expect(verdict.text()).toContain('Evidencia insuficiente')
    expect(w.get('[data-test="causal-no-effect"]').text()).toBe('No se publica ningún efecto causal.')
    expect(verdict.text()).toContain('Ningún estimador causal está habilitado')
    const reqs = w.findAll('[data-requirement]')
    expect(reqs.map((r) => r.attributes('data-status'))).toEqual(['met', 'not_met', 'declared_not_verifiable', 'not_met'])
    expect(reqs[1]!.text()).toContain('Se necesita más historia con cambios de precio')
    expect(w.text()).toContain('El error predictivo no estima la reacción causal')
    expect(w.text()).toContain('1 requisitos cumplidos, 2 sin cumplir y 1 declarados')
  })

  it('envía lo declarado por la persona y el cambio de precio como fracción', async () => {
    api.causalCheck.mockResolvedValue({ data: check, envelope: envelope({ quality_status: 'insufficient_evidence' }) })
    const w = mount(CausalTab, { global: { plugins: [setupTenant('analyst')] } })
    await w.get('#ca-price').setValue('10')
    await w.find('input[type="checkbox"][value="promotions"]').setValue(true)
    await w.get('form').trigger('submit')
    await flushPromises()
    const body = api.causalCheck.mock.calls[0]![0]
    expect(body.price_change_pct).toBeCloseTo(0.1, 9)
    expect(body.confounders_controlled).toEqual(['promotions'])
    expect(body.unobserved_confounders_acknowledged).toEqual([])
  })

  it('la definición del efecto no viene prellenada: lo que no se declara no se da por cumplido', async () => {
    api.causalCheck.mockResolvedValue({ data: check, envelope: envelope({ quality_status: 'insufficient_evidence' }) })
    const w = mount(CausalTab, { global: { plugins: [setupTenant('analyst')] } })
    await w.get('form').trigger('submit')
    await flushPromises()
    const body = api.causalCheck.mock.calls[0]![0]
    expect(body.treatment).toBeNull()
    expect(body.outcome).toBeNull()
    expect(body.horizon_days).toBeNull()
    expect(body.confounders_controlled).toEqual([])
    expect(body.identification_assumptions_acknowledged).toEqual([])
  })

  it('sin permiso de ejecución no ofrece el formulario', () => {
    const w = mount(CausalTab, { global: { plugins: [setupTenant('viewer')] } })
    expect(w.find('[data-test="causal-check"]').exists()).toBe(false)
  })
})

describe('Evaluador de escenarios', () => {
  const result: ScenarioEvaluationResult = {
    run_id: 8, status: 'completed', name: null, horizon_days: 30, request: null,
    results: {
      common_basis: { as_of: '2026-10-26', horizon_days: 30, period_days: 7, currency: 'USD', series: 12, note: 'Todas las estrategias parten del mismo corte.' },
      strategies: [
        { key: 'base', name: 'Escenario base', metrics: { demand_units: 100, attainable_sales_units: 95, unmet_units: 5, fill_rate: 0.95, revenue: 950, cogs: 600, gross_margin: 350, gross_margin_pct: 0.368, revenue_without_known_cost: 0, ending_inventory_units: 10, ending_inventory_value: 80, plan_purchase_outlay: null, units_note: null },
          inputs: [{ input: 'demand_base', classification: 'forecasted', source: 'pronóstico' }, { input: 'price_base', classification: 'observed', source: 'ventas' }] },
        { key: 's2', name: 'Más precio', metrics: { demand_units: 90, attainable_sales_units: 90, unmet_units: 0, fill_rate: 1, revenue: 990, cogs: null, gross_margin: null, gross_margin_pct: null, revenue_without_known_cost: 990, ending_inventory_units: 10, ending_inventory_value: 80, plan_purchase_outlay: null, units_note: null },
          inputs: [{ input: 'price_change', classification: 'assumed', source: 'trayectoria declarada por el usuario' }] },
      ],
      ranking_by_gross_margin: ['base'], assumed_inputs: ['price_change'], classification_summary: { observed: ['price_base'], forecasted: ['demand_base'], assumed: ['price_change'] },
      disclaimer: 'Simulación condicionada a las entradas declaradas. El cambio de demanda es un supuesto y NO se deriva del cambio de precio; no es una estimación causal.',
    },
  }

  it('compara estrategias, clasifica las entradas y deja claro que no es causal', async () => {
    api.plans.mockResolvedValue({ data: [], meta: {} })
    api.evaluateScenarios.mockResolvedValue({ data: result, envelope: envelope({ result_type: 'conditional_simulation', core: 'scenario' }) })
    const w = mount(ScenarioEvalTab, { global: { plugins: [setupTenant('analyst')] } })
    expect(w.get('[data-test="not-causal"]').text()).toContain('no se calcula a partir del cambio de precio')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(w.get('[data-test="scenario-disclaimer"]').text()).toContain('no es una estimación causal')
    const classes = w.findAll('[data-classification]').map((e) => e.attributes('data-classification'))
    expect(classes).toEqual(['forecasted', 'observed', 'assumed'])
    expect(w.get('[data-test="margin-row"]').text()).toContain('margen desconocido')
    expect(w.get('[data-test="ranking"]').text()).toContain('Escenario base')
    expect(w.get('[data-test="ranking"]').text()).not.toContain('Más precio')
  })

  it('los porcentajes se envían como fracciones y 402 explica el plan', async () => {
    api.plans.mockResolvedValue({ data: [], meta: {} })
    api.evaluateScenarios.mockRejectedValue(new ApiError(402, { message: 'plan', code: 'plan_limit_exceeded' }, 'x'))
    const w = mount(ScenarioEvalTab, { global: { plugins: [setupTenant('analyst')] } })
    await w.get('#sc-price-1').setValue('10')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(api.evaluateScenarios.mock.calls[0]![0].strategies[1].price_change_pct).toBeCloseTo(0.1, 9)
    expect(w.get('[data-test="scenario-error"]').text()).toContain('no incluye el evaluador de escenarios')
  })
})
