import { describe, expect, it } from 'vitest'
import { calibrationAxisMax, calibrationPoints, calibrationSummary, MIN_BIN_N } from '../calibration'
import type { CalibrationBin } from '@/api/types'

const bin = (mean_predicted: number, observed_rate: number, n: number, lo?: number, hi?: number): CalibrationBin => ({
  mean_predicted, observed_rate, n, observed_lo95: lo, observed_hi95: hi,
})

describe('curva de calibración', () => {
  it('ordena por probabilidad predicha, conserva el n de cada grupo y marca los de muestra pequeña', () => {
    const pts = calibrationPoints([bin(0.3, 0.28, 400, 0.24, 0.33), bin(0.05, 0.06, 1200), bin(0.6, 0.5, 12)])
    expect(pts.map((p) => p.predicted)).toEqual([0.05, 0.3, 0.6])
    expect(pts.map((p) => p.n)).toEqual([1200, 400, 12])
    expect(pts.map((p) => p.reliable)).toEqual([true, true, false])
    expect(pts[1]).toMatchObject({ lo: 0.24, hi: 0.33 })
    expect(pts[0]).toMatchObject({ lo: null, hi: null })
  })

  it('el límite de muestra pequeña es 30 observaciones', () => {
    expect(MIN_BIN_N).toBe(30)
    expect(calibrationPoints([bin(0.1, 0.1, 29)])[0]!.reliable).toBe(false)
    expect(calibrationPoints([bin(0.1, 0.1, 30)])[0]!.reliable).toBe(true)
  })

  it('descarta grupos sin dato o sin observaciones', () => {
    expect(calibrationPoints([bin(NaN, 0.1, 50), bin(0.1, 0.1, 0), bin(0.2, 0.2, 40)]).length).toBe(1)
    expect(calibrationPoints(null)).toEqual([])
    expect(calibrationPoints(undefined)).toEqual([])
  })

  it('resume la brecha media ponderada por n y cuenta los grupos pequeños', () => {
    // |0,10−0,06|·100 + |0,40−0,50|·300 = 4 + 30 = 34 → 34/400 = 0,085
    const s = calibrationSummary(calibrationPoints([bin(0.1, 0.06, 100), bin(0.4, 0.5, 300), bin(0.7, 0.9, 5)]))
    expect(s.groups).toBe(3)
    expect(s.totalN).toBe(405)
    expect(s.smallGroups).toBe(1)
    expect(s.maxGap).toBeCloseTo(0.2, 6)
    expect(s.weightedGap).toBeCloseTo((0.04 * 100 + 0.1 * 300 + 0.2 * 5) / 405, 6)
    expect(s.flat).toBe(false)
  })

  it('una curva con la misma probabilidad en todos los grupos es plana (el modelo no distingue)', () => {
    // Forma real de la demostración del Customer Core (referencia a 7 días): todos los grupos predicen 1,32 %.
    const flat = [0.015417, 0.018228, 0.014, 0.0125].map((o) => bin(0.013216497105702886, o, 11740))
    const s = calibrationSummary(calibrationPoints(flat))
    expect(s.flat).toBe(true)
    expect(s.totalN).toBe(11740 * 4)
  })

  it('sin grupos el resumen es vacío y no inventa una brecha', () => {
    const s = calibrationSummary([])
    expect(s).toMatchObject({ groups: 0, totalN: 0, weightedGap: null, maxGap: null, flat: false })
  })

  it('el eje se ajusta al máximo dibujado, sin pasar de 100 %', () => {
    expect(calibrationAxisMax(calibrationPoints([bin(0.013, 0.018, 100)]))).toBe(0.02)
    expect(calibrationAxisMax(calibrationPoints([bin(0.3, 0.45, 100)]))).toBe(0.5)
    expect(calibrationAxisMax([])).toBe(1)
  })
})
