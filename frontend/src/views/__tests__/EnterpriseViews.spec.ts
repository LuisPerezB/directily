import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { setupTenant } from '@/test/helpers'
import { decisionSummary, experiment, horizonSummary, kindRow, opportunity, opportunityDetail, outcome, radarSummary, recommendation, roiReport } from '@/test/enterprise'
import { ApiError } from '@/api/errors'
import type { DashboardSummary, PlanLimits, TenantRole } from '@/api/types'
import { useAuthStore } from '@/stores/auth'
import { useTenantStore } from '@/stores/tenant'
import { useCatalogStore } from '@/stores/catalog'
import ProductPicker from '@/components/forecast/ProductPicker.vue'

const api = vi.hoisted(() => ({
  dashboard: vi.fn(), inventory: vi.fn(), radarSummary: vi.fn(), opportunities: vi.fn(), opportunity: vi.fn(), agenda: vi.fn(), feedback: vi.fn(), materialize: vi.fn(),
  decisionSummary: vi.fn(), decisionList: vi.fn(), decisionShow: vi.fn(), decisionTransition: vi.fn(), roi: vi.fn(), outcomeList: vi.fn(), models: vi.fn(), products: vi.fn(),
}))
const router = vi.hoisted(() => ({ replace: vi.fn() }))
const route = vi.hoisted(() => ({ query: {} as Record<string, unknown>, fullPath: '/app/radar-comercial' }))

vi.mock('vue-router', async (orig) => ({ ...(await orig<typeof import('vue-router')>()), useRouter: () => router, useRoute: () => route }))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  forecastApi: { dashboard: api.dashboard },
  inventoryApi: { summary: api.inventory },
  radarApi: { summary: api.radarSummary, opportunities: api.opportunities, opportunity: api.opportunity, agenda: api.agenda, feedback: api.feedback, materialize: api.materialize },
  decisionApi: { summary: api.decisionSummary, list: api.decisionList, show: api.decisionShow, transition: api.decisionTransition, settings: vi.fn() },
  outcomeApi: { roi: api.roi, list: api.outcomeList },
  accuracyApi: { models: api.models },
  catalogApi: { products: api.products, branches: vi.fn().mockResolvedValue([]), categories: vi.fn().mockResolvedValue([]) },
}))

import ExecutiveOverviewView from '../ExecutiveOverviewView.vue'
import RevenueRadarView from '../RevenueRadarView.vue'
import DecisionCenterView from '../DecisionCenterView.vue'
import RoiOutcomesView from '../RoiOutcomesView.vue'
import EnterpriseAdminView from '../EnterpriseAdminView.vue'

enableAutoUnmount(afterEach)
function tenantAs(role: TenantRole = 'analyst', limits?: PlanLimits) {
  const pinia = limits ? setupTenant(role, limits) : setupTenant(role)
  useAuthStore().user = { id: 7, name: 'Ana', email: 'a@x.test', locale: 'es', is_superadmin: false }
  return pinia
}
const page = <T,>(rows: T[], per = 25) => ({ data: rows, meta: { current_page: 1, per_page: per, total: rows.length, last_page: 1 } })
const dashboard = (over: Partial<DashboardSummary> = {}): DashboardSummary => ({
  horizon: 30, horizon_support: { horizon: 30, status: 'ok', reasons: [] }, run: { id: 4, version: 2, generated_at: '2026-10-31T06:00:00-05:00', cutoff_date: '2026-10-31' },
  kpis: { forecast_units: 1200, forecast_units_lo_80: 1000, forecast_units_hi_80: 1400, forecast_revenue: 9000, previous_period_units: 1100, previous_period_revenue: 8000, change_pct: 0.09, stockout_risk_products: 3, purchase_investment: 500, backtest_wape: 0.2, baseline_wape: 0.3, realized_wape: null },
  top_increasing: [], top_decreasing: [], stockout_risks: [], alerts: [], data_status: { has_sales: true, has_inventory: true, has_forecast: true, last_sale_date: '2026-10-30', readiness_score: 1, warnings: [] }, ...over,
})
const inventory = { snapshot_date: '2026-10-30', products_with_stock: 40, total_on_hand_value: 5000, risk_counts: { low: 30, medium: 5, high: 3, critical: 2 }, purchase_investment: 500, coverage_days_median: 21 }

beforeEach(() => {
  Object.values(api).forEach((m) => m.mockReset())
  router.replace.mockReset()
  route.query = {}
  api.dashboard.mockResolvedValue(dashboard())
  api.inventory.mockResolvedValue(inventory)
  api.radarSummary.mockResolvedValue({ data: radarSummary(), envelope: { as_of: '2026-10-31', uncertainty_source: 'empirical_holdout', quality_status: 'ok' }, message: null })
  api.opportunities.mockResolvedValue({ ...page([opportunity()]), envelope: { as_of: '2026-10-31' } })
  api.opportunity.mockResolvedValue({ data: opportunityDetail(), status_map: { version: 'radar-agenda-map-v1', table: [] } })
  api.agenda.mockResolvedValue({ ...page([]), date: '2026-11-02', envelope: { as_of: '2026-10-31' } })
  api.decisionSummary.mockResolvedValue(decisionSummary())
  api.decisionList.mockResolvedValue(page([recommendation()]))
  api.decisionShow.mockResolvedValue(recommendation())
  api.roi.mockResolvedValue(roiReport())
  api.outcomeList.mockResolvedValue(page([outcome()]))
  api.models.mockResolvedValue(page([]))
  api.products.mockResolvedValue(page([]))
})

