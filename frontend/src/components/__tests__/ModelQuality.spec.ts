import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { setupTenant } from '@/test/helpers'
import { useCatalogStore } from '@/stores/catalog'
import type {
  CustomerPrediction, CustomerProfile, EffectiveConfig, ModelQualityEvaluation, PromotionDecision, PromotionDecisionsPage, ShadowEvaluationsPage,
} from '@/api/types'

const api = vi.hoisted(() => ({
  evaluations: vi.fn(), promotionDecisions: vi.fn(), shadowEvaluations: vi.fn(), predictions: vi.fn(), profile: vi.fn(), effective: vi.fn(), saveOverrides: vi.fn(), extensions: vi.fn(),
}))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  modelQualityApi: { evaluations: api.evaluations, promotionDecisions: api.promotionDecisions, shadowEvaluations: api.shadowEvaluations },
  customerApi: { predictions: api.predictions, profile: api.profile },
  configApi: { effective: api.effective, saveOverrides: api.saveOverrides, extensions: api.extensions },
}))

import SegmentAccuracy from '../accuracy/SegmentAccuracy.vue'
import PromotionDecisions from '../accuracy/PromotionDecisions.vue'
import ShadowEvaluations from '../accuracy/ShadowEvaluations.vue'
import CustomerPredictions from '../customers/CustomerPredictions.vue'
import CustomerS05Quality from '../customers/CustomerS05Quality.vue'
import CustomerDetailView from '@/views/CustomerDetailView.vue'
import EffectiveConfigSettings from '../settings/EffectiveConfigSettings.vue'

beforeEach(() => { for (const f of Object.values(api)) f.mockReset() })

const meta = (n: number) => ({ current_page: 1, per_page: 50, total: n, last_page: 1 })
const page = <T,>(data: T[]) => ({ data, meta: meta(data.length) })

// ───────────────────────────── precisión por segmento ─────────────────────────────

const quality = (over: Record<string, unknown> = {}): ModelQualityEvaluation['quality'] => ({
  metrics_version: 'demand-segment-metrics-v1', status: 'ok', insufficient_reasons: [], wape_status: 'ok', wape_unstable: false, wape_raw: 0.18,
  denominator: { sum_actual: 1200, unit: 'unidades', min_required: 30 }, alternative_metric: null, mase: 0.8, rmsse: 0.9, sum_abs_error: 216, pinball: null, interval_width: null,
  sample: { evaluable_days: 840, excluded_days: 30, total_days: 870, n_series: 14, exclusions: { stockout_days: 20, missing_source_days: 6, closed_days: 4, scale_undefined: 0 } },
  stability: { status: 'ok', n_windows: 3, wape_mean: 0.19, wape_std: 0.04, wape_cv: 0.2, unstable_across_windows: false },
  comparison: { baseline_family: 'seasonal_naive', verdict: 'better', metric: 'wape', value: 0.18, baseline_value: 0.3, relative_improvement: 0.4, reason: null }, ...over,
})
const row = (over: Partial<ModelQualityEvaluation> = {}): ModelQualityEvaluation => ({
  id: 1, core: 'demand', forecast_run_id: 5, core_run_id: null, model_registry_id: null, model_family: 'selection', label: 'Selección por patrón de demanda', roles: ['selected'], evaluation_type: 'backtest',
  level: 'segment', level_key: 'demand_class:smooth', segment: { dimension: 'demand_class', value: 'smooth' }, horizon_days: 30, is_baseline: false, is_selected: true, status: 'ok', figures_available: true,
  wape: 0.18, mae: 1.2, rmse: 1.9, bias: 0.03, coverage_80: 0.8, coverage_95: 0.94, n_obs: 840, n_windows: 3, cutoffs: ['2026-09-30'], quality: quality(), calibration: null, shadow: null, metrics: {},
  extra: { evaluation_scope: 'holdout' }, created_at: null, ...over,
})
const mountSegments = (props: { runId: number | null; summary?: object | null } = { runId: 5 }) =>
  mount(SegmentAccuracy, { props: props as never, global: { plugins: [setupTenant('analyst')] } })

