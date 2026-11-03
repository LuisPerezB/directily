import { describe, expect, it } from 'vitest'
import { countOf, dataOriginKey, isKnown, originIsNotReal, stalenessDays, tenantDataOrigin, textOrNull } from '../enterprise'

describe('honestidad: cifras desconocidas', () => {
  it('null, undefined y NaN no son cifra; el 0 sí lo es', () => {
    expect(isKnown(null)).toBe(false)
    expect(isKnown(undefined)).toBe(false)
    expect(isKnown(Number.NaN)).toBe(false)
    expect(isKnown('5')).toBe(false)
    expect(isKnown(0)).toBe(true)
    expect(isKnown(-3.5)).toBe(true)
  })

  it('antigüedad del corte en días; 0 si es de hoy; null sin corte', () => {
    expect(stalenessDays('2026-11-01', '2026-11-01')).toBe(0)
    expect(stalenessDays('2026-10-29', '2026-11-01')).toBe(3)
    expect(stalenessDays(null, '2026-11-01')).toBeNull()
    expect(stalenessDays('no-es-fecha', '2026-11-01')).toBeNull()
  })

  it('el origen de los datos no se supone real: sin declarar queda «undeclared»', () => {
    expect(dataOriginKey('real')).toBe('real')
    expect(dataOriginKey('demo')).toBe('demo')
    expect(dataOriginKey('simulated')).toBe('simulated')
    expect(dataOriginKey(null)).toBe('undeclared')
    expect(dataOriginKey('otro')).toBe('undeclared')
    expect(originIsNotReal('demo')).toBe(true)
    expect(originIsNotReal('simulated')).toBe(true)
    expect(originIsNotReal('real')).toBe(false)
    expect(originIsNotReal('undeclared')).toBe(false)
    expect(tenantDataOrigin({ data_origin: 'demo' })).toBe('demo')
    expect(tenantDataOrigin({})).toBe('undeclared')
    expect(tenantDataOrigin(null)).toBe('undeclared')
  })

  it('utilidades de texto y conteo', () => {
    expect(textOrNull('  ')).toBeNull()
    expect(textOrNull('x')).toBe('x')
    expect(countOf({ a: 3 }, 'a')).toBe(3)
    expect(countOf({ a: 3 }, 'b')).toBe(0)
    expect(countOf(null, 'a')).toBe(0)
  })
})
