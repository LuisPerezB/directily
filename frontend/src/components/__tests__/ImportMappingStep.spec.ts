import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ImportMappingStep from '../imports/ImportMappingStep.vue'
import { FALLBACK_FIELDS } from '@/utils/importMapping'
import type { ImportJob } from '@/api/types'

function job(partial: Partial<ImportJob> = {}): ImportJob {
  return {
    id: 7,
    entity_type: 'sales',
    original_filename: 'ventas.csv',
    file_format: 'csv',
    file_size: 1000,
    status: 'awaiting_mapping',
    progress: 0,
    detected_columns: ['Fecha', 'Factura', 'Codigo', 'Cantidad', 'Precio', 'Sucursal'],
    suggested_mapping: { issued_at: 'Fecha', document_number: 'Factura', product_sku: 'Codigo', quantity: 'Cantidad', unit_price: 'Precio', branch_code: 'Sucursal' },
    column_mapping: null,
    options: null,
    preview: { headers: ['Fecha', 'Factura'], rows: [['2026-10-01', '001-001-1']] },
    normalized_preview: null,
    stats: null,
    quality_report: null,
    error_message: null,
    created_at: '2026-10-27T10:00:00Z',
    validated_at: null,
    finished_at: null,
    user: null,
    ...partial,
  }
}

const branches = [{ id: 1, code: '001', name: 'Matriz', province: 'Pichincha', canton: 'Quito', commercial_zone: null, address: null, is_active: true }]

describe('ImportMappingStep', () => {
  it('precarga el mapeo sugerido y emite solo campos mapeados', async () => {
    const wrapper = mount(ImportMappingStep, { props: { job: job(), fields: FALLBACK_FIELDS.sales, branches, saving: false, error: null } })
    expect((wrapper.get('#map-issued_at').element as HTMLSelectElement).value).toBe('Fecha')
    expect(wrapper.text()).toContain('Sugerido automáticamente')
    const submit = wrapper.get('button[type="submit"]')
    expect(submit.attributes('disabled')).toBeUndefined()
    await wrapper.get('form').trigger('submit')
    const [mapping, options] = wrapper.emitted('submit')![0] as [Record<string, string>, Record<string, unknown>]
    expect(mapping).toEqual({ issued_at: 'Fecha', document_number: 'Factura', product_sku: 'Codigo', quantity: 'Cantidad', unit_price: 'Precio', branch_code: 'Sucursal' })
    expect(options.default_branch_code).toBe('001')
    expect(options.skip_invalid_rows).toBe(false)
  })

  it('bloquea la validación si falta un campo obligatorio', async () => {
    const wrapper = mount(ImportMappingStep, {
      props: { job: job({ suggested_mapping: { issued_at: 'Fecha' } }), fields: FALLBACK_FIELDS.sales, branches, saving: false, error: null },
    })
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Faltan campos obligatorios')
    await wrapper.get('form').trigger('submit')
    expect(wrapper.emitted('submit')).toBeUndefined()
  })

  it('muestra la vista previa del archivo', () => {
    const wrapper = mount(ImportMappingStep, { props: { job: job(), fields: FALLBACK_FIELDS.sales, branches, saving: false, error: null } })
    expect(wrapper.text()).toContain('001-001-1')
  })
})