describe('Precisión por horizonte y segmento', () => {
  it('muestra el error junto a su denominador, los días evaluados y excluidos, la línea base y el veredicto', async () => {
    api.evaluations.mockResolvedValue(page([row()]))
    const w = mountSegments()
    await flushPromises()
    const tr = w.get('[data-segment="demand_class:smooth"]')
    expect(tr.get('th').text()).toBe('Regular')
    expect(tr.get('[data-test="wape"]').text()).toMatch(/18/)
    expect(tr.get('[data-test="denominator"]').text()).toMatch(/1[.,\s]?200/)
    expect(tr.get('[data-test="denominator"]').text()).toContain('unidades')
    expect(tr.get('[data-test="coverage"]').text()).toMatch(/840 de 870 días/)
    expect(tr.get('[data-test="coverage"]').text()).toMatch(/30 excluidos/)
    expect(tr.get('[data-test="coverage"]').attributes('title')).toContain('quiebre de stock: 20')
    expect(tr.get('[data-test="baseline"]').text()).toMatch(/30/)
    expect(tr.get('[data-test="verdict"]').text()).toBe('Mejor')
    expect(tr.get('[data-test="stability"]').text()).toContain('Estable')
    expect(w.get('[data-test="segment-scope"]').text()).toContain('período final reservado')
  })

  it('pide a la API el modelo servido por ejecución, dimensión y horizonte, y refresca al cambiar de dimensión', async () => {
    api.evaluations.mockResolvedValue(page([row()]))
    const w = mountSegments()
    await flushPromises()
    expect(api.evaluations).toHaveBeenCalledWith(expect.objectContaining({ core: 'demand', run_id: 5, level: 'segment', dimension: 'demand_class', horizon: 30, evaluation_type: 'backtest', is_selected: true }))
    const radio = w.findAll('[role="radiogroup"][aria-label="Agrupar por"] [role="radio"]').find((r) => r.text() === 'Sucursal')!
    await radio.trigger('click')
    await flushPromises()
    expect(api.evaluations).toHaveBeenLastCalledWith(expect.objectContaining({ dimension: 'branch', horizon: 30 }))
  })

  it('un WAPE inestable no se muestra como porcentaje: lleva su marca y la métrica alternativa explícita', async () => {
    api.evaluations.mockResolvedValue(page([row({
      id: 2, level_key: 'volume_tier:rest', segment: { dimension: 'volume_tier', value: 'rest' }, wape: null,
      quality: quality({ wape_status: 'unstable', wape_unstable: true, wape_raw: null, denominator: { sum_actual: 12, unit: 'unidades', min_required: 30 },
        alternative_metric: { name: 'aggregate_absolute_error', value: 250.5, per_evaluable_day: 1.2, used_because: 'wape_unstable' } }),
    })]))
    const w = mountSegments()
    await flushPromises()
    const cell = w.get('[data-segment="volume_tier:rest"] [data-test="wape"]')
    expect(cell.get('[data-test="unstable"]').text()).toBe('WAPE inestable')
    expect(cell.text()).not.toMatch(/\d\s?%/)
    expect(cell.get('[data-test="alternative"]').text()).toMatch(/error absoluto total de 250,5 unidades/)
    expect(w.get('[data-segment="volume_tier:rest"] [data-test="denominator"]').text()).toMatch(/12/)
    expect(w.get('[data-segment="volume_tier:rest"] th').text()).toBe('Resto')
  })

  it('un segmento con datos insuficientes no muestra ninguna cifra de error y explica por qué', async () => {
    api.evaluations.mockResolvedValue(page([row({
      id: 3, level_key: 'series_age:new', segment: { dimension: 'series_age', value: 'new' }, status: 'insufficient_data', figures_available: false, wape: null, mae: null, n_obs: 12,
      // Aunque llegara un baseline con la métrica «wape», una fila sin datos suficientes no lo muestra (la comparación no es válida).
      quality: quality({ status: 'insufficient_data', wape_raw: null, mase: null, comparison: { baseline_family: 'seasonal_naive', verdict: 'not_comparable', metric: 'wape', value: null, baseline_value: 0.3, relative_improvement: null, reason: 'insufficient_data' },
        insufficient_reasons: [{ code: 'few_series', n_series: 1, min: 3 }, { code: 'few_observations', evaluable_days: 12, min: 30 }], denominator: { sum_actual: 8, unit: 'unidades', min_required: 30 },
        sample: { evaluable_days: 12, excluded_days: 0, total_days: 42, n_series: 1 }, stability: { status: 'withheld_insufficient_data', n_windows: 3, wape_mean: null, wape_std: null, wape_cv: null, unstable_across_windows: null } }),
    })]))
    const w = mountSegments()
    await flushPromises()
    const tr = w.get('[data-segment="series_age:new"]')
    expect(tr.attributes('data-status')).toBe('insufficient_data')
    expect(tr.get('[data-test="insufficient"]').text()).toBe('Sin datos suficientes')
    const reasons = tr.findAll('[data-test="insufficient-reason"]').map((r) => r.text())
    expect(reasons).toEqual(['Solo 1 series con datos (mínimo 3).', 'Solo 12 días evaluables (mínimo 30).'])
    expect(tr.get('[data-test="wape"]').text()).not.toMatch(/\d\s?%/)
    expect(tr.get('[data-test="baseline"]').text()).toBe('—')
    expect(tr.get('[data-test="stability"]').text()).toBe('—')
    // Sí se ve el denominador y la muestra: explican la abstención.
    expect(tr.get('[data-test="denominator"]').text()).toMatch(/8/)
    expect(tr.get('[data-test="coverage"]').text()).toMatch(/12 de 42 días/)
  })

  it('las sucursales se muestran con su nombre', async () => {
    api.evaluations.mockResolvedValue(page([row({ id: 4, level_key: 'branch:b2', segment: { dimension: 'branch', value: 'b2' } })]))
    const pinia = setupTenant('analyst')
    useCatalogStore().branches = [{ id: 2, name: 'Matriz Quito' } as never]
    const w = mount(SegmentAccuracy, { props: { runId: 5 }, global: { plugins: [pinia] } })
    await flushPromises()
    expect(w.get('[data-segment="branch:b2"] th').text()).toBe('Matriz Quito')
  })

  it('sin métricas para el horizonte o sin ejecución se abstiene; las métricas no disponibles se avisan', async () => {
    api.evaluations.mockResolvedValue(page([]))
    const w = mountSegments({ runId: 5, summary: { available: false, horizons_not_evaluated: [30] } })
    await flushPromises()
    expect(w.get('[data-test="segment-unavailable"]').text()).toContain('Métricas por segmento no disponibles')
    expect(w.get('[data-test="segment-empty"]').text()).toContain('no alcanzó el horizonte de 30 días')
    expect(w.find('table').exists()).toBe(false)

    api.evaluations.mockClear()
    const none = mountSegments({ runId: null })
    await flushPromises()
    expect(api.evaluations).not.toHaveBeenCalled()
    expect(none.find('[data-test="segment-empty"]').exists()).toBe(true)
  })

  it('resume cuántas combinaciones merecen atención', async () => {
    api.evaluations.mockResolvedValue(page([row()]))
    const w = mountSegments({ runId: 5, summary: { available: true, attention_total: 3, counts: { cells: 24 } } })
    await flushPromises()
    expect(w.get('[data-test="segment-attention"]').text()).toContain('3 de 24 combinaciones')
  })
})