describe('Resumen ejecutivo', () => {
  const mountView = async (role: TenantRole = 'analyst', limits?: PlanLimits) => {
    const w = mount(ExecutiveOverviewView, { global: { plugins: [tenantAs(role, limits)] } })
    await flushPromises()
    return w
  }

  it('agrega pronóstico, stock, oportunidades, decisiones y resultados, cada uno con su corte', async () => {
    const w = await mountView()
    expect(w.get('[data-test="forecast-units"]').text()).toMatch(/1\.200 unidades/)
    expect(w.get('[data-test="exec-forecast"]').text()).toContain('1.000')
    expect(w.get('[data-test="exec-forecast"] [data-test="as-of"]').text()).toMatch(/Datos hasta/)
    expect(w.get('[data-test="stock-risk"]').text()).toBe('5')
    expect(w.get('[data-test="exec-stock"] [data-test="as-of"]').text()).toMatch(/Datos hasta/)
    expect(w.get('[data-test="radar-count"]').text()).toContain('4 oportunidades de 3 clientes')
    expect(w.get('[data-test="exec-radar"] [data-test="as-of"]').text()).toMatch(/Datos hasta/)
    expect(w.get('[data-test="awaiting-assignment"]').text()).toBe('5')
    expect(w.get('[data-test="awaiting-approval"]').text()).toBe('1')
    expect(w.find('[data-test="exec-decisions"] [data-test="as-of-live"]').exists()).toBe(true)
    expect(w.get('[data-test="exec-classifications"]').text()).toContain('Observado: 3')
    expect(w.get('[data-test="exec-outcomes"] [data-test="as-of"]').text()).toMatch(/Datos hasta/)
  })

  it('no inventa indicadores: la capacidad y el beneficio probado se declaran no disponibles', async () => {
    const w = await mountView()
    expect(w.get('[data-test="capacity-unavailable"]').text()).toContain('no disponible')
    expect(w.get('[data-test="exec-unavailable"]').text()).toContain('Beneficio incremental probado')
    expect(w.get('[data-test="exec-roi"]').text()).toContain('No demostrado')
  })

  it('el pronóstico de oportunidades y la caída observada no se suman', async () => {
    const w = await mountView()
    const text = w.get('[data-test="exec-radar"]').text().replace(/\s| /g, '')
    expect(text).not.toMatch(/1\.?250/) // 1000 pronóstico + 250 caída
    expect(w.get('[data-test="radar-gross"]').text()).toContain('1.000')
    expect(w.get('[data-test="radar-decline"]').text()).toContain('250')
    expect(w.get('[data-test="radar-decline"]').text()).toContain('no un pronóstico ni una contribución')
  })

  it('valor y contribución nulos son «No estimado/No estimada», nunca 0', async () => {
    api.radarSummary.mockResolvedValue({ data: radarSummary({ by_horizon: { '30': horizonSummary({ value: { ...horizonSummary().value, gross_value: null, contribution: null }, customer_level: { ...horizonSummary().customer_level, observed_spend_decline: null } }) } }), envelope: null, message: null })
    const w = await mountView()
    expect(w.get('[data-test="radar-gross"]').text()).toContain('No estimado')
    expect(w.get('[data-test="radar-contribution"]').text()).toContain('No estimada')
    expect(w.get('[data-test="radar-decline"]').text()).toContain('No estimado')
    expect(w.get('[data-test="exec-radar"]').findAll('[data-test="figure"]')).toHaveLength(0)
  })

  it('un horizonte sin respaldo no publica la cifra como completa', async () => {
    api.dashboard.mockResolvedValue(dashboard({ horizon_support: { horizon: 30, status: 'insufficient_data', reasons: [{ code: 'no_model', reason_code: 'no_model', message: 'Sin modelo' }] } }))
    const w = await mountView()
    expect(w.find('[data-test="forecast-units"]').exists()).toBe(false)
    expect(w.get('[data-test="forecast-incomplete"]').text()).toContain('no se publica una cifra como completa')
  })

  it('sin pronóstico lo dice y no pone una cifra', async () => {
    api.dashboard.mockResolvedValue(dashboard({ data_status: { has_sales: true, has_inventory: false, has_forecast: false, last_sale_date: null, readiness_score: 0, warnings: [] } }))
    const w = await mountView()
    expect(w.find('[data-test="forecast-none"]').exists()).toBe(true)
    expect(w.find('[data-test="forecast-units"]').exists()).toBe(false)
  })

  it('una API que falla no oculta las demás tarjetas', async () => {
    api.inventory.mockRejectedValue(new ApiError(500, null, 'x'))
    const w = await mountView()
    expect(w.find('[data-test="exec-stock"] [role="alert"]').exists()).toBe(true)
    expect(w.find('[data-test="forecast-units"]').exists()).toBe(true)
    expect(w.find('[data-test="radar-count"]').exists()).toBe(true)
  })

  it('un plan sin comportamiento de compra (402) se explica, no es un error', async () => {
    api.radarSummary.mockRejectedValue(new ApiError(402, { message: 'plan', code: 'plan_limit_exceeded' } as never, 'plan'))
    const w = await mountView()
    expect(w.get('[data-test="exec-radar-plan"]').text()).toContain('no incluye el comportamiento de compra')
    expect(w.find('[data-test="exec-radar"] [role="alert"]').exists()).toBe(false)
  })

  it('datos de demostración: la banda avisa que los importes no son resultados reales', async () => {
    api.roi.mockResolvedValue(roiReport({ data_origin: { value: 'demo', declared: true, note: 'Datos de demostración o simulados: los importes NO son resultados reales.' } }))
    const w = await mountView()
    expect(w.get('[data-test="origin-band"]').attributes('data-origin')).toBe('demo')
    expect(w.get('[data-test="origin-band"]').text()).toContain('NO son resultados reales')
  })

  it('M3: al cambiar de empresa CADA tarjeta vuelve a pedir su dato y, mientras llega, no queda nada de la anterior', async () => {
    const w = await mountView()
    expect(w.text()).toContain('1.200 unidades')
    const pending = <T,>() => new Promise<T>(() => undefined) // la empresa nueva aún no responde
    const before = Object.fromEntries(['dashboard', 'inventory', 'radarSummary', 'decisionSummary', 'roi', 'outcomeList'].map((k) => [k, (api as Record<string, ReturnType<typeof vi.fn>>)[k]!.mock.calls.length]))
    for (const k of ['dashboard', 'inventory', 'radarSummary', 'decisionSummary', 'roi', 'outcomeList']) (api as Record<string, ReturnType<typeof vi.fn>>)[k]!.mockImplementation(() => pending())
    useTenantStore().currentUuid = 'otra-empresa'
    await flushPromises()
    for (const k of Object.keys(before)) expect((api as Record<string, ReturnType<typeof vi.fn>>)[k]!.mock.calls.length, k).toBe(before[k]! + 1)
    // Nada de la empresa A bajo el encabezado de la B.
    expect(w.text()).not.toContain('1.200 unidades')
    expect(w.find('[data-test="stock-risk"]').exists()).toBe(false)
    expect(w.find('[data-test="radar-count"]').exists()).toBe(false)
    expect(w.find('[data-test="awaiting-assignment"]').exists()).toBe(false)
    expect(w.find('[data-test="exec-classifications"]').exists()).toBe(false)
  })

  it('S10: un plan sin comportamiento de compra no consulta el Radar', async () => {
    const w = await mountView('analyst', { horizons: [7, 30], features: { customer: false } })
    expect(api.radarSummary).not.toHaveBeenCalled()
    expect(w.get('[data-test="exec-radar-plan"]').text()).toContain('no incluye el comportamiento de compra')
    expect(api.dashboard).toHaveBeenCalled()
  })

  it('M2: la suma parcial de contribución dice cuántas oportunidades quedaron fuera; también la de valor bruto y la caída observada', async () => {
    api.radarSummary.mockResolvedValue({ data: radarSummary({ by_horizon: { '30': horizonSummary({
      value: { ...horizonSummary().value, opportunities: 5, gross_value: 440.64, gross_value_opportunities: 4, contribution: 440.64, contribution_opportunities: 3, contribution_unavailable: 2 },
      customer_level: { ...horizonSummary().customer_level, opportunities: 3, observed_spend_decline: 250, observed_spend_decline_opportunities: 2 } }) } }), envelope: null, message: null })
    const w = await mountView()
    expect(w.get('[data-test="radar-contribution"]').text()).toContain('440')
    expect(w.get('[data-test="radar-contribution-partial"]').text()).toContain('2 sin costo confiable')
    expect(w.get('[data-test="radar-gross-partial"]').text()).toContain('1 sin valor estimado')
    expect(w.get('[data-test="radar-decline-partial"]').text()).toContain('1 sin valor estimado')
  })

  it('M2: una suma completa no lleva aviso de parcial', async () => {
    const w = await mountView()
    expect(w.find('[data-test="radar-contribution-partial"]').exists()).toBe(true) // el ejemplo base deja 1 sin costo confiable
    api.radarSummary.mockResolvedValue({ data: radarSummary({ by_horizon: { '30': horizonSummary({ value: { ...horizonSummary().value, contribution_unavailable: 0 } }) } }), envelope: null, message: null })
    const complete = await mountView()
    expect(complete.find('[data-test="radar-contribution-partial"]').exists()).toBe(false)
    expect(complete.find('[data-test="radar-gross-partial"]').exists()).toBe(false)
  })

  it('M4: «Datos hasta» de los resultados es el corte real de las ventas leídas, no la fecha del informe, y la antigüedad sale de ahí', async () => {
    api.outcomeList.mockResolvedValue(page([outcome({ window: { from: '2019-12-01', to: '2019-12-31', knowledge_cutoff: '2020-01-02' } }), outcome({ id: 9, window: { from: '2019-11-01', to: '2019-11-30', knowledge_cutoff: '2019-12-02' } })]))
    const w = await mountView()
    const card = w.get('[data-test="exec-outcomes"]')
    expect(card.get('[data-test="as-of"]').text()).toMatch(/^Datos hasta 02 ene 2020/)
    expect(card.find('[data-test="stale"]').exists()).toBe(true)
    expect(card.get('[data-test="generated-at"]').text()).toBe('Informe generado el 02 nov 2026')
    expect(api.outcomeList.mock.calls[0]![0]).toMatchObject({ sort: '-window_to', per_page: 1 })
  })

  it('W5-C final: los resultados dicen el periodo que el servidor aplicó y no ofrecen conteos de incremental ni de causal', async () => {
    const w = await mountView()
    const card = w.get('[data-test="exec-outcomes"]')
    expect(card.get('[data-test="exec-period"]').text()).toContain('Periodo: cortes del')
    expect(card.get('[data-test="exec-period"]').text()).toContain('(180 días)')
    expect(card.get('[data-test="exec-classifications"]').findAll('li').map((l) => l.text())).toEqual(['Previsto: 5', 'Observado: 3', 'Asociado temporalmente: 3'])
    api.roi.mockResolvedValue(roiReport({ filters: {} }))
    const sin = await mountView()
    expect(sin.find('[data-test="exec-period"]').exists()).toBe(false)
  })

  it('M4: sin resultados medidos no hay corte de datos: solo «Informe generado el…»', async () => {
    api.outcomeList.mockResolvedValue(page([]))
    const w = await mountView()
    const card = w.get('[data-test="exec-outcomes"]')
    expect(card.find('[data-test="as-of"]').exists()).toBe(false)
    expect(card.find('[data-test="as-of-missing"]').exists()).toBe(true)
    expect(card.find('[data-test="generated-at"]').exists()).toBe(true)
    expect(card.find('[data-test="stale"]').exists()).toBe(false)
  })

  it('M1: quien no aprueba no ve conteos por clasificación mientras incluyan la retención; quien aprueba, sí; sin el campo de retención se muestran', async () => {
    const withHeld = roiReport({ summary: { ...roiReport().summary, funnel: { ...roiReport().summary.funnel, control_held_out: 66 } } })
    api.roi.mockResolvedValue(withHeld)
    const viewer = await mountView('viewer')
    expect(viewer.get('[data-test="exec-classifications"]').text()).not.toMatch(/\d/)
    expect(viewer.get('[data-test="exec-classifications-hidden"]').text()).toContain('lista de resultados medidos')
    const admin = await mountView('admin')
    expect(admin.get('[data-test="exec-classifications"]').text()).toContain('Observado: 3')
    const clean = roiReport()
    delete (clean.summary.funnel as { control_held_out?: number }).control_held_out
    api.roi.mockResolvedValue(clean)
    const fixed = await mountView('viewer')
    expect(fixed.get('[data-test="exec-classifications"]').text()).toContain('Observado: 3')
  })
})

