import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import { setupTenant } from '@/test/helpers'
import type { CustomerPrediction, ResultEnvelope } from '@/api/types'

const api = vi.hoisted(() => ({ predictions: vi.fn(), reconciliation: vi.fn(), evaluation: vi.fn() }))
vi.mock('@/api', async (orig) => ({ ...(await orig<typeof import('@/api')>()), customerApi: { predictions: api.predictions, reconciliation: api.reconciliation, evaluation: api.evaluation } }))
vi.mock('vue-echarts', () => ({ default: { name: 'VChart', props: ['option'], template: '<div data-test="vchart" />' } }))

import CustomerPredictions from '../customers/CustomerPredictions.vue'
import CustomerReconciliation from '../customers/CustomerReconciliation.vue'
import CustomerEvaluation from '../customers/CustomerEvaluation.vue'

const envelope = (over: Partial<ResultEnvelope> = {}): ResultEnvelope => ({
  run_id: 5, core: 'customer', as_of: '2026-10-26', horizon_start: '2026-10-27', horizon_end: '2026-11-25', target_definition: null, entity_keys: ['customer_id', 'product_id'], unit: 'product_unit', currency: 'USD',
  result_type: 'forecast', model_version: null, data_snapshot_id: 9, config_version: 'cfg1234567890', assumptions: ['El precio es el de la última compra.'], limitations: ['Condicionada a que el producto esté disponible.'],
  quality_status: 'degraded', uncertainty_source: 'empirical_holdout', ...over,
})
const prediction = (over: Partial<CustomerPrediction> = {}): CustomerPrediction => ({
  customer: { id: 7, code: 'CLI-007' }, product: { id: 3, sku: 'CEM-50', name: 'Cemento 50 kg', unit: 'saco' }, horizon_days: 30, as_of: '2026-10-26', purchase_probability: 0.42, calibration_status: 'calibrated',
  probability_coherence_adjusted: false, quantity_if_purchase: 12, quantity_interval: { lo: 6, hi: 24, level: 0.8, method: 'conformal' }, expected_quantity: 5.04, committed_quantity: 0, incremental_quantity: 5.04, total_view_quantity: 5.04,
  expected_amount: { value: 42.8, currency: 'USD', assumed_unit_price: 8.5, basis: 'supuesto' }, median_interval_days: 30, days_since_last_purchase: 12, n_purchases: 6, model: { family: 'logistic', role: 'primary' }, quality_flags: [], ...over,
})
const page = (rows: CustomerPrediction[], env = envelope()) => ({ data: rows, meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 }, envelope: env, ranking_source: 'popularity', definitions: { purchase_probability: 'Probabilidad de al menos una compra válida.' } })

beforeEach(() => { api.predictions.mockReset(); api.reconciliation.mockReset(); api.evaluation.mockReset() })
const mountPredictions = (horizon: 7 | 30 | 90 = 30) => mount(CustomerPredictions, { props: { horizon }, global: { plugins: [setupTenant('analyst')] } })

