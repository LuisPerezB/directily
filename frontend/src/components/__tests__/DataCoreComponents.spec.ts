import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import { setupTenant } from '@/test/helpers'

const api = vi.hoisted(() => ({
  mappings: vi.fn(), createMapping: vi.fn(), previewMapping: vi.fn(), approveMapping: vi.fn(), deactivateMapping: vi.fn(), sync: vi.fn(), syncs: vi.fn(), syncById: vi.fn(),
  fields: vi.fn(), coverage: vi.fn(), declareCoverage: vi.fn(), extendedQuality: vi.fn(), calendar: vi.fn(), commitments: vi.fn(), commitment: vi.fn(), cancelCommitment: vi.fn(),
  test: vi.fn(), update: vi.fn(), connectors: vi.fn(), list: vi.fn(), customerModels: vi.fn(), rollbackCustomer: vi.fn(),
}))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  sqlSourcesApi: { mappings: api.mappings, createMapping: api.createMapping, previewMapping: api.previewMapping, approveMapping: api.approveMapping, deactivateMapping: api.deactivateMapping, sync: api.sync, syncs: api.syncs, syncById: api.syncById },
  importsApi: { fields: api.fields },
  dataCoreApi: { coverage: api.coverage, declareCoverage: api.declareCoverage, extendedQuality: api.extendedQuality, calendar: api.calendar, commitments: api.commitments, commitment: api.commitment, cancelCommitment: api.cancelCommitment },
  dataSourcesApi: { test: api.test, update: api.update, connectors: api.connectors, list: api.list },
  accuracyApi: { models: api.customerModels },
  modelsApi: { rollbackCustomer: api.rollbackCustomer, rollbackDemand: vi.fn() },
}))

import SqlQueryStep from '../datasources/SqlQueryStep.vue'
import SqlSyncStep from '../datasources/SqlSyncStep.vue'
import SqlConnectionStep from '../datasources/SqlConnectionStep.vue'
import DataCoveragePanel from '../datasources/DataCoveragePanel.vue'
import DataQualityPanel from '../datasources/DataQualityPanel.vue'
import CalendarPanel from '../datasources/CalendarPanel.vue'
import CommitmentsPanel from '../datasources/CommitmentsPanel.vue'
import CustomerModels from '../customers/CustomerModels.vue'
import StatusBadge from '../ui/StatusBadge.vue'
import type { Commitment, Connector, DataSource, SourceMapping } from '@/api/types'

beforeEach(() => { for (const f of Object.values(api)) f.mockReset() })

const mapping = (over: Partial<SourceMapping> = {}): SourceMapping => ({
  id: 1, data_source_id: 4, entity: 'sales', version: 1, query: 'SELECT 1', field_map: {}, options: {}, is_active: false, approved_at: null, approved_by: null, created_at: '2026-10-26T12:00:00Z', ...over,
})
const salesFields = [
  { key: 'issued_at', label: 'Fecha de emisión', required: true, type: 'datetime' }, { key: 'product_sku', label: 'SKU', required: true, type: 'string' }, { key: 'notes', label: 'Notas', required: false, type: 'string' },
]

