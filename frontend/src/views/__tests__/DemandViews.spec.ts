import { beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach } from 'vitest'
import { setupTenant } from '@/test/helpers'
import type { ForecastSeries, HorizonSupport, ProductForecastRow } from '@/api/types'

const api = vi.hoisted(() => ({ series: vi.fn(), runs: vi.fn(), products: vi.fn(), categories: vi.fn(), dashboard: vi.fn(), inventorySummary: vi.fn(), catalogProducts: vi.fn() }))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  forecastApi: { series: api.series, runs: api.runs, products: api.products, categories: api.categories, dashboard: api.dashboard, createRun: vi.fn(), job: vi.fn() },
  inventoryApi: { summary: api.inventorySummary },
  catalogApi: { products: api.catalogProducts, branches: vi.fn().mockResolvedValue([]), categories: vi.fn().mockResolvedValue([]) },
}))

import ForecastsView from '../ForecastsView.vue'
import ProductsView from '../ProductsView.vue'
import DashboardView from '../DashboardView.vue'
import ForecastChart from '@/components/charts/ForecastChart.vue'

enableAutoUnmount(afterEach)
const stubs = { ForecastChart: true, RunForecastPanel: true, RouterLink: { template: '<a><slot /></a>' } }
const support = (status: HorizonSupport['status'], reasons: HorizonSupport['reasons'] = []): HorizonSupport => ({ horizon: 60, status, reasons })

const series = (hs: HorizonSupport, quality: 'ok' | 'insufficient_data' = 'ok'): ForecastSeries => ({
  node: { key: 'total', level: 'total', label: 'Empresa' }, granularity: 'day', horizon: 60,
  history: [{ date: '2026-10-01', units: 10, revenue: 100, stockout: false }],
  forecast: [
    { date: '2026-10-02', yhat: 12, lo_80: null, hi_80: null, lo_95: null, hi_95: null, revenue: 120, p50: null, quality_status: quality, case_label: 'new_product_category_median' },
    { date: '2026-10-03', yhat: 13, lo_80: null, hi_80: null, lo_95: null, hi_95: null, revenue: 130, p50: null, quality_status: quality, case_label: 'new_product_category_median' },
  ],
  meta: { run_id: 1, run_version: 1, generated_at: '2026-10-26T00:00:00Z', cutoff_date: '2026-10-01', model_family: 'seasonal_naive', models: {}, price_assumption: null, intervals_available: false, has_forecast: true, horizon_support: hs },
  envelope: { run_id: 1, core: 'demand', as_of: '2026-10-01', horizon_start: '2026-10-02', horizon_end: '2026-11-30', target_definition: null, entity_keys: null, unit: 'product_unit', currency: 'USD', result_type: 'forecast', model_version: null, data_snapshot_id: 3, config_version: 'cfg', assumptions: ['Precio promedio de 28 días.'], limitations: ['horizon_60_insufficient_data'], quality_status: quality, uncertainty_source: 'conformal' },
})

beforeEach(() => { for (const f of Object.values(api)) f.mockReset(); api.runs.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 20, total: 0, last_page: 1 } }) })

