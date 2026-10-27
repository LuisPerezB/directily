import { describe, expect, it } from 'vitest'
import { cleanParams } from '../index'

describe('cleanParams', () => {
  it('elimina valores vacíos y conserva ceros y falsos', () => {
    expect(cleanParams({ a: 1, b: null, c: undefined, d: '', e: 0, f: false, g: 'x' })).toEqual({ a: 1, e: 0, f: false, g: 'x' })
  })
  it('acepta undefined', () => {
    expect(cleanParams(undefined)).toBeUndefined()
  })
})
