import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import { setupTenant } from '@/test/helpers'
import type { CausalCheck, Paginated, PlanDetail, PlanLine, PlanShortfall, ResultEnvelope, ScenarioEvaluationResult } from '@/api/types'

const api = vi.hoisted(() => ({ plan: vi.fn(), planLines: vi.fn(), planShortfalls: vi.fn(), causalCheck: vi.fn(), evaluateScenarios: vi.fn(), plans: vi.fn() }))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  supplyApi: { plan: api.plan, planLines: api.planLines, planShortfalls: api.planShortfalls, causalCheck: api.causalCheck, evaluateScenarios: api.evaluateScenarios, plans: api.plans },
}))

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
const shortfall = (over: Partial<PlanShortfall> = {}): PlanShortfall => ({
  id: 1, branch_id: 1, branch: { id: 1, code: '001', name: 'Matriz' }, product_id: 4, product: { id: 4, sku: 'CLA-1', name: 'Clavos' }, period_start: '2026-10-27', period_end: '2026-11-02', demand: 50, unmet: 40, reason: 'lead_time', ...over,
})
/** Hoy en las pruebas es 31-oct-2026; por defecto el plan se calculó con datos hasta el 26-oct (5 días antes). */
const detail = (over: Partial<PlanDetail> = {}, planOver: Record<string, unknown> = {}): PlanDetail => ({
  run: { id: 3, error_message: null } as never,
  plan: { id: 1, objective: 'min_cost', coverage_mode: 'soft', solver_status: 'feasible_with_gap', mip_gap: 0.0042, objective_value: 1000, solve_seconds: 12.4, currency: 'USD', total_purchase_cost: 5000, total_holding_cost: 300, total_shortage_penalty: 120,
    unmet_units_total: 40, demand_units_total: 800, forecast_run_id: 9, is_optimal: false, note: 'Sugerencias.', as_of: '2026-10-26', today: '2026-10-31', timezone: 'America/Guayaquil', as_of_age_days: 5, ...planOver } as never,
  lines: [], shortfalls: [],
  lines_summary: { total: 2, shown: 2, matched: 2, truncated: false, actionable_now: 1 }, shortfalls_summary: { total: 1, shown: 1, matched: 1, truncated: false, unmet_units: 40, demand_units: 50 },
  branches: [{ id: 1, code: '001', name: 'Matriz' }, { id: 2, code: '002', name: 'Norte' }],
  constraints: [{ family: 'budget', ref: 1, period: 'x', used: 5000, limit: 5000, binding: true }], solver: { status: 'feasible_with_gap', notes: ['Brecha dentro de la tolerancia pedida.'] }, diagnosis: null, infeasibility: null, alternative_plan: null,
  offers: [{ offer_id: 9, supplier_id: 3, product_id: 1, status: 'excluded', reasons: ['no_fx_rate'], messages: [] }, { offer_id: 5, supplier_id: 2, product_id: 1, status: 'comparable', reasons: [], messages: [] }],
  comparison_coverage: { offers_received: 2, offers_comparable: 1, offers_excluded: 1, comparable_share: 0.5 }, offer_diagnostics: [], excluded_nodes: [], production: { status: 'not_configured' }, balance: null, periods: [], ...over,
})
const pageOf = <R,>(data: R[], total = data.length, current = 1, perPage = 25): Paginated<R> => ({ data, meta: { current_page: current, per_page: perPage, total, last_page: Math.max(1, Math.ceil(total / perPage)), from: null, to: null } })
const defaultLines = (): PlanLine[] => [line({ id: 1, actionable_now: true, binding_constraints: [{ family: 'budget', ref: 1, period: 'x', used: 5000, limit: 5000 }] }), line({ id: 2, product_id: 2, order_date: '2026-11-03' })]
/** Respuestas por defecto de las dos tablas paginadas; cada prueba puede sobrescribirlas. */
function mockTables(lines: PlanLine[] = defaultLines(), shortfalls: PlanShortfall[] = [shortfall()]): void {
  api.planLines.mockResolvedValue(pageOf(lines))
  api.planShortfalls.mockResolvedValue(pageOf(shortfalls))
}
const mountResults = () => mount(PlanResults, { props: { runId: 3 }, global: { plugins: [setupTenant('analyst')] } })