describe('Pronósticos: horizonte, respaldo y casos especiales', () => {
  it('el selector de horizonte solo ofrece los del plan (básico: 7, 14 y 30)', async () => {
    api.series.mockResolvedValue(series(support('ok')))
    const w = mount(ForecastsView, { global: { plugins: [setupTenant('analyst', { horizons: [7, 14, 30] })], stubs } })
    await flushPromises()
    const radios = w.findAll('[role="radiogroup"][aria-label="Horizonte"] [role="radio"]').map((r) => r.text())
    expect(radios).toEqual(['7 días', '14 días', '30 días'])
  })

  it('con todos los horizontes del dominio se ofrecen 7, 14, 30, 60 y 90', async () => {
    api.series.mockResolvedValue(series(support('ok')))
    const w = mount(ForecastsView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(w.findAll('[role="radiogroup"][aria-label="Horizonte"] [role="radio"]').map((r) => r.text())).toEqual(['7 días', '14 días', '30 días', '60 días', '90 días'])
  })

  it('un horizonte sin respaldo: aviso con motivos, calidad visible, gráfico provisional y total marcado', async () => {
    api.series.mockResolvedValue(series(support('insufficient_data', [{ code: 'history_lt_2h', message: 'La historia (80 días) es menor que 2×60.' }]), 'insufficient_data'))
    const w = mount(ForecastsView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    const notice = w.get('[data-test="horizon-support-notice"]')
    expect(notice.text()).toContain('sin respaldo suficiente')
    expect(notice.text()).toContain('La historia (80 días) es menor que 2×60.')
    expect(w.get('[data-test="series-quality"] [data-test="quality-badge"]').text()).toBe('Datos insuficientes')
    expect(w.findComponent(ForecastChart).props('provisional')).toBe(true)
    expect(w.get('[data-test="series-total"]').text()).toContain('provisional, sin respaldo suficiente')
    expect(w.get('[data-test="envelope-panel"]').text()).toContain('horizon_60_insufficient_data')
  })

  it('un horizonte respaldado se presenta como completo y sin aviso', async () => {
    api.series.mockResolvedValue(series(support('ok')))
    const w = mount(ForecastsView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(w.find('[data-test="horizon-support-notice"]').exists()).toBe(false)
    expect(w.findComponent(ForecastChart).props('provisional')).toBe(false)
    expect(w.get('[data-test="series-total"]').text()).not.toContain('provisional')
  })

  it('muestra el caso especial de la serie (producto nuevo con respaldo por categoría)', async () => {
    api.series.mockResolvedValue(series(support('ok')))
    const w = mount(ForecastsView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    const badges = w.findAll('[data-test="case-label"]')
    expect(badges).toHaveLength(1)
    expect(badges[0]!.text()).toBe('Producto nuevo: respaldo por categoría')
  })

  it('lista las series sin pronóstico (abstención) de la ejecución vigente, sin inventar un cero', async () => {
    api.series.mockResolvedValue(series(support('ok')))
    api.runs.mockResolvedValue({ data: [{ id: 1, version: 1, run_type: 'production', status: 'completed', triggered_by: 'user', horizon_days: 90, cutoff_date: '2026-10-01', is_current: true, created_at: '2026-10-01T00:00:00Z',
      summary: { special_cases: { label_counts: { new_product_category_median: 2 }, no_recent_sales: { abstained: 7, after_days: 180 }, discontinued: { explicit_zero: 1, abstained: 3 }, branch_closed_declared: { branches: [], explicit_zero: 0, abstained: 0 } } } }], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } })
    const w = mount(ForecastsView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    const box = w.get('[data-test="abstentions"]')
    expect(box.text()).toContain('10 series sin pronóstico')
    expect(box.text()).toContain('7 series activas sin ventas hace más de 180 días')
    expect(box.text()).toContain('3 productos descontinuados')
    expect(box.text()).not.toContain('sucursales cerradas')
  })

  it('not_computed ofrece generar un pronóstico solo a quien puede ejecutarlo', async () => {
    api.series.mockResolvedValue(series(support('not_computed')))
    const analyst = mount(ForecastsView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(analyst.get('[data-test="horizon-support-notice"]').text()).toContain('Generar un pronóstico')
    const viewer = mount(ForecastsView, { global: { plugins: [setupTenant('viewer')], stubs } })
    await flushPromises()
    expect(viewer.get('[data-test="horizon-support-notice"]').text()).not.toContain('Generar un pronóstico')
  })
})

describe('Productos y panel', () => {
  const row = (over: Partial<ProductForecastRow> = {}): ProductForecastRow => ({
    product: { id: 1, sku: 'CLA-1', name: 'Clavos', category: null }, history_units: 100, forecast_units: 80, change_pct: -0.2, trend: 'down', forecast_revenue: 200, lo_80: 60, hi_80: 100,
    model_family: 'seasonal_naive', demand_class: 'smooth', wape: 0.3, case_label: 'discontinued_zero', ...over,
  })
  const page = (hs: HorizonSupport) => ({ data: [row()], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 }, run: { id: 1, version: 1, generated_at: null, cutoff_date: null }, horizon: 60, horizon_support: hs })

  it('productos: un horizonte sin respaldo marca las cifras y oculta los rangos; el caso especial se muestra como insignia', async () => {
    api.products.mockResolvedValue(page(support('insufficient_data')))
    const w = mount(ProductsView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(w.get('[data-test="horizon-support-notice"]').attributes('data-status')).toBe('insufficient_data')
    const body = w.get('tbody').text()
    expect(body).toContain('sin respaldo')
    expect(body).not.toMatch(/60.?–.?100/)
    expect(w.get('[data-test="case-label"]').text()).toBe('Descontinuado: cero explícito')
  })

  it('productos: con respaldo se muestra el rango de 80 %', async () => {
    api.products.mockResolvedValue(page(support('ok')))
    const w = mount(ProductsView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(w.get('tbody').text()).toMatch(/60.?–.?100/)
    expect(w.find('[data-test="horizon-support-notice"]').exists()).toBe(false)
  })

  const dashboard = (hs: HorizonSupport) => ({
    horizon: 60, horizon_support: hs, run: { id: 1, version: 1, generated_at: '2026-10-26T00:00:00Z', cutoff_date: '2026-10-01' },
    kpis: { forecast_units: 5000, forecast_units_lo_80: null, forecast_units_hi_80: null, forecast_revenue: 50000, previous_period_units: 4000, previous_period_revenue: 40000, change_pct: 0.25, stockout_risk_products: 2, purchase_investment: 1000, backtest_wape: 0.4, baseline_wape: 0.5, realized_wape: null },
    top_increasing: [], top_decreasing: [], stockout_risks: [], alerts: [], data_status: { has_sales: true, has_inventory: true, has_forecast: true, last_sale_date: '2026-10-01', readiness_score: 0.9, warnings: [] },
  })

  it('panel: un horizonte sin respaldo no se presenta como completo (aviso, KPI provisional, sin variación)', async () => {
    api.dashboard.mockResolvedValue(dashboard(support('insufficient_data')))
    api.series.mockResolvedValue(series(support('insufficient_data')))
    api.inventorySummary.mockResolvedValue({ snapshot_date: null, products_with_stock: 0, total_on_hand_value: null, risk_counts: { low: 0, medium: 0, high: 0, critical: 0 }, purchase_investment: null, coverage_days_median: null })
    const w = mount(DashboardView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(w.get('[data-test="horizon-support-notice"]').attributes('data-status')).toBe('insufficient_data')
    expect(w.text()).toContain('Sin respaldo suficiente para este horizonte: cifra provisional.')
    expect(w.text()).not.toContain('+25')
    expect(w.findComponent(ForecastChart).props('provisional')).toBe(true)
  })

  it('panel: un horizonte respaldado muestra la variación y el gráfico normal', async () => {
    api.dashboard.mockResolvedValue(dashboard(support('ok')))
    api.series.mockResolvedValue(series(support('ok')))
    api.inventorySummary.mockResolvedValue({ snapshot_date: null, products_with_stock: 0, total_on_hand_value: null, risk_counts: { low: 0, medium: 0, high: 0, critical: 0 }, purchase_investment: null, coverage_days_median: null })
    const w = mount(DashboardView, { global: { plugins: [setupTenant('analyst')], stubs } })
    await flushPromises()
    expect(w.find('[data-test="horizon-support-notice"]').exists()).toBe(false)
    expect(w.text()).toMatch(/\+25/)
    expect(w.findComponent(ForecastChart).props('provisional')).toBe(false)
  })
})
