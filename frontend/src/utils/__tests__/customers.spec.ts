import { describe, expect, it } from 'vitest'
import { ApiError } from '@/api/errors'
import { formatExpectedAmount, horizonNotAvailable, predictionAbstains, rankingAtK, reconciliationParts, sortReconciliation, evaluationKey } from '../customers'
import { formatProbability } from '../format'
import type { CustomerReconciliationRow } from '@/api/types'

const row = (over: Partial<CustomerReconciliationRow> = {}): CustomerReconciliationRow => ({
  product: { id: 1, sku: 'CEM-50', name: 'Cemento', unit: 'saco' }, horizon_days: 30, status: 'ok', severity: null,
  aggregate_quantity: 100, aggregate_days: 30, buyers_total_quantity: 60, buyers_committed_quantity: 10, buyers_incremental_quantity: 50,
  buyers_expected_quantity: 55, n_buyers: 12, committed_outside_model: 10, residual_quantity: 30, residual_share: 0.3, excess_quantity: null, excess_ratio: null, ...over,
})

describe('predicciones de compra', () => {
  it('el monto es una estimación: sin valor no se inventa un número', () => {
    expect(formatExpectedAmount({ value: null, currency: 'USD' })).toBe('—')
    expect(formatExpectedAmount(null)).toBe('—')
    expect(formatExpectedAmount({ value: 12.5, currency: 'USD' })).toMatch(/12,50/)
  })

  it('una predicción sin probabilidad es una abstención', () => {
    expect(predictionAbstains({ purchase_probability: null })).toBe(true)
    expect(predictionAbstains({ purchase_probability: 0.0001 })).toBe(false)
  })

  it('las probabilidades nunca se muestran como 0 % ni 100 % exactos', () => {
    // Intl puede separar el signo % con espacio normal, fino o sin espacio según el entorno.
    expect(formatProbability(0.0001)).toMatch(/^<\s?1\s?%$/)
    expect(formatProbability(0.9999)).toMatch(/^>\s?99\s?%$/)
    expect(formatProbability(1)).toMatch(/^>\s?99\s?%$/)
    expect(formatProbability(0.25)).toMatch(/^25\s?%$/)
    expect(formatProbability(null)).toBe('—')
  })

  it('422 horizon_not_available informa los horizontes que sí tienen predicción', () => {
    const e = new ApiError(422, { message: 'sin predicciones', code: 'horizon_not_available', available_horizons: [7, 30] } as never, 'x')
    expect(horizonNotAvailable(e)).toEqual({ availableHorizons: [7, 30] })
  })

  it('otros errores no se tratan como horizonte no disponible', () => {
    expect(horizonNotAvailable(new ApiError(422, { message: 'x', code: 'otro' }, 'x'))).toBeNull()
    expect(horizonNotAvailable(new ApiError(402, { message: 'plan', code: 'plan_limit_exceeded' }, 'x'))).toBeNull()
    expect(horizonNotAvailable(new Error('x'))).toBeNull()
    expect(horizonNotAvailable(new ApiError(422, { message: 'x', code: 'horizon_not_available' }, 'x'))).toEqual({ availableHorizons: [] })
  })

  it('claves de métricas por horizonte y valores de precisión/cobertura en k', () => {
    expect(evaluationKey(30, 'probability')).toBe('h30:probability')
    expect(rankingAtK({ precision_at_5: 0.094, recall_at_5: 0.35 }, 'precision', 5)).toBe(0.094)
    expect(rankingAtK({ precision_at_5: 0.094 }, 'recall', 5)).toBeNull()
    expect(rankingAtK(undefined, 'precision', 1)).toBeNull()
  })
})

describe('conciliación', () => {
  it('las inconsistencias van primero, las graves antes que las leves, y el resto conserva su orden', () => {
    const rows = [row({ product: { id: 1, sku: 'A', name: 'A', unit: 'u' } }), row({ product: { id: 2, sku: 'B', name: 'B', unit: 'u' }, status: 'inconsistent', severity: 'minor' }),
      row({ product: { id: 3, sku: 'C', name: 'C', unit: 'u' }, status: 'aggregate_unavailable' }), row({ product: { id: 4, sku: 'D', name: 'D', unit: 'u' }, status: 'inconsistent', severity: 'major' })]
    expect(sortReconciliation(rows).map((r) => r.product.id)).toEqual([4, 2, 1, 3])
  })

  it('el desglose cuadra con el agregado en una fila conciliada', () => {
    const p = reconciliationParts(row())
    expect(p.decomposable).toBe(true)
    expect(p.sumGap).toBeCloseTo(0, 9)
  })

  it('una fila sin agregado no se descompone ni muestra residual', () => {
    const p = reconciliationParts(row({ status: 'aggregate_unavailable', aggregate_quantity: null, residual_quantity: null }))
    expect(p.decomposable).toBe(false)
    expect(p.sumGap).toBeNull()
  })

  it('una inconsistencia es descomponible pero no se verifica la suma (el residual es 0 y hay exceso)', () => {
    const p = reconciliationParts(row({ status: 'inconsistent', severity: 'major', buyers_total_quantity: 140, residual_quantity: 0, excess_quantity: 50, excess_ratio: 0.5 }))
    expect(p.decomposable).toBe(true)
    expect(p.sumGap).toBeNull()
  })
})