beforeEach(() => { for (const f of Object.values(api)) f.mockReset() })
afterEach(() => { vi.useRealTimers() })

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
  it('muestra las líneas con proveedor, fechas de emisión y recepción, costo, restricciones y lo que se emite al corte', async () => {
    api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
    mockTables()
    const w = mountResults()
    await flushPromises()
    expect(w.get('[data-test="no-orders-notice"]').text()).toContain('no envía pedidos ni compromete fondos')
    expect(w.get('[data-test="solver-label"]').text()).toBe('Factible con brecha')
    const rows = w.findAll('[data-test="plan-lines"] tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Cementos del Sur')
    expect(rows[0]!.text()).toContain('Emitir al corte')
    expect(rows[0]!.text()).toMatch(/1\.?620/)
    expect(rows[0]!.text()).toContain('pallet')
    expect(rows[0]!.get('[data-test="binding"]').text()).toBe('Presupuesto')
    expect(rows[0]!.text()).toContain('Menor costo puesto en bodega')
    expect(w.findAll('[data-test="actionable-now"]')).toHaveLength(1)
    expect(w.get('[data-test="lines-count"]').text()).toBe('(2 líneas, 1 para emitir al corte del plan)')
  })

  it('el detalle no pide las listas completas: pide la primera página de líneas y de demanda no atendida (25 filas)', async () => {
    api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
    mockTables()
    mountResults()
    await flushPromises()
    expect(api.plan).toHaveBeenCalledTimes(1)
    expect(api.plan.mock.calls[0]).toEqual([3])
    expect(api.planLines).toHaveBeenCalledWith(3, { page: 1, per_page: 25 })
    expect(api.planShortfalls).toHaveBeenCalledWith(3, { page: 1, per_page: 25 })
  })

  describe('fecha de corte', () => {
    it('muestra «Datos hasta» con la fecha de corte y avisa que el plan se calculó con datos anteriores a hoy', async () => {
      api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
      mockTables()
      const w = mountResults()
      await flushPromises()
      expect(w.get('[data-test="plan-cutoff"]').text()).toContain('Datos hasta 26 de octubre de 2026')
      const stale = w.get('[data-test="plan-stale"]')
      expect(stale.text()).toContain('26 de octubre de 2026')
      expect(stale.text()).toContain('5 días antes de hoy')
      expect(stale.text()).toContain('generarlo de nuevo con datos actuales')
    })

    it('con el corte en un solo día de antigüedad habla en singular', async () => {
      api.plan.mockResolvedValue({ data: detail({}, { as_of: '2026-10-30', as_of_age_days: 1 }), envelope: envelope() })
      mockTables()
      const w = mountResults()
      await flushPromises()
      expect(w.get('[data-test="plan-stale"]').text()).toContain('1 día antes de hoy')
    })

    it('las líneas del día de corte dicen «Emitir al corte» (nunca «hoy») si el plan es de datos anteriores, con el motivo en el título', async () => {
      api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
      mockTables()
      const w = mountResults()
      await flushPromises()
      const badge = w.get('[data-test="actionable-now"]')
      expect(badge.text()).toBe('Emitir al corte')
      expect(badge.attributes('data-kind')).toBe('cutoff')
      expect(badge.attributes('title')).toContain('fecha de corte del plan (26 de octubre de 2026), no hoy')
      expect(w.text()).not.toContain('Emitir hoy')
      expect(w.text()).not.toContain('Emitir ya')
      expect(w.get('[data-test="filter-only-now"]').element.parentElement!.textContent).toContain('Solo lo que se emite al corte del plan')
    })

    it('si el corte es hoy, las líneas del día de corte dicen «Emitir ya», sin aviso de desactualizado', async () => {
      api.plan.mockResolvedValue({ data: detail({}, { as_of: '2026-10-31', as_of_age_days: 0 }), envelope: envelope() })
      mockTables()
      const w = mountResults()
      await flushPromises()
      expect(w.get('[data-test="plan-cutoff"]').text()).toContain('Datos hasta 31 de octubre de 2026')
      expect(w.find('[data-test="plan-stale"]').exists()).toBe(false)
      const badge = w.get('[data-test="actionable-now"]')
      expect(badge.text()).toBe('Emitir ya')
      expect(badge.attributes('data-kind')).toBe('now')
      expect(w.get('[data-test="lines-count"]').text()).toBe('(2 líneas, 1 para emitir ya)')
      expect(w.get('[data-test="filter-only-now"]').element.parentElement!.textContent).toContain('Solo lo que puedo emitir ya')
    })

    it('sin fecha de corte no afirma que sea de hoy: rotula «Emitir al corte» y lo declara', async () => {
      api.plan.mockResolvedValue({ data: detail({}, { as_of: null, as_of_age_days: null }), envelope: envelope() })
      mockTables()
      const w = mountResults()
      await flushPromises()
      expect(w.find('[data-test="plan-cutoff"]').exists()).toBe(false)
      expect(w.get('[data-test="plan-cutoff-unknown"]').text()).toContain('no declara su fecha de corte')
      expect(w.find('[data-test="plan-stale"]').exists()).toBe(false)
      expect(w.get('[data-test="actionable-now"]').text()).toBe('Emitir al corte')
    })
  })

  describe('paginación y filtros en el servidor', () => {
    it('con miles de líneas solo dibuja la página (25 filas) y pagina con la barra', async () => {
      api.plan.mockResolvedValue({ data: detail({ lines_summary: { total: 1358, shown: 20, matched: 1358, truncated: true, actionable_now: 40 } }), envelope: envelope() })
      const many = Array.from({ length: 25 }, (_, i) => line({ id: i + 1, product_id: i + 1, product: { id: i + 1, sku: `S-${i + 1}`, name: `Producto ${i + 1}` } }))
      api.planLines.mockImplementation((_id: number, q: { page?: number }) => Promise.resolve(pageOf(many.map((l) => ({ ...l, id: (q.page ?? 1) * 100 + (l.id ?? 0) })), 1358, q.page ?? 1)))
      api.planShortfalls.mockResolvedValue(pageOf([shortfall()]))
      const w = mountResults()
      await flushPromises()
      expect(w.findAll('[data-test="plan-lines"] tbody tr')).toHaveLength(25)
      expect(w.get('[data-test="lines-count"]').text()).toContain('1.358 líneas')
      const nav = w.get('[data-test="plan-lines-section"] nav')
      expect(nav.text()).toContain('1–25 de 1.358')
      expect(nav.text()).toContain('Página 1 de 55')
      await nav.get('button[aria-label="Página siguiente"]').trigger('click')
      await vi.waitFor(() => expect(api.planLines).toHaveBeenLastCalledWith(3, { page: 2, per_page: 25 }))
      await flushPromises()
      expect(w.get('[data-test="plan-lines-section"] nav').text()).toContain('Página 2 de 55')
    })

    describe('filtros de las líneas', () => {
      beforeEach(() => { vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] }) })
      const settle = async (): Promise<void> => { await vi.advanceTimersByTimeAsync(300); await flushPromises() }

      it('filtra por sucursal, búsqueda de producto y «solo al corte», vuelve a la página 1 y puede quitar los filtros', async () => {
        api.plan.mockResolvedValue({ data: detail({ lines_summary: { total: 80, shown: 20, matched: 80, truncated: true, actionable_now: 3 } }), envelope: envelope() })
        api.planLines.mockImplementation((_id: number, q: { page?: number }) => Promise.resolve(pageOf(defaultLines(), 80, q.page ?? 1)))
        api.planShortfalls.mockResolvedValue(pageOf([shortfall()]))
        const w = mountResults()
        await flushPromises()

        const nav = () => w.get('[data-test="plan-lines-section"] nav')
        await nav().get('button[aria-label="Página siguiente"]').trigger('click')
        await settle()
        expect(api.planLines).toHaveBeenLastCalledWith(3, { page: 2, per_page: 25 })

        await w.get('[data-test="filter-branch"]').setValue(2)
        await settle()
        expect(api.planLines).toHaveBeenLastCalledWith(3, { page: 1, per_page: 25, branch_id: 2 })

        await w.get('[data-test="filter-search"]').setValue('  cemento ')
        await settle()
        expect(api.planLines).toHaveBeenLastCalledWith(3, { page: 1, per_page: 25, branch_id: 2, search: 'cemento' })

        await w.get('[data-test="filter-only-now"]').setValue(true)
        await settle()
        expect(api.planLines).toHaveBeenLastCalledWith(3, { page: 1, per_page: 25, branch_id: 2, search: 'cemento', actionable_now: true })

        await w.get('[data-test="clear-filters"]').trigger('click')
        await settle()
        expect(api.planLines).toHaveBeenLastCalledWith(3, { page: 1, per_page: 25 })
        expect(w.find('[data-test="clear-filters"]').exists()).toBe(false)
        // las opciones de sucursal salen del propio plan
        expect(w.get('[data-test="filter-branch"]').findAll('option').map((o) => o.text())).toEqual(['Todas las sucursales', 'Matriz', 'Norte'])
      })

      it('escribir varias letras seguidas hace una sola consulta (espera a que termine de escribir)', async () => {
        api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
        mockTables()
        const w = mountResults()
        await flushPromises()
        api.planLines.mockClear()
        const input = w.get('[data-test="filter-search"]')
        for (const text of ['c', 'ce', 'cem']) { await input.setValue(text); await vi.advanceTimersByTimeAsync(100) }
        await settle()
        expect(api.planLines).toHaveBeenCalledTimes(1)
        expect(api.planLines).toHaveBeenLastCalledWith(3, { page: 1, per_page: 25, search: 'cem' })
      })

      it('ordena en el servidor al pulsar el encabezado de una columna', async () => {
        api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
        mockTables()
        const w = mountResults()
        await flushPromises()
        const header = w.findAll('[data-test="plan-lines"] th').find((th) => th.text().includes('Costo de la línea'))!
        await header.get('button').trigger('click')
        await settle()
        expect(api.planLines).toHaveBeenLastCalledWith(3, { page: 1, per_page: 25, sort: '-line_cost' })
        await header.get('button').trigger('click')
        await settle()
        expect(api.planLines).toHaveBeenLastCalledWith(3, { page: 1, per_page: 25, sort: 'line_cost' })
      })

      it('sin coincidencias lo dice, y un error de la API se muestra con reintento', async () => {
        api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
        mockTables()
        const w = mountResults()
        await flushPromises()
        api.planLines.mockResolvedValue(pageOf([], 0))
        await w.get('[data-test="filter-search"]').setValue('zzz')
        await settle()
        expect(w.get('[data-test="plan-lines"]').text()).toContain('Ninguna línea coincide con los filtros')
        api.planLines.mockRejectedValue(new ApiError(500, { message: 'El servidor falló.' }, 'x'))
        await w.get('[data-test="filter-search"]').setValue('zzzz')
        await settle()
        expect(w.find('[data-test="plan-lines"]').exists()).toBe(false)
        expect(w.get('[data-test="plan-lines-section"]').text()).toContain('Reintentar')
        // el resto del plan sigue visible
        expect(w.find('[data-test="plan-totals"]').exists()).toBe(true)
      })
    })

    it('un plan sin líneas no muestra tabla ni filtros ni consulta las líneas', async () => {
      api.plan.mockResolvedValue({ data: detail({ lines_summary: { total: 0, shown: 0, matched: 0, truncated: false, actionable_now: 0 } }), envelope: envelope() })
      mockTables()
      const w = mountResults()
      await flushPromises()
      expect(w.get('[data-test="no-lines"]').text()).toContain('no sugiere compras')
      expect(w.find('[data-test="plan-lines"]').exists()).toBe(false)
      expect(w.find('[data-test="lines-filters"]').exists()).toBe(false)
      expect(api.planLines).not.toHaveBeenCalled()
    })
  })

  describe('demanda no atendida', () => {
    it('se explica con su causa; los totales vienen del resumen del servidor, no de las filas de la página', async () => {
      api.plan.mockResolvedValue({ data: detail({ shortfalls_summary: { total: 300, shown: 20, matched: 300, truncated: true, unmet_units: 4000, demand_units: 20000 } }), envelope: envelope() })
      api.planLines.mockResolvedValue(pageOf(defaultLines()))
      api.planShortfalls.mockImplementation((_id: number, q: { page?: number }) => Promise.resolve(pageOf([shortfall()], 300, q.page ?? 1)))
      const w = mountResults()
      await flushPromises()
      expect(w.get('[data-test="shortfall-reason"]').text()).toBe('El proveedor tarda más de lo que se puede esperar')
      expect(w.get('[data-test="shortfalls"]').text()).toContain('Clavos')
      expect(w.get('[data-test="shortfalls-summary"]').text()).toMatch(/4\.000 unidades en total \(20\s?% de la demanda del plan\)/)
      expect(w.get('[data-test="shortfalls"] nav').text()).toContain('1–25 de 300')
      expect(w.get('[data-test="unmet-total"]').text()).toBe('40')
      expect(w.get('[data-test="plan-totals"]').text()).toContain('supuesto, no costo observado')
    })

    it('filtra por sucursal y producto en el servidor', async () => {
      vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
      api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
      mockTables()
      const w = mountResults()
      await flushPromises()
      await w.get('[data-test="shortfall-filter-branch"]').setValue(2)
      await vi.advanceTimersByTimeAsync(300)
      await flushPromises()
      expect(api.planShortfalls).toHaveBeenLastCalledWith(3, { page: 1, per_page: 25, branch_id: 2 })
      await w.get('[data-test="shortfall-filter-search"]').setValue('clavo')
      await vi.advanceTimersByTimeAsync(300)
      await flushPromises()
      expect(api.planShortfalls).toHaveBeenLastCalledWith(3, { page: 1, per_page: 25, branch_id: 2, search: 'clavo' })
    })

    it('si el plan atiende toda la demanda lo dice y no consulta la tabla', async () => {
      api.plan.mockResolvedValue({ data: detail({ shortfalls_summary: { total: 0, shown: 0, matched: 0, truncated: false, unmet_units: 0, demand_units: 0 } }), envelope: envelope() })
      mockTables()
      const w = mountResults()
      await flushPromises()
      expect(w.get('[data-test="shortfalls"]').text()).toContain('atiende toda la demanda pronosticada')
      expect(w.find('[data-test="shortfalls-table"]').exists()).toBe(false)
      expect(api.planShortfalls).not.toHaveBeenCalled()
    })
  })

  it('lista las ofertas excluidas con su motivo y declara la producción sin configurar', async () => {
    api.plan.mockResolvedValue({ data: detail(), envelope: envelope() })
    mockTables()
    const w = mountResults()
    await flushPromises()
    expect(w.get('[data-test="excluded-offers"]').text()).toContain('Falta una tasa de cambio explícita')
    expect(w.get('[data-test="offers-coverage"]').text()).toContain('2 ofertas recibidas: 1 comparables y 1 excluidas')
    expect(w.get('[data-test="production-not-configured"]').text()).toContain('Producción no configurada')
  })

  it('muestra la calidad, las limitaciones y los supuestos de la envoltura', async () => {
    api.plan.mockResolvedValue({ data: detail(), envelope: envelope({ quality_status: 'degraded' }) })
    mockTables()
    const w = mountResults()
    await flushPromises()
    expect(w.get('[data-test="quality-badge"]').text()).toBe('Con reservas')
    expect(w.get('[data-test="envelope-limitations"]').text()).toContain('Solo ventas perdidas')
    expect(w.get('[data-test="envelope-assumptions"]').text()).toContain('supuesto')
  })

  it('infactible: bloque con causas ordenadas, requisito y plan de máxima cobertura; sin tabla de líneas', async () => {
    api.plan.mockResolvedValue({
      data: detail({
        lines_summary: { total: 0, shown: 0, matched: 0, truncated: false, actionable_now: 0 }, shortfalls_summary: { total: 0, shown: 0, matched: 0, truncated: false, unmet_units: 0, demand_units: 0 },
        infeasibility: { message: 'Con cobertura obligatoria no existe un plan que respete todas las restricciones.', alternatives: [], structural: { family: 'structural', label: 'plazos de entrega', unmet_even_without_constraints: 10 }, causes: [
          { family: 'budget', label: 'presupuesto de compra', units_recovered: 5, unmet_with_constraint: 60, unmet_if_relaxed: 55 },
          { family: 'storage', label: 'capacidad de almacenamiento', units_recovered: 45, unmet_with_constraint: 60, unmet_if_relaxed: 15, required: [{ needed: 900, declared: 500 }] }] },
        alternative_plan: { description: 'Plan de máxima cobertura factible: respeta todas las restricciones y declara lo no atendido.', summary: { unmet_units_total: 60 }, lines: [line()], shortfalls: [] },
      }, { solver_status: 'infeasible', mip_gap: null, total_purchase_cost: null, is_optimal: false }),
      envelope: envelope({ quality_status: 'degraded' }),
    })
    mockTables()
    const w = mountResults()
    await flushPromises()
    expect(w.get('[data-test="solver-label"]').text()).toBe('Sin plan posible')
    const block = w.get('[data-test="infeasibility"]')
    expect(block.text()).toContain('No existe un plan que respete todas las restricciones')
    const causes = block.findAll('tbody tr')
    expect(causes[0]!.text()).toContain('capacidad de almacenamiento')
    expect(causes[0]!.text()).toContain('se necesitan 900 y se declararon 500')
    expect(block.get('[data-test="alternative-plan"]').text()).toContain('máxima cobertura')
    expect(w.find('[data-test="plan-lines"]').exists()).toBe(false)
    expect(api.planLines).not.toHaveBeenCalled()
  })

  it('sin plan (datos insuficientes): abstención explícita y calidad visible', async () => {
    api.plan.mockResolvedValue({ data: detail({ plan: null, run: { id: 3, error_message: 'No hay un pronóstico de demanda completado.' } as never }), envelope: envelope({ quality_status: 'insufficient_data', limitations: ['No hay un pronóstico de demanda completado.'] }) })
    mockTables()
    const w = mountResults()
    await flushPromises()
    expect(w.get('[data-test="plan-abstention"]').text()).toContain('No hay plan')
    expect(w.text()).toContain('No hay un pronóstico de demanda completado.')
    expect(w.find('[data-test="plan-lines"]').exists()).toBe(false)
    expect(w.find('[data-test="plan-cutoff"]').exists()).toBe(false)
    expect(w.get('[data-test="quality-badge"]').text()).toBe('Datos insuficientes')
    expect(api.planLines).not.toHaveBeenCalled()
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
