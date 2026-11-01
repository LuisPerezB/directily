import { describe, expect, it } from 'vitest'
import { allowedHorizons, horizonSupportMap, pickHorizon } from '../horizons'
import { CUSTOMER_HORIZONS, HORIZONS } from '../constants'

describe('horizontes según el plan', () => {
  it('el catálogo de demanda tiene seis horizontes (incluido 15) y el de compradores cinco, sin 60', () => {
    expect(HORIZONS).toEqual([7, 14, 15, 30, 60, 90])
    expect(CUSTOMER_HORIZONS).toEqual([7, 14, 15, 30, 90])
    expect(CUSTOMER_HORIZONS.every((h) => (HORIZONS as number[]).includes(h))).toBe(true)
  })

  it('Growth y Enterprise (7/15/30) ven 15; Starter (7/30) y Básico (7/14/30) no', () => {
    expect(allowedHorizons(HORIZONS, { horizons: [7, 15, 30] })).toEqual([7, 15, 30])
    expect(allowedHorizons(HORIZONS, { horizons: [7, 30] })).toEqual([7, 30])
    expect(allowedHorizons(HORIZONS, { horizons: [7, 14, 30] })).not.toContain(15)
    expect(allowedHorizons(HORIZONS, { horizons: [7, 14, 15, 30, 60, 90] })).toEqual([7, 14, 15, 30, 60, 90])
    expect(allowedHorizons(CUSTOMER_HORIZONS, { horizons: [7, 15, 30] })).toEqual([7, 15, 30])
  })

  it('el plan básico (7/14/30) limita el selector', () => {
    expect(allowedHorizons(HORIZONS, { horizons: [7, 14, 30] })).toEqual([7, 14, 30])
  })

  it('compradores: plan ∩ {7, 14, 15, 30, 90} (60 nunca)', () => {
    expect(allowedHorizons(CUSTOMER_HORIZONS, { horizons: [7, 14, 30] })).toEqual([7, 14, 30])
    expect(allowedHorizons(CUSTOMER_HORIZONS, { horizons: [7, 14, 30, 60, 90] })).toEqual([7, 14, 30, 90])
  })

  it('sin horizontes declarados por el plan no se restringe en el cliente (decide la API)', () => {
    expect(allowedHorizons(HORIZONS, undefined)).toEqual([...HORIZONS])
    expect(allowedHorizons(HORIZONS, null)).toEqual([...HORIZONS])
    expect(allowedHorizons(HORIZONS, {})).toEqual([...HORIZONS])
  })

  it('un plan sin ningún horizonte del dominio deja el selector vacío', () => {
    expect(allowedHorizons(CUSTOMER_HORIZONS, { horizons: [60] })).toEqual([])
    expect(pickHorizon([], 30)).toBeNull()
  })

  it('elige el preferido, o el mayor permitido que no lo supere, o el menor', () => {
    expect(pickHorizon([7, 14, 30], 30)).toBe(30)
    expect(pickHorizon([7, 14, 30], 60)).toBe(30)
    expect(pickHorizon([14, 30], 7)).toBe(14)
    expect(pickHorizon([30, 7, 14], null)).toBe(7)
  })

  it('el respaldo por horizonte de una ejecución sale de su resumen; sin él no se inventa nada', () => {
    const map = horizonSupportMap({ horizon_support: { '7': { horizon: 7, status: 'ok', reasons: [] }, '15': { horizon: 15, status: 'insufficient_data', reasons: [] }, x: 'basura' } })
    expect(Object.keys(map).map(Number)).toEqual([7, 15])
    expect(map[15]!.status).toBe('insufficient_data')
    expect(horizonSupportMap(null)).toEqual({})
    expect(horizonSupportMap({})).toEqual({})
    expect(horizonSupportMap({ horizon_support: 'nada' })).toEqual({})
  })
})