describe('Asistente SQL: consultas autorizadas', () => {
  async function mountQuery() {
    api.mappings.mockResolvedValue([])
    api.fields.mockResolvedValue(salesFields)
    const w = mount(SqlQueryStep, { props: { sourceId: 4 }, global: { plugins: [setupTenant('admin')] } })
    await flushPromises()
    return w
  }
  const fill = async (w: Awaited<ReturnType<typeof mountQuery>>) => {
    await w.get('[data-test="query-editor"]').setValue('SELECT fecha, sku FROM ventas')
    await w.get('#sq-f-issued_at').setValue('fecha')
    await w.get('#sq-f-product_sku').setValue('sku')
  }

  it('no deja guardar mientras falten campos obligatorios', async () => {
    const w = await mountQuery()
    await w.get('[data-test="query-editor"]').setValue('SELECT 1')
    expect(w.get('[data-test="missing-fields"]').text()).toContain('issued_at, product_sku')
    expect(w.get('[data-test="save-version"]').attributes('disabled')).toBeDefined()
    await fill(w)
    expect(w.find('[data-test="missing-fields"]').exists()).toBe(false)
    expect(w.get('[data-test="save-version"]').attributes('disabled')).toBeUndefined()
  })

  it('envía la consulta y la equivalencia de columnas; cada guardado es una versión nueva pendiente', async () => {
    const w = await mountQuery()
    api.createMapping.mockResolvedValue(mapping({ version: 2 }))
    await fill(w)
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(api.createMapping).toHaveBeenCalledWith(4, { entity: 'sales', query: 'SELECT fecha, sku FROM ventas', field_map: { issued_at: 'fecha', product_sku: 'sku' }, options: {} })
  })

  it('una consulta que no es de solo lectura se rechaza y se muestra el motivo junto al editor', async () => {
    const w = await mountQuery()
    api.createMapping.mockRejectedValue(new ApiError(422, { message: 'rechazada', code: 'sql_query_rejected', reason: 'Solo se permite una sentencia SELECT.' } as never, 'x'))
    await fill(w)
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(w.get('[data-test="query-rejected"]').text()).toContain('Solo se permite una sentencia SELECT.')
  })

  it('una versión nueva nace pendiente: aprobar exige confirmación y activa la versión', async () => {
    api.mappings.mockResolvedValue([mapping({ id: 11, version: 3 }), mapping({ id: 10, version: 2, is_active: true, approved_at: '2026-10-20T00:00:00Z', approved_by: { id: 1, name: 'Ana' } })])
    api.fields.mockResolvedValue(salesFields)
    api.approveMapping.mockResolvedValue(mapping({ id: 11, version: 3, is_active: true }))
    const w = mount(SqlQueryStep, { props: { sourceId: 4 }, global: { plugins: [setupTenant('admin')] } })
    await flushPromises()
    const states = w.findAll('[data-state]').map((e) => e.attributes('data-state'))
    expect(states).toEqual(['pending', 'active'])
    await w.get('[data-test="approve"]').trigger('click')
    expect(api.approveMapping).not.toHaveBeenCalled()
    expect(w.text()).toContain('¿Aprobar esta versión?')
    await w.findAll('button').find((b) => b.text() === 'Aprobar' && b.classes().includes('btn-primary'))!.trigger('click')
    await flushPromises()
    expect(api.approveMapping).toHaveBeenCalledWith(4, 11)
  })

  it('la vista previa muestra los campos canónicos y aclara que no se importó nada', async () => {
    api.mappings.mockResolvedValue([mapping({ id: 11, version: 1 })])
    api.fields.mockResolvedValue(salesFields)
    api.previewMapping.mockResolvedValue({ headers: ['issued_at', 'product_sku', 'customer_code'], rows: [['2026-10-01 10:00:00', 'CEM-50', '09*****']], rows_returned: 1, note: 'Vista previa de campos canónicos; no se importó nada.' })
    const w = mount(SqlQueryStep, { props: { sourceId: 4 }, global: { plugins: [setupTenant('admin')] } })
    await flushPromises()
    await w.get('[data-test="preview"]').trigger('click')
    await flushPromises()
    const preview = w.get('[data-test="preview-result"]')
    expect(preview.text()).toContain('no se importó nada')
    expect(preview.text()).toContain('CEM-50')
    expect(preview.text()).toContain('09*****')
  })
})

describe('Asistente SQL: sincronización y conexión', () => {
  it('una sincronización por revisar enlaza a la importación con sus errores', async () => {
    api.syncs.mockResolvedValue({ data: [{ id: 5, data_source_id: 4, entity: 'sales', mapping_version: 2, mode: 'full', status: 'needs_review', since: null, watermark: null, rows_extracted: 300, pages: 1, import_job_id: 88, stats: {}, error_message: null, started_at: null, finished_at: '2026-10-26T12:00:00Z', created_at: '2026-10-26T11:00:00Z' }], meta: {} })
    const w = mount(SqlSyncStep, { props: { sourceId: 4 }, global: { plugins: [setupTenant('admin')] } })
    await flushPromises()
    expect(w.text()).toContain('Por revisar')
    expect(w.text()).toContain('Abrir la importación')
  })

  it('409 sin versión aprobada se explica', async () => {
    api.syncs.mockResolvedValue({ data: [], meta: {} })
    api.sync.mockRejectedValue(new ApiError(409, { message: 'x', code: 'mapping_not_approved' }, 'x'))
    const w = mount(SqlSyncStep, { props: { sourceId: 4 }, global: { plugins: [setupTenant('admin')] } })
    await flushPromises()
    await w.get('[data-test="start-sync"]').trigger('click')
    await flushPromises()
    expect(api.sync).toHaveBeenCalledWith(4, 'sales', 'incremental')
    expect(w.get('[data-test="sync-message"]').text()).toContain('No hay una versión aprobada')
  })

  const source: DataSource = { id: 4, type: 'connector', connector_key: 'sql_mysql', name: 'ERP MySQL', status: 'inactive', last_synced_at: null, last_error: null, config: { host: 'erp.local', port: 3306, database: 'erp' }, has_credentials: true }
  const connector: Connector = { key: 'sql_mysql', name: 'MySQL/MariaDB', description: '', status: 'implemented_untested', verification: 'implemented_untested', entity_types: ['sales'] }

  it('conector sin probar: aviso visible, y la prueba de conexión muestra las advertencias', async () => {
    api.test.mockResolvedValue({ ok: true, message: 'Conexión de solo lectura establecida.', details: { server_version: '8.0.36', warnings: ['La cuenta puede escribir en la base de datos.'], verification: 'implemented_untested', read_only_enforcement: 'transaction_read_only' } })
    const w = mount(SqlConnectionStep, { props: { source, connector }, global: { plugins: [setupTenant('admin')] } })
    expect(w.text()).toContain('Conector sin probar')
    await w.get('[data-test="test-connection"]').trigger('click')
    await flushPromises()
    const result = w.get('[data-test="test-result"]')
    expect(result.text()).toContain('8.0.36')
    expect(result.get('[data-test="test-warnings"]').text()).toContain('La cuenta puede escribir')
    expect(result.text()).toContain('Implementado, sin probar')
  })

  it('las credenciales guardadas nunca se muestran y quedan vacías para conservarlas', async () => {
    const w = mount(SqlConnectionStep, { props: { source, connector }, global: { plugins: [setupTenant('admin')] } })
    expect((w.get('input[type="password"]').element as HTMLInputElement).value).toBe('')
    expect(w.text()).toContain('Ya hay credenciales guardadas')
    api.update.mockResolvedValue(source)
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(api.update.mock.calls[0]![1]).not.toHaveProperty('credentials')
  })
})