// ───────────────────────────── decisiones de promoción ─────────────────────────────

const decision = (over: Partial<PromotionDecision> = {}): PromotionDecision => ({
  id: 1, core: 'demand', forecast_run_id: 5, core_run_id: null, scope_key: 'global', scope: { type: 'global', value: null }, policy: 'legacy', policy_version: 'a'.repeat(64),
  candidate_family: 'lightgbm_global', candidate_label: 'LightGBM global', champion_family: 'seasonal_naive', champion_label: 'Estacional ingenuo', candidate_model_registry_id: null,
  champion_model_registry_id: null, decision: 'promote', is_effective: true, informational: false, served_family: 'lightgbm_global', served_label: 'LightGBM global', planned_family: null,
  fallback_applied: false, gates: { min_improvement: { status: 'pass', blocking: false, message: 'Mejora relativa 8 % (mínimo 3 %).', evidence: {} } },
  gates_summary: { pass: 1, fail: 0, not_evaluable: 0, not_applicable: 0, pending: 0 }, failed_gates: [], metrics: {}, reason: 'LightGBM supera al mejor estadístico.',
  approval: { status: 'pending', approved_by: null, approved_at: null }, decided_at: null, created_at: null, ...over,
})
const decisions = (): PromotionDecisionsPage => ({
  ...page([
    decision(),
    decision({ id: 2, policy: 'v25', decision: 'shadow', is_effective: false, served_family: 'seasonal_naive', served_label: 'Estacional ingenuo', reason: 'Un gate blando no pasa.',
      gates: { temporal_stability: { status: 'fail', blocking: false, message: 'Pierde 2 ventanas consecutivas (máximo permitido 1).', evidence: {} }, human_approval: { status: 'pending', blocking: false, message: 'Pendiente.', evidence: {} } },
      gates_summary: { pass: 0, fail: 1, not_evaluable: 0, not_applicable: 0, pending: 1 }, failed_gates: ['temporal_stability'] }),
    decision({ id: 3, scope_key: 'class:smooth', scope: { type: 'class', value: 'smooth' } }),
    decision({ id: 4, scope_key: 'class:smooth', scope: { type: 'class', value: 'smooth' }, policy: 'v25', is_effective: false, decision: 'promote', approval: { status: 'approved', approved_by: 9, approved_at: '2026-10-02T10:00:00Z' } }),
    decision({ id: 5, scope_key: 'horizon:30', scope: { type: 'horizon', value: '30' }, informational: true }),
  ]),
  run: { id: 5, version: 5, run_type: 'production', cutoff_date: '2026-09-30', is_current: true },
  summary: { governing_policy: 'legacy', governing_source: 'run_config', policies: {}, agreement: { scopes_compared: 2, same_served: 1, different: [{ scope_key: 'global', legacy_served_family: 'lightgbm_global', v25_served_family: 'seasonal_naive' }] } },
})

describe('Decisiones de promoción', () => {
  const mountDecisions = (runId: number | null = 5) => mount(PromotionDecisions, { props: { runId }, global: { plugins: [setupTenant('viewer')] } })

  it('muestra las dos políticas lado a lado, cuál gobernó y por qué, y lo que cada una dejó servido', async () => {
    api.promotionDecisions.mockResolvedValue(decisions())
    const w = mountDecisions()
    await flushPromises()
    expect(api.promotionDecisions).toHaveBeenCalledWith({ run_id: 5, per_page: 100 })
    expect(w.get('[data-test="promotion-governing"]').text()).toContain('Política heredada')
    expect(w.get('[data-test="promotion-governing"]').text()).toContain('Es la política por defecto: lo servido no cambia')
    const global = w.get('[data-scope="global"]')
    const legacy = global.get('[data-policy="legacy"]')
    const v25 = global.get('[data-policy="v25"]')
    expect(legacy.attributes('data-effective')).toBe('true')
    expect(legacy.get('[data-test="effective"]').text()).toBe('Gobierna')
    expect(legacy.get('[data-test="served"]').text()).toBe('Machine learning global (LightGBM)')
    expect(v25.attributes('data-effective')).toBe('false')
    expect(v25.get('[data-test="shadow-policy"]').text()).toBe('Sombra de decisión')
    expect(v25.get('[data-test="decision"]').text()).toBe('Observar en sombra')
    expect(v25.get('[data-test="served"]').text()).toBe('Estacional simple (misma semana anterior)')
    expect(global.findAll('th')[0]!.text()).toBe('Global')
    expect(w.get('[data-scope="class:smooth"] th').text()).toContain('Clase: Regular')
  })

  it('lista los gates con su estado y su evidencia, y el estado de la aprobación humana separada', async () => {
    api.promotionDecisions.mockResolvedValue(decisions())
    const w = mountDecisions()
    await flushPromises()
    const gates = w.get('[data-scope="global"] [data-policy="v25"]').findAll('[data-test="gates"] li')
    expect(gates.map((g) => g.attributes('data-status'))).toEqual(['fail', 'pending'])
    expect(gates[0]!.text()).toContain('Estabilidad temporal')
    expect(gates[0]!.text()).toContain('No cumple')
    expect(gates[0]!.text()).toContain('Pierde 2 ventanas consecutivas')
    expect(w.get('[data-scope="global"] [data-policy="v25"] [data-test="approval-pending"]').text()).toContain('pendiente')
    // En la política heredada un gate que no se cumple es un aviso (LightGBM no reemplaza al estadístico), no un error, y la columna lo explica.
    const legacy = w.get('[data-scope="global"] [data-policy="legacy"]')
    expect(legacy.get('[data-test="legacy-gates-note"]').text()).toContain('se sirve el estadístico')
    expect(w.find('[data-scope="global"] [data-policy="v25"] [data-test="legacy-gates-note"]').exists()).toBe(false)
    expect(w.get('[data-scope="class:smooth"] [data-policy="v25"] [data-test="approval-done"]').text()).toContain('Aprobada')
  })

  it('dice en qué alcances la V2.5 habría servido otro modelo', async () => {
    api.promotionDecisions.mockResolvedValue(decisions())
    const w = mountDecisions()
    await flushPromises()
    expect(w.get('[data-test="promotion-agreement"]').text()).toContain('distinto en 1 de 2 alcances')
    expect(w.get('[data-test="promotion-different"]').text()).toContain('global: heredada → Machine learning global (LightGBM); V2.5 → Estacional simple')
  })

  it('los alcances por horizonte son informativos y quedan ocultos hasta pedirlos', async () => {
    api.promotionDecisions.mockResolvedValue(decisions())
    const w = mountDecisions()
    await flushPromises()
    expect(w.find('[data-scope="horizon:30"]').exists()).toBe(false)
    await w.get('input[type="checkbox"]').setValue(true)
    expect(w.get('[data-scope="horizon:30"]').text()).toContain('informativo')
  })

  it('avisa cuando el entrenamiento final sirvió un respaldo en lugar de lo planeado', async () => {
    const d = decisions()
    d.data[0] = decision({ served_family: 'seasonal_naive', planned_family: 'lightgbm_global', fallback_applied: true })
    api.promotionDecisions.mockResolvedValue(d)
    const w = mountDecisions()
    await flushPromises()
    expect(w.get('[data-scope="global"] [data-policy="legacy"] [data-test="fallback"]').text()).toContain('se había planeado Machine learning global (LightGBM)')
    expect(w.get('[data-scope="global"] [data-policy="legacy"] [data-test="served"]').text()).toBe('Estacional simple (misma semana anterior)')
  })

  it('cuando gobierna la V2.5 lo dice', async () => {
    const d = decisions()
    d.summary!.governing_policy = 'v25'
    api.promotionDecisions.mockResolvedValue(d)
    const w = mountDecisions()
    await flushPromises()
    expect(w.get('[data-test="promotion-governing"]').text()).toContain('La empresa eligió que gobiernen los gates')
  })

  it('una ejecución sin decisiones se abstiene', async () => {
    api.promotionDecisions.mockResolvedValue({ ...page([]), run: null, summary: null })
    const w = mountDecisions()
    await flushPromises()
    expect(w.get('[data-test="promotion-empty"]').text()).toContain('Sin decisiones de promoción')
    expect(w.find('table').exists()).toBe(false)
  })
})

