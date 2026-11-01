import { describe, expect, it } from 'vitest'
import { ApiError } from '@/api/errors'
import { buildFieldMap, buildSqlPayload, defaultPort, emptySqlForm, fieldMapToRows, isSqlConnector, missingRequiredFields, parseOptionsJson, sqlFieldErrors, sqlRejection } from '../sqlSource'

describe('fuente SQL', () => {
  it('reconoce los conectores SQL y sus puertos', () => {
    expect(isSqlConnector('sql_postgresql')).toBe(true)
    expect(isSqlConnector('generic_rest')).toBe(false)
    expect(isSqlConnector(null)).toBe(false)
    expect([defaultPort('sql_postgresql'), defaultPort('sql_mysql'), defaultPort('sql_sqlserver')]).toEqual([5432, 3306, 1433])
  })

  it('al crear, las credenciales van aparte de la configuración no secreta', () => {
    const f = { ...emptySqlForm('sql_mysql'), name: 'ERP', host: ' db.local ', database: 'erp', username: 'lector', password: 'secreta' }
    const p = buildSqlPayload(f, 'create')
    expect(p).toMatchObject({ type: 'connector', connector_key: 'sql_mysql', name: 'ERP', credentials: { username: 'lector', password: 'secreta' } })
    expect(p.config).toMatchObject({ host: 'db.local', port: 3306, database: 'erp' })
    expect(JSON.stringify(p.config)).not.toContain('secreta')
  })

  it('al editar sin credenciales nuevas no se envían (se conservan las guardadas)', () => {
    const p = buildSqlPayload({ ...emptySqlForm(), name: 'ERP', host: 'h', database: 'd' }, 'update')
    expect(p).not.toHaveProperty('credentials')
    expect(p).not.toHaveProperty('type')
  })

  it('mapea los errores config.* y credentials.* a su campo', () => {
    const e = new ApiError(422, { message: 'x', errors: { 'config.host': ['Indique un host válido.'], 'credentials.username': ['Es obligatorio.'] } }, 'x')
    expect(sqlFieldErrors(e)).toEqual({ host: 'Indique un host válido.', username: 'Es obligatorio.' })
    expect(sqlFieldErrors(new Error('x'))).toEqual({})
  })
})

describe('equivalencia de columnas', () => {
  it('construye el field_map con columnas, valores fijos y omite las filas vacías', () => {
    expect(buildFieldMap({ issued_at: { column: ' fecha ', fixed: false }, branch_code: { column: '001', fixed: true }, notes: { column: '  ', fixed: false } }))
      .toEqual({ issued_at: 'fecha', branch_code: { const: '001' } })
  })

  it('ida y vuelta entre filas del editor y field_map', () => {
    const map = { quantity: 'cant', branch_code: { const: '001' } }
    expect(buildFieldMap(fieldMapToRows(map))).toEqual(map)
  })

  it('detecta campos obligatorios sin columna', () => {
    const fields = [{ key: 'issued_at', required: true }, { key: 'product_sku', required: true }, { key: 'notes', required: false }]
    expect(missingRequiredFields(fields, { issued_at: { column: 'f', fixed: false }, product_sku: { column: ' ', fixed: false } })).toEqual(['product_sku'])
  })
})

describe('consulta rechazada y opciones', () => {
  it('422 sql_query_rejected expone el motivo', () => {
    const e = new ApiError(422, { message: 'rechazada', code: 'sql_query_rejected', reason: 'Solo se permite una sentencia SELECT.' } as never, 'x')
    expect(sqlRejection(e)).toEqual({ reason: 'Solo se permite una sentencia SELECT.' })
    expect(sqlRejection(new ApiError(422, { message: 'x', code: 'otro' }, 'x'))).toBeNull()
    expect(sqlRejection(new ApiError(500, { message: 'x', code: 'sql_query_rejected' }, 'x'))).toBeNull()
  })

  it('las opciones avanzadas deben ser un objeto JSON', () => {
    expect(parseOptionsJson('')).toEqual({ ok: true, value: {} })
    expect(parseOptionsJson('{"page_size": 5000}')).toEqual({ ok: true, value: { page_size: 5000 } })
    expect(parseOptionsJson('[1]')).toEqual({ ok: false })
    expect(parseOptionsJson('{mal')).toEqual({ ok: false })
  })
})