describe('Revenue Radar', () => {
  const mountView = async (props: Record<string, unknown> = {}, role: TenantRole = 'analyst', limits?: PlanLimits) => {
    const w = mount(RevenueRadarView, { props, global: { plugins: [tenantAs(role, limits)] } })
    await flushPromises()
    return w
  }

  it('lista las oportunidades con el resumen por horizonte y la nota de que no envía mensajes', async () => {
    const w = await mountView()
    expect(w.findAll('[data-test="horizon-card"]')).toHaveLength(2)
    expect(w.text()).toContain('Predictily no envía mensajes ni escribe en el ERP')
    expect(w.findAll('tbody tr')).toHaveLength(1)
    expect(api.opportunities.mock.calls[0]![0]).toMatchObject({ order_by: 'score', direction: 'desc', page: 1 })
    expect(w.find('[role="tab"][aria-selected="true"]').text()).toBe('Oportunidades')
  })

  it('un plan sin comportamiento de compra no consulta nada y lo explica', async () => {
    const w = await mountView({}, 'analyst', { horizons: [7, 30], features: { customer: false } })
    expect(w.get('[data-test="radar-not-in-plan"]').text()).toContain('no incluye el comportamiento de compra')
    expect(api.radarSummary).not.toHaveBeenCalled()
    expect(w.find('[data-test="radar-filters"]').exists()).toBe(false)
  })

  it('un 402 del servidor (horizonte fuera del plan) también se explica', async () => {
    api.radarSummary.mockRejectedValue(new ApiError(402, { message: 'plan', code: 'plan_limit_exceeded' } as never, 'plan'))
    api.opportunities.mockRejectedValue(new ApiError(402, { message: 'plan', code: 'plan_limit_exceeded' } as never, 'plan'))
    const w = await mountView()
    expect(w.find('[data-test="radar-not-in-plan"]').exists()).toBe(true)
  })

  it('los filtros viajan al servidor: horizonte, tipo, estado heredado, y «solo mías» usa el usuario actual', async () => {
    const w = await mountView()
    await w.get('#rf-horizon').setValue(30)
    await w.get('#rf-kind').setValue('recovery')
    await w.get('#rf-state').setValue('contactado')
    await w.get('[data-test="filter-mine"]').setValue(true)
    await new Promise((r) => setTimeout(r, 300))
    await flushPromises()
    const last = api.opportunities.mock.calls.at(-1)![0]
    expect(last).toMatchObject({ horizon: 30, kind: 'recovery', state: 'contactado', seller_id: 7 })
    expect(api.radarSummary.mock.calls.at(-1)![0]).toMatchObject({ horizon: 30, kind: 'recovery', state: 'contactado', seller_id: 7 })
  })

  it('el SKU, la categoría y la zona también viajan al servidor', async () => {
    const w = await mountView()
    useCatalogStore().categories = [{ id: 2, name: 'Huevos' } as never]
    await flushPromises()
    await w.get('#rf-category').setValue(2)
    await w.get('#rf-zone').setValue('  Quito ')
    ;(w.findComponent(ProductPicker).vm as unknown as { $emit: (e: string, v: unknown) => void }).$emit('update:modelValue', { id: 8, sku: 'SKU-8', name: 'Cubeta de huevos' })
    await new Promise((r) => setTimeout(r, 300))
    await flushPromises()
    expect(api.opportunities.mock.calls.at(-1)![0]).toMatchObject({ category_id: 2, zone: 'Quito', product_id: 8 })
    expect(api.radarSummary.mock.calls.at(-1)![0]).toMatchObject({ category_id: 2, zone: 'Quito', product_id: 8 })
  })

  it('sólo ofrece los horizontes del plan', async () => {
    const w = await mountView({}, 'analyst', { horizons: [7, 15, 30] })
    expect(w.findAll('#rf-horizon option').map((o) => o.text())).toEqual(['Todos los horizontes', '7 días', '15 días', '30 días'])
  })

  it('un comprador llegado por enlace filtra la lista y se puede quitar', async () => {
    route.query = { cliente: '3' }
    const w = await mountView()
    expect(api.opportunities.mock.calls[0]![0]).toMatchObject({ customer_id: 3 })
    expect(w.get('[data-test="customer-chip"]').text()).toContain('Comprador 3')
  })

  it('la pestaña de agenda consulta /radar/agenda', async () => {
    await mountView({ tab: 'agenda' })
    expect(api.agenda).toHaveBeenCalled()
  })

  it('cambiar de pestaña navega con el nombre de la ruta', async () => {
    const w = await mountView()
    await w.findAll('[role="tab"]')[1]!.trigger('click')
    expect(router.replace).toHaveBeenCalledWith(expect.objectContaining({ name: 'radar', params: { tab: 'agenda' } }))
  })

  it('sin oportunidades propuestas pero con predicción, quien puede ejecutar pronósticos las genera; un viewer no', async () => {
    api.radarSummary.mockResolvedValue({ data: radarSummary({ totals: { opportunities: 0, customers: 0, unit: 'oportunidades' }, by_horizon: {} }), envelope: null, message: 'Hay una predicción vigente pero aún no hay oportunidades propuestas.' })
    api.opportunities.mockResolvedValue({ ...page([]), envelope: null })
    api.materialize.mockResolvedValue({ status: 'completed', run_id: 5, as_of: '2026-10-31', horizons: [30], policy: 'radar-v1', candidates: 3, created: 3, existing: 0, failed: 0 })
    const w = await mountView()
    await w.get('[data-test="materialize"]').trigger('click')
    await flushPromises()
    expect(api.materialize).toHaveBeenCalledTimes(1)
    expect(w.get('[data-test="materialized"]').text()).toContain('3 nuevas')
    const viewer = await mountView({}, 'viewer')
    expect(viewer.find('[data-test="materialize"]').exists()).toBe(false)
  })

  it('abre el detalle de una oportunidad', async () => {
    const w = await mountView()
    await w.get('[data-test="open-detail"]').trigger('click')
    await flushPromises()
    expect(api.opportunity).toHaveBeenCalledWith(11)
    expect(w.find('[data-test="opportunity-detail"]').exists()).toBe(true)
  })

  it('no muestra el grupo del experimento en ninguna parte, ni a un administrador', async () => {
    api.opportunities.mockResolvedValue({ ...page([opportunity({ experiment_group: 'treatment' })]), envelope: null })
    api.radarSummary.mockResolvedValue({ data: radarSummary({ control_withheld: 5 }), envelope: null, message: null })
    const w = await mountView({}, 'admin')
    const text = w.text().toLowerCase()
    expect(text).not.toContain('treatment')
    expect(text).not.toContain('tratamiento')
    expect(text).not.toContain('control')
    expect(text).not.toContain('experiment')
    expect(text).not.toContain('retenid')
  })

  it('M3: al cambiar de empresa vuelve a pedir el resumen y la lista, y mientras llegan no queda nada de la anterior', async () => {
    const w = await mountView()
    expect(w.findAll('[data-test="horizon-card"]')).toHaveLength(2)
    expect(w.get('[data-test="customer-link"]').text()).toBe('CLI-003')
    const before = { summary: api.radarSummary.mock.calls.length, list: api.opportunities.mock.calls.length }
    api.radarSummary.mockImplementation(() => new Promise(() => undefined))
    api.opportunities.mockImplementation(() => new Promise(() => undefined))
    useTenantStore().currentUuid = 'otra-empresa'
    await flushPromises()
    expect(api.radarSummary.mock.calls.length).toBe(before.summary + 1)
    expect(api.opportunities.mock.calls.length).toBe(before.list + 1)
    expect(w.find('[data-test="horizon-card"]').exists()).toBe(false)
    expect(w.find('[data-test="customer-link"]').exists()).toBe(false)
    expect(w.text()).not.toContain('CLI-003')
  })

  it('M3: la agenda también descarta y vuelve a pedir al cambiar de empresa', async () => {
    api.agenda.mockResolvedValue({ ...page([{ customer: opportunity().customer, opportunities_count: 1, best_score: 1, top_priority: 1, states: { pendiente: 1 }, next_action: null, opportunities: [opportunity()] }], 10), date: '2026-11-02', envelope: { as_of: '2026-10-31' } })
    const w = await mountView({ tab: 'agenda' })
    expect(w.findAll('[data-test="agenda-group"]')).toHaveLength(1)
    const before = api.agenda.mock.calls.length
    api.agenda.mockImplementation(() => new Promise(() => undefined))
    useTenantStore().currentUuid = 'otra-empresa'
    await flushPromises()
    expect(api.agenda.mock.calls.length).toBe(before + 1)
    expect(w.find('[data-test="agenda-group"]').exists()).toBe(false)
  })
})