// ───────────────────────────── shadow ─────────────────────────────

describe('Modelo observador (shadow)', () => {
  const shadowBlock = (over: Record<string, unknown> = {}): NonNullable<ModelQualityEvaluation['shadow']> => ({
    served: false, scope: 'prospectivo', wape_status: 'ok', wape_unstable: false, denominator: { sum_actual: 280, unit: 'unidades', min_required: 30 },
    served_wape_same_series_days: 0.25, relative_improvement_vs_served: 0.1, n_served_compared: 5, sum_actual: 280, n_series: 2, coverage_80_n: 5,
    evaluated: { first_date: '2026-10-02', last_date: '2026-10-04', n_days: 3, n_series_days: 5, n_series: 2 },
    exclusions: { series_days: 6, evaluated_series_days: 5, stockout_days: 1, closed_days: 0 }, ...over,
  }) as never
  const shadowPage = (evaluated: boolean, block = shadowBlock(), over: Partial<ModelQualityEvaluation> = {}): ShadowEvaluationsPage => ({
    ...page(evaluated ? [row({ id: 9, model_family: 'auto_ets', evaluation_type: 'shadow_prospective', level: 'overall', level_key: null, segment: null, horizon_days: null, is_selected: false, wape: 0.225, bias: -0.02,
      n_obs: 5, quality: null, shadow: block, ...over })] : []),
    run: { id: 5, version: 5, run_type: 'production', cutoff_date: '2026-09-30', is_current: true },
    shadow_forecasts: [{ forecast_run_id: 5, model_family: 'auto_ets', label: 'ETS', model_registry_id: null, served: false, n_values: 6, n_nodes: 2, first_target_date: '2026-10-02', last_target_date: '2026-10-04', status: evaluated ? 'evaluated' : 'pending', n_obs_evaluated: evaluated ? 5 : null }],
  })
  const mountShadow = () => mount(ShadowEvaluations, { props: { runId: 5 }, global: { plugins: [setupTenant('viewer')] } })

  it('compara el modelo observador con el servido en los mismos días y dice que no se sirve', async () => {
    api.shadowEvaluations.mockResolvedValue(shadowPage(true))
    const w = mountShadow()
    await flushPromises()
    expect(api.shadowEvaluations).toHaveBeenCalledWith({ run_id: 5, level: 'overall', per_page: 50 })
    const tr = w.get('[data-family="auto_ets"]')
    expect(tr.text()).toContain('No se sirve')
    expect(tr.get('[data-test="shadow-status"]').text()).toBe('Evaluado')
    expect(tr.get('[data-test="shadow-wape"]').text()).toMatch(/22/)
    expect(tr.get('[data-test="shadow-served"]').text()).toMatch(/25/)
    expect(tr.get('[data-test="shadow-served"]').text()).toContain('mejora')
    expect(w.get('[data-test="shadow-note"]').text()).toContain('nunca se sirve')
    // Solo las causas de exclusión con días: los totales de series-día no se confunden con exclusiones.
    expect(tr.get('[data-test="shadow-evaluated"]').attributes('title')).toBe('quiebre de stock: 1')
    // El período REALMENTE evaluado y el denominador siempre están a la vista (las predicciones tienen su propio rango).
    expect(tr.get('[data-test="shadow-evaluated"]').text()).toMatch(/3 días/)
    expect(tr.get('[data-test="shadow-evaluated"]').text()).toMatch(/5 series-día/)
    expect(tr.get('[data-test="shadow-denominator"]').text()).toMatch(/280/)
    expect(tr.get('[data-test="shadow-predicted"]').text()).toContain('predicho')
  })

  it('mientras no haya ventas posteriores al corte está pendiente y no muestra ninguna cifra', async () => {
    api.shadowEvaluations.mockResolvedValue(shadowPage(false))
    const w = mountShadow()
    await flushPromises()
    const tr = w.get('[data-family="auto_ets"]')
    expect(tr.get('[data-test="shadow-status"]').text()).toBe('Pendiente')
    expect(tr.get('[data-test="shadow-pending"]').text()).toContain('Pendiente')
    expect(tr.find('[data-test="shadow-wape"]').exists()).toBe(false)
    expect(tr.text()).not.toMatch(/\d\s?%/)
  })

  it('el estado «pendiente» manda: aunque llegara una fila de evaluación, no se muestra ninguna cifra', async () => {
    api.shadowEvaluations.mockResolvedValue({ ...shadowPage(false), data: shadowPage(true).data })
    const w = mountShadow()
    await flushPromises()
    const tr = w.get('[data-family="auto_ets"]')
    expect(tr.get('[data-test="shadow-pending"]').text()).toContain('Pendiente')
    expect(tr.find('[data-test="shadow-wape"]').exists()).toBe(false)
    expect(tr.find('[data-test="shadow-denominator"]').exists()).toBe(false)
    expect(tr.text()).not.toMatch(/\d\s?%/)
  })

  it('con muy pocas ventas reales el WAPE no se muestra: ni «0 %» ni «+100 %», solo el denominador y el período evaluado', async () => {
    api.shadowEvaluations.mockResolvedValue(shadowPage(true, shadowBlock({
      wape_status: 'unstable', wape_unstable: true, denominator: { sum_actual: 0.5, unit: 'unidades', min_required: 30 }, served_wape_same_series_days: null, relative_improvement_vs_served: null,
      evaluated: { first_date: '2026-10-02', last_date: '2026-10-02', n_days: 1, n_series_days: 1, n_series: 1 },
    }), { wape: null, bias: null, n_obs: 1 }))
    const w = mountShadow()
    await flushPromises()
    const tr = w.get('[data-family="auto_ets"]')
    expect(tr.get('[data-test="shadow-unstable"]').text()).toContain('WAPE inestable')
    expect(tr.get('[data-test="shadow-unstable"]').text()).toMatch(/0,5 unidades\) no llegan al mínimo de 30/)
    expect(tr.find('[data-test="shadow-wape"]').exists()).toBe(false)
    expect(tr.find('[data-test="shadow-served"]').exists()).toBe(false)
    expect(tr.text()).not.toMatch(/\d\s?%/)
    expect(tr.get('[data-test="shadow-denominator"]').text()).toMatch(/0,5/)
    expect(tr.get('[data-test="shadow-evaluated"]').text()).toMatch(/1 días/)
  })

  it('sin ventas reales el WAPE no está definido', async () => {
    api.shadowEvaluations.mockResolvedValue(shadowPage(true, shadowBlock({ wape_status: 'undefined', wape_unstable: true, denominator: { sum_actual: 0, unit: 'unidades', min_required: 30 }, served_wape_same_series_days: null, relative_improvement_vs_served: null }), { wape: null, bias: null }))
    const w = mountShadow()
    await flushPromises()
    expect(w.get('[data-family="auto_ets"] [data-test="shadow-unstable"]').text()).toContain('WAPE no definido')
  })

  it('una fila anterior a W3-D (sin fechas evaluadas) lo dice en lugar de inventar el período', async () => {
    api.shadowEvaluations.mockResolvedValue(shadowPage(true, shadowBlock({ evaluated: { first_date: null, last_date: null, n_days: null, n_series_days: 5, n_series: 2 } })))
    const w = mountShadow()
    await flushPromises()
    const cell = w.get('[data-family="auto_ets"] [data-test="shadow-evaluated"]')
    expect(cell.text()).toContain('fechas no registradas')
    expect(cell.text()).toMatch(/5 series-día/)
  })

  it('sin challenger se abstiene', async () => {
    api.shadowEvaluations.mockResolvedValue({ ...page([]), run: null, shadow_forecasts: [] })
    const w = mountShadow()
    await flushPromises()
    expect(w.get('[data-test="shadow-empty"]').text()).toContain('Sin modelo observador')
  })
})