describe('Cobertura y calidad', () => {
  it('cobertura sin declarar: se dice que los días sin registros son ausentes, no cero', async () => {
    api.coverage.mockResolvedValue({ data: [], effective: { sales: { entity: 'sales', complete_from: null, complete_through: null, rule: 'none', basis: null, sources: [] }, inventory: { entity: 'inventory', complete_from: null, complete_through: '2026-10-25', rule: 'declared_by_user', basis: 'declared', sources: [3] } } })
    const w = mount(DataCoveragePanel, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-rule="none"]').text()).toContain('Sin cobertura declarada')
    expect(w.get('[data-rule="none"]').text()).toContain('ausentes, no como cero')
    expect(w.get('[data-rule="declared_by_user"]').text()).toContain('Declarada por usted')
  })

  it('declarar la cobertura envía la fecha y muestra los errores de validación (no futura)', async () => {
    api.coverage.mockResolvedValue({ data: [], effective: {} })
    api.declareCoverage.mockRejectedValue(new ApiError(422, { message: 'x', errors: { complete_through: ['La fecha no puede ser futura.'] } }, 'x'))
    const w = mount(DataCoveragePanel, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    await w.get('#cv-through').setValue('2026-10-20')
    await w.get('[data-test="coverage-form"]').trigger('submit')
    await flushPromises()
    expect(api.declareCoverage).toHaveBeenCalledWith({ entity: 'sales', complete_through: '2026-10-20' })
    expect(w.text()).toContain('La fecha no puede ser futura.')
  })

  it('un usuario de consulta no puede declarar cobertura', async () => {
    api.coverage.mockResolvedValue({ data: [], effective: {} })
    const w = mount(DataCoveragePanel, { global: { plugins: [setupTenant('viewer')] } })
    await flushPromises()
    expect(w.find('[data-test="coverage-form"]').exists()).toBe(false)
  })

  it('calidad extendida: distingue ceros observados de días ausentes', async () => {
    const entity = { observed_from: '2025-01-01', observed_to: '2026-10-25', days_with_records: 600, coverage: { entity: 'sales', complete_from: null, complete_through: '2026-10-25', rule: 'declared_by_user', basis: 'declared', sources: [] }, status: 'declared', message: null }
    api.extendedQuality.mockResolvedValue({
      generated_at: '2026-10-26T12:00:00Z', as_of_local_date: '2026-10-26', overall_status: 'warning', notes: ['Fuera de la cobertura declarada o inferida, un día sin registros es AUSENTE, no cero.'],
      sections: {
        temporal_coverage: { status: 'ok', entities: { sales: entity } },
        sales_days: { status: 'ok', message: 'La fuente declara datos completos hasta 2026-10-25.', range: { from: '2025-01-01', to: '2026-10-25', days: 663 }, days_without_sales: 63, classification: 'observed_zero_days', observed_zero_days: 63, absent_days: 1, days_after_coverage: 1, longest_gaps: [{ from: '2026-01-01', to: '2026-01-03', days: 3 }] },
        customer_identity: { status: 'warning', message: 'Las ventas sin cliente son población anónima.', documents_total: 1000, documents_without_customer: 200, documents_with_generic_customer: 300, share_without_stable_identifier: 0.5, customers_total: 40, generic_customers: 1, customers_without_tax_id: 2 },
        product_code_changes: { status: 'ok', message: 'No se detectaron productos con el mismo nombre.', groups_detected: 0, groups: [] },
        price_history: { status: 'ok', message: 'Los precios históricos se toman del precio original.', products_with_sales: 10, products_with_price_variation: 4, products_with_variation_over_20pct: 1, price_history_rows: 12, price_history_rows_without_known_at: 12, top_variation: [] },
        units: { status: 'ok', message: 'Sin conversiones silenciosas.', incompatible_without_conversion: 0, commitment_lines_converted: 0, declared_conversions: 2, incompatible_units: [], categories_with_mixed_units: [] },
      },
    })
    const w = mount(DataQualityPanel, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.get('[data-test="observed-zero"]').text()).toBe('63')
    expect(w.get('[data-test="absent"]').text()).toBe('1')
    expect(w.get('[data-test="sales-days"]').text()).toContain('no se convierten en ceros')
    expect(w.get('[data-test="customer-identity"]').text()).toMatch(/50\s?% de los documentos/)
    expect(w.text()).toMatch(/No mide la precisión de ningún pronóstico/)
  })
})

describe('Calendario y compromisos', () => {
  const event = (over = {}) => ({ id: 1, is_public: false, scope: 'branch', scope_ref: 'branch:3', code: 'FERIA', name: 'Feria local', category: 'campaign', starts_on: '2026-11-10', ends_on: '2026-11-12', status: 'planned', known_at: null, known_at_unknown: true, source: 'manual', revision: 1, ...over })

  it('un evento sin fecha de conocimiento se marca y se avisa que no se usará como conocido de antemano', async () => {
    api.calendar.mockResolvedValue({ data: [event(), event({ id: 2, is_public: true, code: 'NAVIDAD', name: 'Navidad', scope: 'country', scope_ref: 'EC', known_at: '2026-01-01T00:00:00Z', known_at_unknown: false })], meta: { as_of: null, rule: 'latest_revision' } })
    const w = mount(CalendarPanel, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.findAll('[data-test="known-at-unknown"]')).toHaveLength(1)
    expect(w.get('[data-test="unknown-known-at"]').text()).toContain('no se usarán como conocidos de antemano')
    expect(w.text()).toContain('Feria local')
    expect(w.text()).toContain('público')
  })

  const commitment = (over: Partial<Commitment> = {}): Commitment => ({
    id: 9, commitment_type: 'order', external_id: 'PED-001', status: 'confirmed', issued_at: '2026-10-01T10:00:00Z', known_at: null, known_at_unknown: true, valid_from: null, valid_to: null, expected_delivery_date: '2026-11-05',
    cancelled_at: null, cancellation_date_unknown: false, currency: 'USD', customer_id: 3, branch_id: 1, items: [{ line_number: 1, product_id: 4, quantity: 10, quantity_fulfilled: 4, pending: 6, unit: 'saco', unit_price: 8, delivery_date: '2026-11-05' }], pending_total: 6, ...over,
  })

  it('compromisos: no son ventas; el detalle muestra lo pendiente y cancelar pide confirmación', async () => {
    api.commitments.mockResolvedValue({ data: [commitment()], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } })
    api.commitment.mockResolvedValue({ ...commitment(), revisions: [{ revision: 1, status: 'confirmed', known_at: null, cancelled_at: null, recorded_at: '2026-10-02T00:00:00Z' }] })
    api.cancelCommitment.mockResolvedValue(commitment({ status: 'cancelled' }))
    const w = mount(CommitmentsPanel, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(w.text()).toContain('No son ventas realizadas')
    await w.get('[data-test="commitment-open"]').trigger('click')
    await flushPromises()
    const detail = w.get('[data-test="commitment-detail"]')
    expect(detail.text()).toContain('Pedido confirmado')
    expect(detail.text()).toContain('Revisión 1')
    await w.get('[data-test="commitment-cancel"]').trigger('click')
    expect(api.cancelCommitment).not.toHaveBeenCalled()
    expect(w.text()).toContain('¿Cancelar este compromiso?')
    await w.findAll('button').filter((b) => b.text() === 'Cancelar compromiso').at(-1)!.trigger('click')
    await flushPromises()
    expect(api.cancelCommitment).toHaveBeenCalledWith(9)
  })

  it('409 already_cancelled se explica', async () => {
    api.commitments.mockResolvedValue({ data: [commitment()], meta: { current_page: 1, per_page: 20, total: 1, last_page: 1 } })
    api.commitment.mockResolvedValue({ ...commitment(), revisions: [] })
    api.cancelCommitment.mockRejectedValue(new ApiError(409, { message: 'x', code: 'already_cancelled' }, 'x'))
    const w = mount(CommitmentsPanel, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    await w.get('[data-test="commitment-open"]').trigger('click')
    await flushPromises()
    await w.get('[data-test="commitment-cancel"]').trigger('click')
    await w.findAll('button').filter((b) => b.text() === 'Cancelar compromiso').at(-1)!.trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Ese compromiso ya estaba cancelado')
  })
})

describe('Conectores y reversión de compradores', () => {
  it('el estado implemented_untested se muestra como «Implementado, sin probar», nunca como disponible', () => {
    const w = mount(StatusBadge, { props: { domain: 'connector', value: 'implemented_untested' } })
    expect(w.text()).toBe('Implementado, sin probar')
    expect(w.classes()).toContain('badge-warning')
    expect(w.classes()).not.toContain('badge-success')
    expect(mount(StatusBadge, { props: { domain: 'verification', value: 'tested_with_test_database' } }).text()).toBe('Probado con base de pruebas')
  })

  const entry = (id: number, status: string, slot: string | null) => ({ id, version: `v${id}`, model_family: 'logistic', strategy_level: 'tenant', status, role: 'primary', core: 'customer', hyperparameters: slot ? { slot } : null, demand_class: null, trained_at: null, training_data_from: null, training_data_to: null, series_count: 0 })

  it('solo las versiones validadas o retiradas con horizonte y objetivo se pueden volver a servir, con confirmación', async () => {
    api.customerModels.mockResolvedValue({ data: [entry(1, 'production', 'h30:probability'), entry(2, 'validated', 'h30:probability'), entry(3, 'retired', 'h7:quantity'), entry(4, 'failed', 'h30:probability'), entry(5, 'validated', null)], meta: {} })
    api.rollbackCustomer.mockResolvedValue({ restored: { id: 2 }, slot: 'h30:probability', horizon_days: 30, objective: 'probability', retired: [{ id: 1, version: 'v1', model_family: 'logistic' }], artifact_sha256_verified: null, ranking_source: 'popularity', note: 'La siguiente predicción usará esta versión.' })
    const w = mount(CustomerModels, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    const open = w.findAll('[data-test="customer-rollback-open"]')
    expect(open).toHaveLength(2)
    await open[0]!.trigger('click')
    expect(api.rollbackCustomer).not.toHaveBeenCalled()
    expect(w.get('[data-test="rollback-details"]').text()).toContain('30 días')
    await w.findAll('button').find((b) => b.text() === 'Sí, volver a esta versión')!.trigger('click')
    await flushPromises()
    expect(api.rollbackCustomer).toHaveBeenCalledWith(2)
    expect(w.get('[data-test="rollback-result"]').text()).toContain('La siguiente predicción usará esta versión.')
  })

  it('409 de reversión de compradores y plan (402) se explican', async () => {
    api.customerModels.mockResolvedValue({ data: [entry(2, 'validated', 'h90:probability')], meta: {} })
    api.rollbackCustomer.mockRejectedValueOnce(new ApiError(409, { message: 'x', code: 'rollback_target_incompatible' }, 'x'))
    const w = mount(CustomerModels, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    await w.get('[data-test="customer-rollback-open"]').trigger('click')
    await w.findAll('button').find((b) => b.text() === 'Sí, volver a esta versión')!.trigger('click')
    await flushPromises()
    expect(w.get('[data-test="rollback-error"]').text()).toContain('esquema de variables incompatible')
    api.rollbackCustomer.mockRejectedValueOnce(new ApiError(402, { message: 'x', code: 'plan_limit_exceeded' }, 'x'))
    await w.findAll('button').find((b) => b.text() === 'Sí, volver a esta versión')!.trigger('click')
    await flushPromises()
    expect(w.get('[data-test="rollback-error"]').text()).toContain('límite de su plan')
  })

  it('un usuario de consulta no ve el botón de reversión', async () => {
    api.customerModels.mockResolvedValue({ data: [entry(2, 'validated', 'h90:probability')], meta: {} })
    const w = mount(CustomerModels, { global: { plugins: [setupTenant('viewer')] } })
    await flushPromises()
    expect(w.find('[data-test="customer-rollback-open"]').exists()).toBe(false)
  })
})