describe('Centro de decisiones', () => {
  const mountView = async (props: Record<string, unknown> = {}, role: TenantRole = 'analyst') => {
    const w = mount(DecisionCenterView, { props, global: { plugins: [tenantAs(role)] } })
    await flushPromises()
    return w
  }

  it('muestra los conteos y la lista sin detalle hasta elegir una recomendación', async () => {
    const w = await mountView()
    expect(w.get('[data-test="decision-summary"]').text()).toContain('Por asignar')
    expect(w.find('[data-test="decision-list"]').exists()).toBe(true)
    expect(w.find('[data-test="decision-detail"]').exists()).toBe(false)
    expect(api.decisionShow).not.toHaveBeenCalled()
    expect(w.text()).toContain('No envía mensajes ni escribe en el ERP')
  })

  it('seleccionar una fila navega al detalle por id', async () => {
    const w = await mountView()
    await w.get('[data-test="select-decision"]').trigger('click')
    expect(router.replace).toHaveBeenCalledWith({ name: 'decision-center', params: { id: '21' } })
  })

  it('con un id en la ruta carga el detalle y sus acciones según el rol', async () => {
    const w = await mountView({ id: '21' }, 'analyst')
    expect(api.decisionShow).toHaveBeenCalledWith(21)
    expect(w.find('[data-test="decision-detail"]').exists()).toBe(true)
    expect(w.findAll('[data-action]').map((b) => b.attributes('data-action'))).toEqual(['assign', 'dismiss'])
  })

  it('un id inválido no abre el detalle', async () => {
    const w = await mountView({ id: 'abc' })
    expect(w.find('[data-test="decision-detail"]').exists()).toBe(false)
  })

  it('cerrar el detalle vuelve a la lista', async () => {
    const w = await mountView({ id: '21' })
    await w.get('[data-test="close-detail"]').trigger('click')
    expect(router.replace).toHaveBeenCalledWith({ name: 'decision-center', params: {} })
  })

  it('un error al cargar los conteos se muestra con reintento', async () => {
    api.decisionSummary.mockRejectedValue(new ApiError(500, null, 'x'))
    const w = await mountView()
    expect(w.find('[role="alert"]').exists()).toBe(true)
  })

  it('M3: al cambiar de empresa vuelve a pedir los conteos y la lista, y no queda nada de la anterior mientras llegan', async () => {
    const w = await mountView()
    expect(w.find('[data-test="decision-summary"]').exists()).toBe(true)
    expect(w.text()).toContain('Contacto #21')
    const before = { summary: api.decisionSummary.mock.calls.length, list: api.decisionList.mock.calls.length }
    api.decisionSummary.mockImplementation(() => new Promise(() => undefined))
    api.decisionList.mockImplementation(() => new Promise(() => undefined))
    useTenantStore().currentUuid = 'otra-empresa'
    await flushPromises()
    expect(api.decisionSummary.mock.calls.length).toBe(before.summary + 1)
    expect(api.decisionList.mock.calls.length).toBe(before.list + 1)
    expect(w.find('[data-test="decision-summary"]').exists()).toBe(false)
    expect(w.text()).not.toContain('Contacto #21')
  })
})