// ───────────────────────────── Customer: S05 ─────────────────────────────

const window_ = (over: Record<string, unknown> = {}) => ({ status: 'estimated', p10_days: 6, p50_days: 11.5, p90_days: 19, ...over })
const prediction = (over: Partial<CustomerPrediction> = {}): CustomerPrediction => ({
  customer: { id: 7, code: 'CLI-007' }, product: { id: 3, sku: 'CEM-50', name: 'Cemento 50 kg', unit: 'saco' }, horizon_days: 30, as_of: '2026-10-26', purchase_probability: 0.42, calibration_status: 'calibrated',
  probability_coherence_adjusted: false, quantity_if_purchase: 12, quantity_interval: { lo: 6, hi: 24, level: 0.8, method: 'conformal' }, expected_quantity: 5.04, committed_quantity: 0, incremental_quantity: 5.04, total_view_quantity: 5.04,
  expected_amount: { value: 42.8, currency: 'USD', assumed_unit_price: 8.5, basis: 'supuesto' }, median_interval_days: 30, days_since_last_purchase: 12, n_purchases: 6, model: { family: 'logistic', role: 'primary' }, quality_flags: [],
  expected_orders: 1.35, quantity_per_order: 3.2, expected_units: 4.32, window: window_() as never, segment: 'frequency:high', ...over,
})
const envelope = { run_id: 5, core: 'customer', as_of: '2026-10-26', horizon_start: '2026-10-27', horizon_end: '2026-11-25', target_definition: null, entity_keys: null, unit: 'product_unit', currency: 'USD', result_type: 'forecast', model_version: null, data_snapshot_id: 9, config_version: 'c', assumptions: [], limitations: [], quality_status: 'ok', uncertainty_source: 'empirical_holdout' }
const predictionsPage = (rows: CustomerPrediction[]) => ({ ...page(rows), envelope, ranking_source: 'popularity', definitions: {} })

