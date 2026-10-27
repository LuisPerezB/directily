import { describe, expect, it } from 'vitest'
import { FALLBACK_FIELDS, canConfirmImport, checkUploadFile, compactMapping, initialMapping, stepForStatus, validateMapping } from '../importMapping'

const fields = FALLBACK_FIELDS.sales

describe('mapeo de importación', () => {
  it('usa el mapeo sugerido limitado a columnas detectadas', () => {
    const m = initialMapping(fields, {
      detected_columns: ['Fecha', 'Factura', 'Codigo', 'Cant', 'PVP'],
      suggested_mapping: { issued_at: 'Fecha', document_number: 'Factura', product_sku: 'Codigo', quantity: 'Cant', unit_price: 'PVP', discount: 'NoExiste' },
      column_mapping: null,
    })
    expect(m.issued_at).toBe('Fecha')
    expect(m.discount).toBeNull()
    expect(Object.keys(m)).toHaveLength(fields.length)
  })

  it('ignora un mapeo guardado vacío ({}) y usa la sugerencia', () => {
    const m = initialMapping(fields, { detected_columns: ['Cant'], suggested_mapping: { quantity: 'Cant' }, column_mapping: {} })
    expect(m.quantity).toBe('Cant')
  })

  it('prefiere el mapeo guardado por el usuario', () => {
    const m = initialMapping(fields, { detected_columns: ['A', 'B'], suggested_mapping: { quantity: 'A' }, column_mapping: { quantity: 'B' } })
    expect(m.quantity).toBe('B')
  })

  it('detecta campos obligatorios faltantes y columnas repetidas', () => {
    const v = validateMapping(fields, { document_number: 'X', issued_at: 'X', product_sku: 'Y', quantity: 'Z', unit_price: null })
    expect(v.valid).toBe(false)
    expect(v.missingRequired).toEqual(['unit_price'])
    expect(v.duplicateColumns).toEqual(['X'])
  })

  it('valida un mapeo completo', () => {
    const v = validateMapping(fields, { document_number: 'a', issued_at: 'b', product_sku: 'c', quantity: 'd', unit_price: 'e' })
    expect(v.valid).toBe(true)
  })

  it('compacta el mapeo antes de enviarlo', () => {
    expect(compactMapping({ a: 'x', b: null, c: '' })).toEqual({ a: 'x' })
  })

  it('traduce estados a pasos del asistente', () => {
    expect(stepForStatus('awaiting_mapping')).toBe('mapping')
    expect(stepForStatus('validating')).toBe('validating')
    expect(stepForStatus('validated')).toBe('review')
    expect(stepForStatus('failed')).toBe('review')
    expect(stepForStatus('importing')).toBe('importing')
    expect(stepForStatus('completed')).toBe('done')
    expect(stepForStatus(null)).toBe('upload')
  })

  it('solo permite confirmar sin errores o con omisión explícita', () => {
    const base = { status: 'validated' as const, stats: { rows_valid: 10, rows_error: 2 }, options: { skip_invalid_rows: false } }
    expect(canConfirmImport(base)).toEqual({ allowed: false, reason: 'has_errors' })
    expect(canConfirmImport({ ...base, options: { skip_invalid_rows: true } }).allowed).toBe(true)
    expect(canConfirmImport({ ...base, stats: { rows_valid: 10, rows_error: 0 } }).allowed).toBe(true)
    expect(canConfirmImport({ ...base, stats: { rows_valid: 0, rows_error: 0 } }).reason).toBe('no_valid_rows')
    expect(canConfirmImport({ ...base, status: 'validating' as never }).reason).toBe('not_validated')
  })

  it('valida tipo y tamaño de archivo', () => {
    expect(checkUploadFile({ name: 'ventas.CSV', size: 100 })).toBe('ok')
    expect(checkUploadFile({ name: 'ventas.xlsx', size: 100 })).toBe('ok')
    expect(checkUploadFile({ name: 'ventas.pdf', size: 100 })).toBe('bad_type')
    expect(checkUploadFile({ name: 'ventas.csv', size: 0 })).toBe('empty')
    expect(checkUploadFile({ name: 'ventas.csv', size: 25 * 1024 * 1024 })).toBe('too_large')
  })
})