describe('ROI y resultados', () => {
  const mountView = async (role: TenantRole = 'analyst') => {
    const w = mount(RoiOutcomesView, { global: { plugins: [tenantAs(role)] } })
    await flushPromises()
    return w
  }

  it('muestra el ROI no demostrado, los cuatro tipos de valor sin total, embudo, costos, desglose, resultados y límites', async () => {
    const w = await mountView()
    expect(w.get('[data-test="roi-headline"]').attributes('data-status')).toBe('potential_unproven')
    expect(w.get('[data-test="roi-unproven"]').text()).toBe('No demostrado')
    expect(w.get('[data-test="value-kinds"]').text()).toContain('sin total')
    expect(w.find('[data-test="funnel"]').exists()).toBe(true)
    expect(w.find('[data-test="costs"]').exists()).toBe(true)
    expect(w.find('[data-test="breakdown"]').exists()).toBe(true)
    expect(w.find('[data-test="outcomes"]').exists()).toBe(true)
    expect(w.get('[data-test="limitations"]').text()).toContain('Sin grupo de control analizado')
    expect(w.get('[data-test="classifications"]').text()).toContain('Previsto: 5')
    expect(w.get('[data-test="origin-note"]').text()).toContain('declarados como reales')
  })

  it('H1: la oportunidad potencial se muestra por tipo y ninguna suma entre tipos aparece en la vista (contribución 267,52; bruto 1.760,50)', async () => {
    const w = await mountView('admin')
    const text = w.text().replace(/\s|\u00a0/g, '')
    expect(w.findAll('[data-test="potential-kind"]').map((k) => k.attributes('data-kind'))).toEqual(['contact', 'recovery', 'replenishment', 'budget_constraint'])
    for (const sum of ['267,52', '1.760,50', '1.120,50']) expect(text, sum).not.toContain(sum)
    expect(text).toContain('27,52')
  })

  it('H1: la oportunidad potencial viene en celdas por tipo y horizonte del resumen; cambiar la agrupación del desglose no la altera ni la suma', async () => {
    // Como el servidor: el desglose responde con la agrupación pedida; el resumen (y su potencial por celdas) es el mismo.
    api.roi.mockImplementation(async (p: { group_by: 'kind' | 'owner' }) => roiReport(p.group_by === 'kind' ? {} : { breakdown: { group_by: p.group_by, rows: [kindRow('7', 5)] } }))
    const w = await mountView()
    expect(api.roi).toHaveBeenCalledTimes(1)
    expect(api.roi.mock.calls[0]![0]).toMatchObject({ group_by: 'kind' })
    await w.get('#roi-group').setValue('owner')
    await flushPromises()
    // UNA sola petición más, con la agrupación elegida (ya no hay un segundo informe «por tipo» aparte).
    expect(api.roi).toHaveBeenCalledTimes(2)
    expect(api.roi.mock.calls[1]![0]).toMatchObject({ group_by: 'owner' })
    expect(w.findAll('[data-test="potential-kind"]')).toHaveLength(4)
    expect(w.findAll('[data-test="potential-cell"]')).toHaveLength(5) // reposición tiene dos horizontes
    expect(w.get('[data-test="breakdown"]').attributes('aria-label')).toBe('Responsable')
    expect(w.find('[data-test="breakdown-potential"]').exists()).toBe(false)
    expect(w.text().replace(/\s|\u00a0/g, '')).not.toContain('267,52')
  })

  it('M4: «Datos hasta» es el corte real de las ventas del resultado más reciente; «Informe generado el…» va aparte', async () => {
    api.outcomeList.mockResolvedValue(page([outcome({ window: { from: '2019-12-01', to: '2019-12-31', knowledge_cutoff: '2020-01-02' } })]))
    const w = await mountView()
    const line = w.get('[data-test="roi-report"] [data-test="provenance"]')
    expect(line.get('[data-test="as-of"]').text()).toMatch(/^Datos hasta 02 ene 2020/)
    expect(line.find('[data-test="stale"]').exists()).toBe(true)
    expect(line.get('[data-test="generated-at"]').text()).toBe('Informe generado el 02 nov 2026')
    expect(api.outcomeList.mock.calls[0]![0]).toMatchObject({ sort: '-window_to', per_page: 1 })
  })

  it('M4: sin resultados medidos no hay corte de datos', async () => {
    api.outcomeList.mockResolvedValue(page([]))
    const w = await mountView()
    const line = w.get('[data-test="roi-report"] [data-test="provenance"]')
    expect(line.find('[data-test="as-of"]').exists()).toBe(false)
    expect(line.find('[data-test="as-of-missing"]').exists()).toBe(true)
  })

  it('M1: quien no aprueba ve conteos coherentes con lo que lista (69/64) y no los de la retención; quien aprueba, todos (135/129)', async () => {
    const base = roiReport()
    api.roi.mockResolvedValue(roiReport({ summary: { ...base.summary, funnel: { ...base.summary.funnel, identified: 135, actionable: 69, measured: 129, measured_actionable: 64, control_held_out: 66 } } }))
    const viewer = await mountView('viewer')
    expect(viewer.get('[data-test="identified"]').text()).toBe('69')
    expect(viewer.get('[data-test="measured"]').text()).toBe('64')
    expect(viewer.find('[data-test="classifications-hidden"]').exists()).toBe(true)
    expect(viewer.get('[data-test="classifications"]').text()).not.toMatch(/\d/)
    const admin = await mountView('admin')
    expect(admin.get('[data-test="identified"]').text()).toBe('135')
    expect(admin.get('[data-test="measured"]').text()).toBe('129')
    expect(admin.get('[data-test="classifications"]').text()).toContain('Previsto: 5')
  })

  it('robusto a W5-C: sin campos de retención ni experimentos para quien no aprueba, la vista se pinta completa', async () => {
    const clean = roiReport()
    delete (clean.summary.funnel as { control_held_out?: number }).control_held_out
    delete (clean.summary as { by_action_kind?: unknown }).by_action_kind
    delete (clean.summary as { control_baseline?: unknown }).control_baseline
    delete (clean as { experiments?: unknown }).experiments
    delete (clean.roi as { value?: unknown }).value
    api.roi.mockResolvedValue(clean)
    const viewer = await mountView('viewer')
    expect(viewer.find('[data-test="roi-headline"]').exists()).toBe(true)
    expect(viewer.get('[data-test="classifications"]').text()).toContain('Previsto: 5')
    expect(viewer.find('[data-test="classifications-hidden"]').exists()).toBe(false)
    const admin = await mountView('admin')
    expect(admin.find('[data-test="no-experiments"]').exists()).toBe(true)
  })

  it('el periodo aplicado por el servidor se muestra con sus días y, si lo completó por omisión, lo dice; sin esos campos rige el de 180 días', async () => {
    const applied = await mountView() // la fixture declara el periodo por omisión: 180 días, máximo 730
    expect(applied.get('[data-test="period"]').text()).toContain('Periodo aplicado: cortes del')
    expect(applied.get('[data-test="period"]').text()).toContain('(180 días)')
    expect(applied.get('[data-test="period-defaulted"]').text()).toContain('últimos 180 días de corte (máximo 730 días)')
    api.roi.mockResolvedValue(roiReport({ filters: { from: '2026-01-01', to: '2026-02-01', period_days: 31, period_defaulted: false, period_default_days: 180, period_max_days: 730 } }))
    const explicit = await mountView()
    expect(explicit.get('[data-test="period"]').text()).toContain('(31 días)')
    expect(explicit.find('[data-test="period-defaulted"]').exists()).toBe(false)
    api.roi.mockResolvedValue(roiReport({ filters: {} }))
    const dflt = await mountView()
    expect(dflt.get('[data-test="period"]').text()).toContain('últimos 180 días')
    expect(dflt.find('[data-test="period-defaulted"]').exists()).toBe(false)
  })

  it('un periodo de más de 730 días se avisa y no se envía ninguna fecha (el servidor respondería 422): se muestra el periodo por defecto', async () => {
    const w = await mountView()
    await w.get('#roi-from').setValue('2024-01-01')
    await w.get('#roi-to').setValue('2026-11-01')
    await flushPromises()
    expect(w.get('[data-test="range-too-long"]').text()).toContain('730 días')
    expect(api.roi.mock.calls.at(-1)![0].to).toBeUndefined()
    expect(api.roi.mock.calls.at(-1)![0].from).toBeUndefined()
    await w.get('#roi-from').setValue('2025-01-01')
    await flushPromises()
    expect(w.find('[data-test="range-too-long"]').exists()).toBe(false)
    expect(api.roi.mock.calls.at(-1)![0]).toMatchObject({ from: '2025-01-01', to: '2026-11-01' })
  })

  it('sin «hasta» el servidor toma hoy: un «desde» de hace más de 730 días se avisa; uno futuro es un rango inválido; ninguno viaja', async () => {
    const w = await mountView()
    await w.get('#roi-from').setValue('2020-01-01')
    await flushPromises()
    expect(w.find('[data-test="range-too-long"]').exists()).toBe(true)
    expect(api.roi.mock.calls.at(-1)![0].from).toBeUndefined()
    await w.get('#roi-from').setValue('2999-01-01')
    await flushPromises()
    expect(w.get('[data-test="range-error"]').text()).toContain('no puede ser anterior')
    expect(api.roi.mock.calls.at(-1)![0].from).toBeUndefined()
    await w.get('#roi-from').setValue('')
    await flushPromises()
    expect(w.find('[data-test="range-error"]').exists()).toBe(false)
    expect(w.find('[data-test="range-too-long"]').exists()).toBe(false)
  })

  it('un 422 del servidor por el periodo se muestra con su mensaje', async () => {
    api.roi.mockRejectedValue(new ApiError(422, { message: 'El periodo no puede superar 730 días.', errors: {} } as never, 'x'))
    const w = await mountView()
    expect(w.get('[role="alert"]').text()).toContain('730 días')
  })

  it('W5-C final, quien no aprueba: sin experiments, control_baseline, control_held_out ni by_action_kind.control, con el ROI reducido; la vista se pinta completa y sin nada del control', async () => {
    const clean = roiReport({
      roi: { status: 'potential_unproven', label: 'Oportunidad potencial, atribución no demostrada', proven: false, causal_claims: 0, currency: 'USD', value: null, message: 'El detalle del análisis por grupos solo está disponible para quien puede aprobar (decisions.approve).' },
    })
    delete (clean as { experiments?: unknown }).experiments
    delete (clean.summary as { control_baseline?: unknown }).control_baseline
    delete (clean.summary.funnel as { control_held_out?: number }).control_held_out
    api.roi.mockResolvedValue(clean)
    for (const role of ['viewer', 'analyst'] as const) {
      const w = await mountView(role)
      expect(w.find('[data-test="experiments"]').exists(), role).toBe(false)
      expect(w.find('[data-test="roi-reasons"]').exists(), role).toBe(false)
      expect(w.find('[data-test="roi-conversion"]').exists(), role).toBe(false)
      // Los conteos por clasificación se muestran: la API ya no mezcla en ellos lo retenido.
      expect(w.get('[data-test="classifications"]').text(), role).toContain('Previsto: 5')
      expect(w.find('[data-test="classifications-hidden"]').exists(), role).toBe(false)
      expect(w.get('[data-test="funnel"]').text().toLowerCase(), role).not.toContain('control')
      expect(w.findAll('#oc-action option').map((o) => o.text()), role).not.toContain('Retenida para medición')
    }
  })

  it('W5-C final, quien aprueba: solo conversión estimada y cola pesada se ven en el titular, el incremento y la tarjeta del experimento; sin cifra monetaria', async () => {
    const e = experiment({ incremental: null, contribution: { status: 'not_estimable', reason: 'zero_variance', buyers: { treatment: 20, control: 12 } } })
    api.roi.mockResolvedValue(roiReport({
      experiments: [e],
      roi: { status: 'incremental_conversion_only', label: 'Incremento de conversión estimado; contribución no estimable', proven: false, causal_claims: 0, currency: 'USD', experiment_key: 'exp-contacto', horizon_days: 30,
        conversion: e.conversion ?? null, incremental_contribution: null, net_incremental_contribution: null, roi_ratio: null, reasons: ['sample_ratio_mismatch', 'heavy_tailed_contribution', 'zero_variance'],
        message: 'Hay estimación de la tasa de conversión, pero no de la contribución.' },
    }))
    const w = await mountView('admin')
    expect(w.get('[data-test="roi-headline"]').attributes('data-status')).toBe('incremental_conversion_only')
    expect(w.find('[data-test="roi-conversion"]').exists()).toBe(true)
    expect(w.find('[data-test="incremental-conversion-only"]').exists()).toBe(true)
    expect(w.get('[data-test="roi-reasons"]').text()).toContain('Compradores muy concentrados')
    expect(w.find('[data-test="roi-heavy-tail"]').exists()).toBe(false) // sin intervalo monetario no hay a qué rotular
    expect(w.get('[data-test="itt-no-contribution"]').text()).toContain('no hay variación en la contribución')
    expect(w.find('[data-test="roi-net"]').exists()).toBe(false)
    expect(w.find('[data-test="itt-incremental"]').exists()).toBe(false)
    expect(w.get('[data-test="roi-unproven"]').text()).toBe('No demostrado')
  })

  it('origen no declarado: la banda y el aviso del ROI dicen «no verificado» y la vista nunca lo supone real', async () => {
    api.roi.mockResolvedValue(roiReport({
      data_origin: { value: null, declared: false, note: 'Origen de los datos no verificado (tenants.settings.data_origin sin declarar): no se supone que sean reales.' },
      roi: { ...roiReport().roi, data_origin_warning: 'Origen de los datos no verificado (tenants.settings.data_origin sin declarar): no se supone que sean reales.' },
    }))
    const w = await mountView()
    expect(w.get('[data-test="origin-band"]').attributes('data-origin')).toBe('undeclared')
    expect(w.get('[data-test="origin-band"]').text()).toContain('no verificado')
    expect(w.get('[data-test="roi-origin-warning"]').text()).toContain('no verificado')
    expect(w.get('[data-test="origin"]').text()).toContain('no verificado')
  })

  it('el análisis de experimentos solo se muestra a quien aprueba', async () => {
    const withExperiment = roiReport({ experiments: [{ experiment_key: 'k', horizon_days: 30, method: 'itt', status: 'no_control_group', confidence_level: 0.95, min_group_size: 30, sample: { treatment: 10, control: 0, treatment_converted: 2, control_converted: 0 },
      conversion: { rate_treatment: 0.2, rate_control: null, difference: null, ci_low: null, ci_high: null, significant: null }, contribution: { status: 'not_estimated', reason: 'no_control_group' }, incremental: null, note: 'Sin estimación', contact_cost: { total: 0, actions: 0, actions_cost_unknown: 0 } }] })
    api.roi.mockResolvedValue(withExperiment)
    expect((await mountView('analyst')).find('[data-test="experiments"]').exists()).toBe(false)
    expect((await mountView('viewer')).find('[data-test="experiments"]').exists()).toBe(false)
    expect((await mountView('admin')).find('[data-test="experiments"]').exists()).toBe(true)
  })

  it('datos de demostración: banda y aviso junto al ROI', async () => {
    api.roi.mockResolvedValue(roiReport({ data_origin: { value: 'demo', declared: true, note: 'Datos de demostración o simulados: los importes de este informe NO son resultados reales.' }, roi: { ...roiReport().roi, data_origin_warning: 'Datos de demostración o simulados: los importes de este informe NO son resultados reales.' } }))
    const w = await mountView()
    expect(w.get('[data-test="origin-band"]').attributes('data-origin')).toBe('demo')
    expect(w.find('[data-test="roi-origin-warning"]').exists()).toBe(true)
    expect(w.get('[data-test="origin"]').attributes('data-origin')).toBe('demo')
  })

  it('los filtros viajan a /decisions/roi y un rango inválido no envía ninguna fecha', async () => {
    const w = await mountView()
    await w.get('#roi-kind').setValue('contact')
    await w.get('#roi-horizon').setValue(30)
    await w.get('#roi-group').setValue('horizon')
    await w.get('#roi-from').setValue('2026-10-01')
    await w.get('#roi-to').setValue('2026-09-01')
    await flushPromises()
    expect(w.get('[data-test="range-error"]').text()).toContain('no puede ser anterior')
    const last = api.roi.mock.calls.at(-1)![0]
    expect(last).toMatchObject({ kind: 'contact', horizon_days: 30, group_by: 'horizon' })
    expect(last.from).toBeUndefined()
    expect(last.to).toBeUndefined()
    // Con un rango válido viajan las dos fechas, y el corte de datos lleva los mismos filtros que el informe.
    await w.get('#roi-to').setValue('2026-10-31')
    await flushPromises()
    expect(api.roi.mock.calls.at(-1)![0]).toMatchObject({ kind: 'contact', horizon_days: 30, group_by: 'horizon', from: '2026-10-01', to: '2026-10-31' })
    expect(api.outcomeList.mock.calls.at(-1)![0]).toMatchObject({ kind: 'contact', horizon_days: 30, from: '2026-10-01', to: '2026-10-31' })
  })

  it('un error del informe se muestra con reintento y no deja cifras en pantalla', async () => {
    api.roi.mockRejectedValue(new ApiError(500, null, 'x'))
    const w = await mountView()
    expect(w.find('[role="alert"]').exists()).toBe(true)
    expect(w.find('[data-test="value-kinds"]').exists()).toBe(false)
  })

  it('un viewer puede ver el informe (decisions.view) pero no el grupo del experimento', async () => {
    const w = await mountView('viewer')
    expect(w.find('[data-test="roi-headline"]').exists()).toBe(true)
    // El método sí se explica; lo que no aparece es el análisis por grupos ni el grupo de ninguna unidad.
    expect(w.text()).not.toContain('Experimentos (intención de tratamiento)')
    expect(w.text()).not.toContain('Tratamiento (se contacta)')
    expect(w.text()).not.toContain('Control (retenida para medición)')
  })

  it('M3: al cambiar de empresa vuelve a pedir el informe, el desglose y el corte, y no queda ninguna cifra de la anterior', async () => {
    const w = await mountView()
    expect(w.find('[data-test="roi-headline"]').exists()).toBe(true)
    const before = { roi: api.roi.mock.calls.length, latest: api.outcomeList.mock.calls.length }
    api.roi.mockImplementation(() => new Promise(() => undefined))
    useTenantStore().currentUuid = 'otra-empresa'
    await flushPromises()
    expect(api.roi.mock.calls.length).toBe(before.roi + 1)
    expect(api.outcomeList.mock.calls.length).toBeGreaterThan(before.latest)
    expect(w.find('[data-test="roi-headline"]').exists()).toBe(false)
    expect(w.find('[data-test="value-kinds"]').exists()).toBe(false)
  })
})