describe('Compradores: pedidos esperados y ventana de recompra', () => {
  const mountPredictions = () => mount(CustomerPredictions, { props: { horizon: 30 }, global: { plugins: [setupTenant('analyst')] } })

  it('muestra los pedidos esperados, las unidades y la ventana como un rango, nunca un día exacto', async () => {
    api.predictions.mockResolvedValue(predictionsPage([prediction()]))
    const w = mountPredictions()
    await flushPromises()
    expect(w.get('[data-test="orders"]').text()).toMatch(/1,35/)
    expect(w.get('[data-test="units"]').text()).toMatch(/4,32/)
    expect(w.get('[data-test="per-order"]').text()).toMatch(/3,2 por pedido/)
    expect(w.get('[data-test="window-range"]').text()).toBe('6 a 19 días')
    expect(w.get('[data-test="window-median"]').text()).toBe('mediana 12 días')
    expect(w.find('[data-test="window-state"]').exists()).toBe(false)
  })

  it('con insufficient_data muestra el estado y ninguna cifra de ventana', async () => {
    api.predictions.mockResolvedValue(predictionsPage([prediction({ window: window_({ status: 'insufficient_data', p10_days: null, p50_days: null, p90_days: null }) as never })]))
    const w = mountPredictions()
    await flushPromises()
    const cell = w.get('[data-window-status="insufficient_data"]')
    expect(cell.get('[data-test="window-state"]').text()).toBe('Sin estimar: evidencia insuficiente')
    expect(cell.find('[data-test="window-range"]').exists()).toBe(false)
    expect(cell.text()).not.toMatch(/\d/)
  })

  it('aunque lleguen cuantiles sin un estado que los respalde, no se muestran', async () => {
    api.predictions.mockResolvedValue(predictionsPage([prediction({ window: window_({ status: 'insufficient_data' }) as never })]))
    const w = mountPredictions()
    await flushPromises()
    expect(w.find('[data-test="window-range"]').exists()).toBe(false)
    expect(w.get('[data-test="window"]').text()).not.toMatch(/\d/)
  })

  it('un producto sin existencias lo dice; una ventana atrasada se marca y no se confunde con abandono', async () => {
    api.predictions.mockResolvedValue(predictionsPage([
      prediction({ customer: { id: 8, code: 'B' }, window: window_({ status: 'censored', p10_days: null, p50_days: null, p90_days: null }) as never }),
      prediction({ customer: { id: 9, code: 'C' }, window: window_({ status: 'overdue', p10_days: 20, p50_days: 35, p90_days: 60 }) as never }),
    ]))
    const w = mountPredictions()
    await flushPromises()
    expect(w.get('[data-window-status="censored"] [data-test="window-state"]').text()).toBe('Sin estimar: producto sin existencias')
    const overdue = w.get('[data-window-status="overdue"]')
    expect(overdue.get('[data-test="window-range"]').text()).toBe('20 a 60 días')
    expect(overdue.get('[data-test="window-overdue"]').text()).toBe('Atrasado')
  })

  it('una corrida sin S05 muestra «No estimado», nunca un cero', async () => {
    api.predictions.mockResolvedValue(predictionsPage([prediction({ expected_orders: null, expected_units: null, quantity_per_order: null, window: window_({ status: null, p10_days: null, p50_days: null, p90_days: null }) as never })]))
    const w = mountPredictions()
    await flushPromises()
    expect(w.get('[data-test="orders-missing"]').text()).toBe('No estimado')
    expect(w.get('[data-test="units-missing"]').text()).toBe('No estimado')
    expect(w.get('[data-test="window-state"]').text()).toBe('No estimada')
    expect(w.find('[data-test="orders"]').exists()).toBe(false)
  })

  it('traduce las banderas que explican una cifra y no muestra como código las de diagnóstico del motor', async () => {
    api.predictions.mockResolvedValue(predictionsPage([prediction({ quality_flags: ['orders_nested_adjusted', 'cold_start', 'window_beyond_validation', 'quantity_coherence_adjusted', 'price_unknown'] })]))
    const w = mountPredictions()
    await flushPromises()
    const text = w.text()
    for (const label of ['Pedidos ajustados entre horizontes', 'Pocas compras', 'Ventana más larga que lo validado', 'Cantidad ajustada por coherencia', 'Precio desconocido']) expect(text).toContain(label)
    expect(text).not.toContain('orders_nested_adjusted')
    expect(text).not.toContain('quantity_coherence_adjusted')
  })

  it('un «No estimado» explica su causa (modelo anterior o cálculo fallido) sin mostrar el código de diagnóstico ni cifras', async () => {
    const none = { expected_orders: null, expected_units: null, quantity_per_order: null, window: window_({ status: null, p10_days: null, p50_days: null, p90_days: null }) as never }
    api.predictions.mockResolvedValue(predictionsPage([
      prediction({ customer: { id: 8, code: 'FALLO' }, ...none, quality_flags: ['s05_failed'] }),
      prediction({ customer: { id: 9, code: 'ANTIGUO' }, ...none, quality_flags: ['s05_unavailable'] }),
      prediction({ customer: { id: 10, code: 'SINDATO' }, ...none, quality_flags: [] }),
    ]))
    const w = mountPredictions()
    await flushPromises()
    const rows = w.findAll('tbody tr')
    expect(rows[0]!.get('[data-test="orders-cause"]').text()).toBe('el cálculo falló en esta fila')
    expect(rows[0]!.get('[data-test="orders-missing"]').attributes('title')).toContain('falló para esta fila')
    expect(rows[0]!.get('[data-test="window-cause"]').text()).toBe('el cálculo falló en esta fila')
    expect(rows[1]!.get('[data-test="orders-cause"]').text()).toBe('modelo anterior a esta función')
    expect(rows[2]!.find('[data-test="orders-cause"]').exists()).toBe(false)
    for (const r of rows) {
      expect(r.text()).toContain('No estimado')
      expect(r.text()).not.toContain('s05_')
      expect(r.text()).not.toContain('Pedidos y ventana no')   // la etiqueta de la bandera de diagnóstico no es un aviso de la fila
    }
  })

  it('una fila con cifras de S05 no muestra causa aunque traiga una bandera de diagnóstico', async () => {
    api.predictions.mockResolvedValue(predictionsPage([prediction({ quality_flags: ['s05_failed'] })]))
    const w = mountPredictions()
    await flushPromises()
    expect(w.find('[data-test="orders-cause"]').exists()).toBe(false)
    expect(w.find('[data-test="window-cause"]').exists()).toBe(false)
  })

  it('permite ordenar por pedidos y unidades esperados', async () => {
    api.predictions.mockResolvedValue(predictionsPage([prediction()]))
    const w = mountPredictions()
    await flushPromises()
    await w.get('#cp-order').setValue('expected_orders')
    await flushPromises()
    expect(api.predictions).toHaveBeenLastCalledWith(expect.objectContaining({ order_by: 'expected_orders' }))
  })
})

