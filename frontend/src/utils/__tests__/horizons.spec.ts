import { describe, expect, it } from 'vitest'
import { allowedHorizons, pickHorizon } from '../horizons'
import { CUSTOMER_HORIZONS, HORIZONS } from '../constants'

describe('horizontes según el plan', () => {
  it('el dominio de demanda tiene cinco horizontes y el de compradores tres', () => {
    expect(HORIZONS).toEqual([7, 14, 30, 60, 90])
    expect(CUSTOMER_HORIZONS).toEqual([7, 30, 90])
  })

  it('el plan básico (7/14/30) limita el selector', () => {
    expect(allowedHorizons(HORIZONS, { horizons: [7, 14, 30] })).toEqual([7, 14, 30])
  })

  it('compradores: plan ∩ {7, 30, 90}', () => {
    expect(allowedHorizons(CUSTOMER_HORIZONS, { horizons: [7, 14, 30] })).toEqual([7, 30])
    expect(allowedHorizons(CUSTOMER_HORIZONS, { horizons: [7, 14, 30, 60, 90] })).toEqual([7, 30, 90])
  })

  it('sin horizontes declarados por el plan no se restringe en el cliente (decide la API)', () => {
    expect(allowedHorizons(HORIZONS, undefined)).toEqual([...HORIZONS])
    expect(allowedHorizons(HORIZONS, null)).toEqual([...HORIZONS])
    expect(allowedHorizons(HORIZONS, {})).toEqual([...HORIZONS])
  })

  it('un plan sin ningún horizonte del dominio deja el selector vacío', () => {
    expect(allowedHorizons(CUSTOMER_HORIZONS, { horizons: [14, 60] })).toEqual([])
    expect(pickHorizon([], 30)).toBeNull()
  })

  it('elige el preferido, o el mayor permitido que no lo supere, o el menor', () => {
    expect(pickHorizon([7, 14, 30], 30)).toBe(30)
    expect(pickHorizon([7, 14, 30], 60)).toBe(30)
    expect(pickHorizon([14, 30], 7)).toBe(14)
    expect(pickHorizon([30, 7, 14], null)).toBe(7)
  })
})
