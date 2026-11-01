import { describe, expect, it } from 'vitest'
import { ApiError } from '@/api/errors'
import { applyDraftValue, configIssues, formatConfigValue, issuesByKey, parseConfigValue, profileOfSource, sourceKind, valuesEqual } from '../configEdit'

describe('errores de validación de la configuración', () => {
  it('normaliza la lista que devuelve el motor', () => {
    const e = new ApiError(422, { message: 'x', code: 'invalid_config', errors: [{ key: 'service_level', code: 'out_of_range', message: 'debe estar entre 0,5 y 0,999' }, { key: 'clave_rara', code: 'unknown_key', message: 'clave desconocida' }] } as never, 'x')
    expect(configIssues(e)).toEqual([
      { key: 'service_level', code: 'out_of_range', message: 'debe estar entre 0,5 y 0,999' },
      { key: 'clave_rara', code: 'unknown_key', message: 'clave desconocida' },
    ])
  })

  it('normaliza el mapa de Laravel y quita el prefijo overrides.', () => {
    const e = new ApiError(422, { message: 'x', errors: { 'overrides.min_improvement': ['Debe ser numérico.'], _: ['Otro'] } }, 'x')
    expect(configIssues(e)).toEqual([{ key: 'min_improvement', message: 'Debe ser numérico.' }, { key: '_', message: 'Otro' }])
  })

  it('agrupa los mensajes por clave y devuelve vacío para errores ajenos', () => {
    expect(issuesByKey([{ key: 'a', message: 'uno' }, { key: 'a', message: 'dos' }, { key: 'b', message: 'tres' }])).toEqual({ a: ['uno', 'dos'], b: ['tres'] })
    expect(configIssues(new Error('x'))).toEqual([])
    expect(configIssues(new ApiError(503, { message: 'x', code: 'engine_unavailable' }, 'x'))).toEqual([])
  })
})

describe('edición de valores', () => {
  it('convierte el texto al tipo del valor original', () => {
    expect(parseConfigValue('0,95', 0.5)).toEqual({ ok: true, value: 0.95 })
    expect(parseConfigValue('7', 3)).toEqual({ ok: true, value: 7 })
    expect(parseConfigValue('true', false)).toEqual({ ok: true, value: true })
    expect(parseConfigValue('[7, 30]', [7, 14, 30])).toEqual({ ok: true, value: [7, 30] })
    expect(parseConfigValue('{"train": 0.6}', { train: 0.5 })).toEqual({ ok: true, value: { train: 0.6 } })
    expect(parseConfigValue('min_cost', 'max_margin')).toEqual({ ok: true, value: 'min_cost' })
  })

  it('rechaza lo que no coincide con el tipo en lugar de guardarlo', () => {
    expect(parseConfigValue('abc', 1)).toMatchObject({ ok: false })
    expect(parseConfigValue('', 1)).toMatchObject({ ok: false })
    expect(parseConfigValue('quizás', true)).toMatchObject({ ok: false })
    expect(parseConfigValue('{no json', [1])).toMatchObject({ ok: false })
    expect(parseConfigValue('3', [1])).toMatchObject({ ok: false })
    expect(parseConfigValue('[1]', { a: 1 })).toMatchObject({ ok: false })
  })

  it('un valor nulo acepta vacío (sigue nulo), JSON o texto', () => {
    expect(parseConfigValue('', null)).toEqual({ ok: true, value: null })
    expect(parseConfigValue('12', null)).toEqual({ ok: true, value: 12 })
    expect(parseConfigValue('hola', null)).toEqual({ ok: true, value: 'hola' })
  })

  it('un valor igual al heredado no es una sobrescritura de empresa', () => {
    let draft = applyDraftValue({}, 'min_improvement', 0.05, 0.03)
    expect(draft).toEqual({ min_improvement: 0.05 })
    draft = applyDraftValue(draft, 'min_improvement', 0.03, 0.03)
    expect(draft).toEqual({})
    expect(applyDraftValue({ a: 1 }, 'horizons', [7, 30], [7, 14, 30])).toEqual({ a: 1, horizons: [7, 30] })
  })

  it('formatea valores y compara por contenido', () => {
    expect(formatConfigValue(null)).toBe('—')
    expect(formatConfigValue([7, 30])).toBe('[7,30]')
    expect(formatConfigValue(true)).toBe('true')
    expect(valuesEqual({ a: [1] }, { a: [1] })).toBe(true)
    expect(valuesEqual({ a: 1 }, { a: 2 })).toBe(false)
  })
})

describe('procedencia de cada valor', () => {
  it('distingue core, perfil sectorial y empresa', () => {
    expect(sourceKind('core')).toBe('core')
    expect(sourceKind('profile:synthetic_retail_a@1.0')).toBe('profile')
    expect(sourceKind('tenant')).toBe('tenant')
    expect(sourceKind(undefined)).toBe('core')
    expect(profileOfSource('profile:synthetic_retail_a@1.0')).toBe('synthetic_retail_a@1.0')
    expect(profileOfSource('tenant')).toBeNull()
  })
})