describe('Ficha del comprador: S05', () => {
  const profile = (): { data: CustomerProfile; envelope: typeof envelope } => ({
    data: {
      customer: { id: 7, code: 'CLI-007', type: 'company', is_generic: false, segment: null }, status: 'predicted', fallback: null, as_of: '2026-10-26', activity: null,
      rankings: {
        30: [
          { rank: 1, ranking_source: 'popularity', ranking_score: 40, product: { id: 3, sku: 'CEM-50', name: 'Cemento 50 kg', unit: 'saco' }, purchase_probability: 0.42, expected_quantity: 5.04,
            expected_amount: { value: 42.8, currency: 'USD', basis: 'supuesto' }, expected_orders: 1.35, expected_units: 4.32, quantity_per_order: 3.2, window: window_() as never, segment: 'frequency:high', quality_flags: ['cold_start', 's05_failed'] },
          { rank: 2, ranking_source: 'popularity', ranking_score: 30, product: { id: 4, sku: 'VAR-12', name: 'Varilla 12 mm', unit: 'u' }, purchase_probability: 0.2, expected_quantity: 1,
            expected_amount: { value: 8, currency: 'USD', basis: 'supuesto' }, expected_orders: null, expected_units: null, quantity_per_order: null, window: window_({ status: 'insufficient_data', p10_days: null, p50_days: null, p90_days: null }) as never, quality_flags: [] },
          { rank: 3, ranking_source: 'popularity', ranking_score: 20, product: { id: 5, sku: 'ALA-02', name: 'Alambre', unit: 'u' }, purchase_probability: 0.1, expected_quantity: 0.5,
            expected_amount: { value: 4, currency: 'USD', basis: 'supuesto' }, expected_orders: null, expected_units: null, quantity_per_order: null, window: window_({ status: null, p10_days: null, p50_days: null, p90_days: null }) as never, quality_flags: ['s05_unavailable'] },
        ],
      } as never,
      ranking_sources: { 30: 'popularity' } as never,
    } as CustomerProfile,
    envelope,
  })

  it('muestra pedidos esperados, ventana y banderas junto a cada producto probable', async () => {
    api.profile.mockResolvedValue(profile())
    const w = mount(CustomerDetailView, { props: { id: '7' }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    const section = w.get('[data-test="ranking-30"]')
    const rows = section.findAll('tbody tr')
    expect(rows[0]!.get('[data-test="orders"]').text()).toMatch(/1,35/)
    expect(rows[0]!.get('[data-test="window-range"]').text()).toBe('6 a 19 días')
    expect(rows[0]!.text()).toContain('Pocas compras')
    // `s05_failed` de la fila 1 no se muestra como código ni como etiqueta; esa fila tiene cifras, así que tampoco hay causa que explicar.
    expect(rows[0]!.text()).not.toContain('Pedidos y ventana no calculados')
    expect(rows[0]!.find('[data-test="orders-cause"]').exists()).toBe(false)
    expect(rows[1]!.get('[data-test="window-state"]').text()).toBe('Sin estimar: evidencia insuficiente')
    expect(rows[1]!.text()).toContain('No estimado')
    expect(rows[2]!.get('[data-test="orders-cause"]').text()).toBe('modelo anterior a esta función')
    expect(rows[2]!.get('[data-test="window-cause"]').text()).toBe('modelo anterior a esta función')
    expect(rows[2]!.text()).not.toContain('s05_unavailable')
  })
})

// ───────────────────────────── Customer: calibración por segmento y validación de la ventana ─────────────────────────────

describe('Compradores: calibración por segmento', () => {
  const seg = (key: string, status: string, over: Partial<ModelQualityEvaluation> = {}): ModelQualityEvaluation => row({
    id: Math.floor(Math.random() * 1e6), core: 'customer', forecast_run_id: null, core_run_id: 8, model_family: 'customer_segment_calibration', evaluation_type: 'holdout', level: 'segment', level_key: key,
    segment: null, horizon_days: 30, status: status as never, figures_available: status !== 'insufficient_data', quality: null,
    calibration: status === 'insufficient_data'
      ? { status: 'insufficient_data', n: 40, n_positive: 2, base_rate: 0.05, mean_predicted: null, mean_ratio: null, brier: null, log_loss: null, ece: null, reference_brier: null, brier_delta_vs_reference: null, reason: 'menos de 100 filas o de 20 positivos' }
      : { status: status as never, n: 1800, n_positive: 240, base_rate: 0.1333, mean_predicted: 0.1401, mean_ratio: 1.05, brier: 0.101, log_loss: 0.33, ece: 0.02, reference_brier: 0.109, brier_delta_vs_reference: 0.008, reason: null }, ...over,
  })
  const windowRow = (status: string) => row({ id: 99, core: 'customer', level: 'overall', level_key: 'window', model_family: 'customer_window', evaluation_type: 'out_of_sample', segment: null, horizon_days: null, quality: null, coverage_80: 0.4, n_obs: 127, metrics: { status, interval: { n_relations: 127 } } })

  function arrange(windowStatus: string | null) {
    api.evaluations.mockImplementation(async (params: { level_key?: string }) =>
      params.level_key === 'window'
        ? page(windowStatus ? [windowRow(windowStatus)] : [])
        : page([seg('frequency:high', 'calibrated'), seg('frequency:low', 'miscalibrated'), seg('history:cold_start', 'insufficient_data')]))
  }
  const mountQuality = () => mount(CustomerS05Quality, { props: { coreRunId: 8, horizon: 30 }, global: { plugins: [setupTenant('analyst')] } })

  it('agrupa por dimensión, muestra la calibración y no inventa cifras en un segmento con pocas filas', async () => {
    arrange('not_validated')
    const w = mountQuality()
    await flushPromises()
    expect(api.evaluations).toHaveBeenCalledWith(expect.objectContaining({ core: 'customer', core_run_id: 8, level: 'segment', horizon: 30 }))
    const high = w.get('[data-segment="frequency:high"]')
    expect(high.get('th').text()).toBe('Alta')
    expect(high.text()).toMatch(/13,3\s?%/)
    expect(high.text()).toContain('Calibrado')
    expect(w.get('[data-segment="frequency:low"]').text()).toContain('Descalibrado')
    const cold = w.get('[data-segment="history:cold_start"]')
    expect(cold.attributes('data-status')).toBe('insufficient_data')
    expect(cold.get('[data-test="segment-insufficient"]').text()).toContain('menos de 100 filas')
    expect(cold.text()).not.toMatch(/\d\s?%/)
    expect(w.findAll('[data-dimension]').map((d) => d.attributes('data-dimension'))).toEqual(['frequency', 'history'])
  })

  it('una ventana no validada muestra el estado y la cobertura medida, y dice que no publica rangos', async () => {
    arrange('not_validated')
    const w = mountQuality()
    await flushPromises()
    const box = w.get('[data-test="window-validation"]')
    expect(box.text()).toContain('Ventana no validada')
    expect(box.text()).toContain('no se publica ninguna ventana')
    expect(box.text()).toMatch(/Cobertura medida del rango P10–P90: 40\s?%/)
    expect(box.text()).toContain('127 relaciones comprador × producto distintas')
  })

  it('sin filas de segmento ni de ventana (corrida anterior a S05) se abstiene', async () => {
    api.evaluations.mockResolvedValue(page([]))
    const w = mountQuality()
    await flushPromises()
    expect(w.get('[data-test="segments-empty"]').text()).toContain('no trae calibración por segmento')
    expect(w.find('[data-test="window-validation"]').exists()).toBe(false)
  })
})

// ───────────────────────────── Configuración: promoción ─────────────────────────────

describe('Configuración de la promoción de modelos', () => {
  const config = (): EffectiveConfig => ({
    core: 'promotion', config_version: 'f'.repeat(64), profile: null, precedence: ['core', 'profile', 'tenant'], tenant_overrides: {},
    keys: [{ key: 'governing_policy', value: 'legacy', source: 'core' }, { key: 'shadow_enabled', value: true, source: 'core' }],
  })

  it('ofrece el core «Promoción de modelos», explica la política que gobierna y guarda lo que el motor valida', async () => {
    api.effective.mockImplementation(async (core: string) => ({ ...config(), core }))
    api.saveOverrides.mockResolvedValue({ ...config(), tenant_overrides: { governing_policy: 'v25' }, keys: [{ key: 'governing_policy', value: 'v25', source: 'tenant' }] })
    api.extensions.mockResolvedValue({ providers: [], profile: null })
    const w = mount(EffectiveConfigSettings, { global: { plugins: [setupTenant('owner')] } })
    await flushPromises()
    const select = w.get('#ec-core')
    expect(select.findAll('option').map((o) => o.text())).toContain('Promoción de modelos')
    await select.setValue('promotion')
    await flushPromises()
    expect(api.effective).toHaveBeenLastCalledWith('promotion')
    expect(w.get('[data-test="promotion-config-note"]').text()).toContain('«legacy»')
    const input = w.get('input#ec-governing_policy')
    await input.setValue('v25')
    await w.get('[data-test="save-config"]').trigger('click')
    await flushPromises()
    expect(api.saveOverrides).toHaveBeenCalledWith('promotion', { governing_policy: 'v25' })
  })
})