describe('Compradores: predicciones', () => {
  it('rotula el monto como estimación con supuesto de precio, nunca como venta confirmada', async () => {
    api.predictions.mockResolvedValue(page([prediction(), prediction({ customer: { id: 8, code: 'CLI-008' } })]))
    const w = mountPredictions()
    await flushPromises()
    expect(w.get('[data-test="amount-notice"]').text()).toContain('no son ventas confirmadas')
    const labels = w.findAll('[data-test="amount-label"]')
    expect(labels).toHaveLength(2)
    expect(labels.every((l) => l.text() === 'Estimación con supuesto de precio, no es una venta confirmada')).toBe(true)
    expect(w.findAll('[data-test="amount"]')[0]!.text()).toMatch(/42,80/)
  })

  it('las probabilidades no se muestran como 0 % ni 100 % exactos', async () => {
    api.predictions.mockResolvedValue(page([prediction({ purchase_probability: 0.0004 }), prediction({ customer: { id: 8, code: 'B' }, purchase_probability: 0.99999 }), prediction({ customer: { id: 9, code: 'C' }, purchase_probability: 0.42 })]))
    const w = mountPredictions()
    await flushPromises()
    const probs = w.findAll('[data-test="prob"]').map((p) => p.text().replace(/\s/g, ''))
    expect(probs).toEqual(['<1%', '>99%', '42%'])
  })

  it('una fila sin probabilidad es una abstención, no un cero', async () => {
    api.predictions.mockResolvedValue(page([prediction({ purchase_probability: null, expected_amount: { value: null, currency: 'USD', assumed_unit_price: null, basis: 'supuesto' }, quality_flags: ['price_unknown'] })]))
    const w = mountPredictions()
    await flushPromises()
    expect(w.get('[data-test="prob-abstains"]').text()).toBe('Sin predicción')
    expect(w.get('[data-test="amount"]').text()).toBe('—')
    expect(w.text()).toContain('Precio desconocido')
  })

  it('muestra la calidad de la envoltura, sus limitaciones y supuestos, y la fuente del ranking', async () => {
    api.predictions.mockResolvedValue(page([prediction({ calibration_status: 'degraded', model: { family: 'reference', role: 'fallback' } })]))
    const w = mountPredictions()
    await flushPromises()
    expect(w.get('[data-test="envelope-limitations"]').text()).toContain('Condicionada a que el producto esté disponible.')
    expect(w.get('[data-test="envelope-assumptions"]').text()).toContain('El precio es el de la última compra.')
    expect(w.get('[data-test="quality-badge"]').text()).toBe('Con reservas')
    expect(w.get('[data-test="ranking-source"]').text()).toContain('popularidad')
    expect(w.text()).toContain('calibración degradada')
    expect(w.text()).toContain('respaldo')
  })

  it('422 horizon_not_available: estado explícito con los horizontes disponibles, sin cifras', async () => {
    api.predictions.mockRejectedValue(new ApiError(422, { message: 'sin predicciones a 90 días', code: 'horizon_not_available', available_horizons: [7, 30] } as never, 'x'))
    const w = mountPredictions(90)
    await flushPromises()
    const state = w.get('[data-test="horizon-not-available"]')
    expect(state.text()).toContain('Sin predicciones a 90 días')
    expect(w.find('[data-test="amount"]').exists()).toBe(false)
    const buttons = state.findAll('button')
    expect(buttons.map((b) => b.text())).toEqual(['Ver 7 días', 'Ver 30 días'])
    await buttons[1]!.trigger('click')
    expect(w.emitted('update:horizon')![0]).toEqual([30])
  })

  it('sin ninguna predicción y calidad "datos insuficientes" se abstiene en lugar de mostrar una tabla vacía', async () => {
    api.predictions.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 }, envelope: envelope({ quality_status: 'insufficient_data', run_id: null, limitations: ['Aún no hay una predicción.'] }) })
    const w = mountPredictions()
    await flushPromises()
    expect(w.get('[data-test="customer-abstention"]').text()).toContain('Aún no hay predicciones de compra')
    expect(w.find('[data-test="amount-notice"]').exists()).toBe(false)
    expect(w.text()).toContain('Datos insuficientes')
  })
})