describe('Administración', () => {
  const mountView = async (role: TenantRole, tab?: string) => {
    const w = mount(EnterpriseAdminView, { props: { tab }, global: { plugins: [tenantAs(role)], stubs: { ModelApprovals: { template: '<div data-test="stub-models" />' }, UsageAndCosts: { template: '<div data-test="stub-usage" />' }, DataQualityPanel: { template: '<div data-test="stub-quality" />' }, DataCoveragePanel: { template: '<div data-test="stub-coverage" />' }, AuditLog: { template: '<div data-test="stub-audit" />' } } } })
    await flushPromises()
    return w
  }
  const tabs = (w: Awaited<ReturnType<typeof mountView>>) => w.findAll('[role="tab"]').map((t) => t.text())

  it('el administrador ve modelos, calidad, uso y auditoría; la primera pestaña es la de modelos', async () => {
    const w = await mountView('admin')
    expect(tabs(w)).toEqual(['Modelos y aprobaciones', 'Calidad de datos', 'Uso y costos', 'Auditoría'])
    expect(w.find('[data-test="stub-models"]').exists()).toBe(true)
  })

  it.each([['calidad', 'stub-quality'], ['uso', 'stub-usage'], ['auditoria', 'stub-audit']] as const)('pestaña %s', async (tab, stub) => {
    const w = await mountView('owner', tab)
    expect(w.find(`[data-test="${stub}"]`).exists()).toBe(true)
  })

  it('una pestaña desconocida cae en modelos', async () => {
    const w = await mountView('admin', 'inexistente')
    expect(w.find('[data-test="stub-models"]').exists()).toBe(true)
  })

  it('declara que el servidor decide cada acción', async () => {
    const w = await mountView('admin')
    expect(w.text()).toContain('el servidor decide siempre')
  })
})
