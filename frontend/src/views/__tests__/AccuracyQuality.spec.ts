import { beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach } from 'vitest'
import { setupTenant } from '@/test/helpers'

const api = vi.hoisted(() => ({ summary: vi.fn(), runs: vi.fn(), evaluations: vi.fn(), promotionDecisions: vi.fn(), shadowEvaluations: vi.fn(), comparison: vi.fn() }))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  accuracyApi: { summary: api.summary, comparison: api.comparison, models: vi.fn().mockResolvedValue({ data: [], meta: {} }), evaluations: vi.fn() },
  forecastApi: { runs: api.runs },
  modelQualityApi: { evaluations: api.evaluations, promotionDecisions: api.promotionDecisions, shadowEvaluations: api.shadowEvaluations },
  catalogApi: { branches: vi.fn().mockResolvedValue([]), categories: vi.fn().mockResolvedValue([]), products: vi.fn() },
}))

import AccuracyView from '../AccuracyView.vue'

enableAutoUnmount(afterEach)
const stubs = { ErrorBarsChart: true, ComparisonChart: true, ProductPicker: true, ProtocolManifest: true, ExtendedMetrics: true, DemandModels: true, RouterLink: { template: '<a><slot /></a>' } }
const empty = { data: [], meta: { current_page: 1, per_page: 50, total: 0, last_page: 1 } }

beforeEach(() => {
  for (const f of Object.values(api)) f.mockReset()
  api.summary.mockResolvedValue({
    run: { id: 7, version: 3, cutoff_date: '2026-09-30' }, backtest: { selected: null, baseline: null, by_horizon: [], by_demand_class: [], by_branch: [], models: [] }, realized: null, explanation: [],
  })
  api.runs.mockResolvedValue({
    data: [{ id: 7, version: 3, run_type: 'production', status: 'completed', is_current: true, cutoff_date: '2026-09-30', summary: { segment_metrics: { available: false, enabled: true } } }],
    meta: { current_page: 1, per_page: 30, total: 1, last_page: 1 },
  })
  api.evaluations.mockResolvedValue(empty)
  api.promotionDecisions.mockResolvedValue({ ...empty, run: null, summary: null })
  api.shadowEvaluations.mockResolvedValue({ ...empty, run: null, shadow_forecasts: [] })
})

describe('Historial de precisión: calidad ML', () => {
  it('muestra las secciones por segmento, de promoción y de modelo observador, consultando la ejecución resuelta', async () => {
    const w = mount(AccuracyView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(w.get('[data-test="segment-section"] h2').text()).toBe('Precisión por horizonte y segmento')
    expect(w.get('[data-test="promotion-section"] h2').text()).toBe('Decisiones de promoción')
    expect(w.get('[data-test="shadow-section"] h2').text()).toBe('Modelo observador (shadow)')
    expect(api.evaluations).toHaveBeenCalledWith(expect.objectContaining({ run_id: 7, level: 'segment' }))
    expect(api.promotionDecisions).toHaveBeenCalledWith({ run_id: 7, per_page: 100 })
    expect(api.shadowEvaluations).toHaveBeenCalledWith(expect.objectContaining({ run_id: 7 }))
  })

  it('usa el resumen de la ejecución para avisar que las métricas por segmento no estuvieron disponibles', async () => {
    const w = mount(AccuracyView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(w.get('[data-test="segment-unavailable"]').text()).toContain('Esta ejecución no pudo calcular las métricas por segmento')
  })

  it('las ejecuciones sin nada de esto se abstienen en cada sección, sin cifras', async () => {
    const w = mount(AccuracyView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(w.get('[data-test="segment-empty"]').text()).toContain('Sin métricas por segmento')
    expect(w.get('[data-test="promotion-empty"]').text()).toContain('Sin decisiones de promoción')
    expect(w.get('[data-test="shadow-empty"]').text()).toContain('Sin modelo observador')
  })
})