describe('Compradores: conciliación', () => {
  const rec = (over: Record<string, unknown> = {}) => ({
    product: { id: 1, sku: 'CEM-50', name: 'Cemento', unit: 'saco' }, horizon_days: 30, status: 'ok', severity: null, aggregate_quantity: 100, aggregate_days: 30, buyers_total_quantity: 60, buyers_committed_quantity: 10,
    buyers_incremental_quantity: 50, buyers_expected_quantity: 55, n_buyers: 12, committed_outside_model: 10, residual_quantity: 30, residual_share: 0.3, excess_quantity: null, excess_ratio: null, ...over,
  })

  it('destaca las inconsistencias, muestra el exceso y no inventa un residual', async () => {
    api.reconciliation.mockResolvedValue({
      data: [rec({ product: { id: 2, sku: 'A', name: 'Ok', unit: 'u' } }), rec({ product: { id: 3, sku: 'B', name: 'Inconsistente', unit: 'u' }, status: 'inconsistent', severity: 'major', buyers_total_quantity: 140, residual_quantity: 0, excess_quantity: 50, excess_ratio: 0.5 }),
        rec({ product: { id: 4, sku: 'C', name: 'Sin agregado', unit: 'u' }, status: 'aggregate_unavailable', aggregate_quantity: null, residual_quantity: null, residual_share: null })],
      meta: { current_page: 1, per_page: 25, total: 3, last_page: 1 }, summary: { horizon_days: 30, status_counts: { ok: 1, inconsistent: 1, aggregate_unavailable: 1 } }, policy: 'agregado = compradores modelados + comprometido fuera + residual.', envelope: envelope(),
    })
    const w = mount(CustomerReconciliation, { props: { horizon: 30 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="inconsistent-banner"]').text()).toContain('1 productos con compradores por encima del agregado')
    const rows = w.findAll('tbody tr')
    expect(rows[0]!.attributes('data-status')).toBe('inconsistent')
    expect(rows[0]!.classes()).toContain('bg-danger-soft')
    expect(rows[0]!.get('[data-test="excess"]').text()).toMatch(/exceso 50 \(50\s?%\)/)
    expect(rows[2]!.text()).toContain('No se calcula')
    expect(w.get('[data-test="reconciliation-policy"]').text()).toContain('compradores modelados')
  })
})

describe('Compradores: evaluación', () => {
  const evalData = (tier: string, metrics: Record<string, unknown> = {}) => ({
    data: {
      run_id: 1, as_of: '2026-10-26', metrics,
      manifest: { protocol_version: 'cust-protocol-2026.10-v1', labels_mature_through: '2026-10-26', horizons: { '30': { tier, status: 'trained', reason: tier === 'abstained' ? 'Menos de 100 compras positivas para entrenar.' : null, horizon_days: 30,
        blocks: { train: { cutoff_from: '2025-01-27', cutoff_to: '2025-11-03', label_window_from: '2025-01-28', label_window_to: '2025-12-03', n_cutoffs: 21 }, holdout: { cutoff_from: '2026-06-29', cutoff_to: '2026-09-21', label_window_from: '2026-06-30', label_window_to: '2026-10-21', n_cutoffs: 7 } },
        block_rows: { train: 202778, holdout: 100118 }, block_positives: { train: 11830, holdout: 6215 }, overlapping_origins: true, overlap_note: 'Los cortes consecutivos se solapan.' } } },
      notes: ['Los datos sintéticos comprueban el procedimiento.'],
    },
    envelope: envelope({ result_type: 'evaluation' }),
  })
  const probRow = (family: string, baseline: boolean, selected: boolean, brier: number, prAuc: number, n: number) => ({
    model_family: family, evaluation_type: 'holdout', is_baseline: baseline, is_selected: selected, horizon_days: 30, n_obs: n, mae: null, bias: null, wape: null, rmse: null, coverage_80: null, extra: null,
    metrics: { n, n_positive: 6215, ece: 0.006, brier, pr_auc: prAuc, log_loss: 0.233, base_rate: 0.062, brier_skill_vs_climatology: -0.0004, calibration_curve: [
      { n: 20000, mean_predicted: 0.0583, observed_rate: 0.0612 }, { n: 20000, mean_predicted: 0.0583, observed_rate: 0.0624 }, { n: 12, mean_predicted: 0.0583, observed_rate: 0.1 }] },
  })

  it('muestra el manifiesto con fechas por bloque, Brier/log loss/PR-AUC frente a la referencia y la curva con n por grupo', async () => {
    api.evaluation.mockResolvedValue(evalData('full', { 'h30:probability': [probRow('customer_prob_reference', true, true, 0.05827, 0.0622, 100118), probRow('customer_prob_logistic', false, false, 0.05763, 0.0905, 100118)] }))
    const w = mount(CustomerEvaluation, { props: { horizon: 30 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="tier"]').text()).toBe('Protocolo completo')
    expect(w.get('[data-block="holdout"]').text()).toContain('2026')
    expect(w.get('[data-block="train"]').text()).toMatch(/202\.?778/)
    const prob = w.get('[data-test="probability-metrics"]')
    expect(prob.text()).toContain('0,05827')
    expect(prob.text()).toContain('0,0905')
    expect(prob.text()).toContain('referencia')
    expect(prob.text()).toContain('servido')
    expect(w.findAll('[data-test="calibration-row-small"]')).toHaveLength(1)
    expect(w.get('[data-test="brier-skill"]').text()).toContain('Mejora del Brier')
    expect(w.text()).toContain('Los datos sintéticos comprueban el procedimiento.')
  })

  it('un horizonte sin entrenar (abstención) lo explica y no muestra métricas', async () => {
    api.evaluation.mockResolvedValue(evalData('abstained'))
    const w = mount(CustomerEvaluation, { props: { horizon: 30 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="tier"]').text()).toBe('Sin entrenar')
    expect(w.text()).toContain('Menos de 100 compras positivas para entrenar.')
    expect(w.find('[data-test="no-metrics"]').exists()).toBe(true)
    expect(w.find('[data-test="probability-metrics"]').exists()).toBe(false)
  })

  it('solo referencia se presenta con reservas, no como un modelo completo', async () => {
    api.evaluation.mockResolvedValue(evalData('reference_only'))
    const w = mount(CustomerEvaluation, { props: { horizon: 30 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="tier"]').text()).toBe('Solo referencia')
    expect(w.get('[data-test="tier"]').classes()).toContain('badge-warning')
  })

  it('sin entrenamiento previo: estado vacío explícito', async () => {
    api.evaluation.mockResolvedValue({ data: { manifest: null, metrics: {}, models: [] }, envelope: envelope({ quality_status: 'insufficient_data' }) })
    const w = mount(CustomerEvaluation, { props: { horizon: 30 }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="evaluation-abstention"]').text()).toContain('Aún no hay una evaluación')
  })
})
