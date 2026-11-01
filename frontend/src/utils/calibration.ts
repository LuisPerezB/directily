import type { CalibrationBin } from '@/api/types'

/** Mínimo de observaciones por grupo para leer la tasa observada como evidencia (docs/architecture/v2/P2 §E2-10). */
export const MIN_BIN_N = 30

export interface CalibrationPoint {
  /** Probabilidad media predicha en el grupo. */
  predicted: number
  /** Frecuencia observada en el grupo. */
  observed: number
  lo: number | null
  hi: number | null
  n: number
  /** n ≥ MIN_BIN_N: la tasa observada es una evidencia razonable. */
  reliable: boolean
}

/** Ordena la curva por probabilidad predicha y marca los grupos con muestra pequeña. Ignora grupos sin dato. */
export function calibrationPoints(bins: readonly CalibrationBin[] | null | undefined, minN = MIN_BIN_N): CalibrationPoint[] {
  return (bins ?? [])
    .filter((b) => Number.isFinite(b.mean_predicted) && Number.isFinite(b.observed_rate) && b.n > 0)
    .map((b) => ({
      predicted: b.mean_predicted,
      observed: b.observed_rate,
      lo: b.observed_lo95 ?? null,
      hi: b.observed_hi95 ?? null,
      n: b.n,
      reliable: b.n >= minN,
    }))
    .sort((a, b) => a.predicted - b.predicted)
}

export interface CalibrationSummary {
  groups: number
  totalN: number
  smallGroups: number
  /** Brecha media ponderada |observada − predicha| (equivale al ECE calculado sobre los grupos mostrados). */
  weightedGap: number | null
  maxGap: number | null
  /** Todas las probabilidades predichas son casi iguales: el modelo no distingue compradores y la curva no es informativa. */
  flat: boolean
}

export function calibrationSummary(points: readonly CalibrationPoint[]): CalibrationSummary {
  const totalN = points.reduce((acc, p) => acc + p.n, 0)
  if (points.length === 0 || totalN === 0) {
    return { groups: 0, totalN: 0, smallGroups: 0, weightedGap: null, maxGap: null, flat: false }
  }
  const gaps = points.map((p) => Math.abs(p.observed - p.predicted))
  const weighted = points.reduce((acc, p, i) => acc + p.n * gaps[i]!, 0) / totalN
  const predicted = points.map((p) => p.predicted)
  const spread = Math.max(...predicted) - Math.min(...predicted)
  return {
    groups: points.length,
    totalN,
    smallGroups: points.filter((p) => !p.reliable).length,
    weightedGap: weighted,
    maxGap: Math.max(...gaps),
    flat: points.length > 1 && spread < 0.005,
  }
}

/** Diagonal de calibración perfecta acotada al rango dibujado. */
export function calibrationAxisMax(points: readonly CalibrationPoint[]): number {
  const top = points.reduce((acc, p) => Math.max(acc, p.predicted, p.observed, p.hi ?? 0), 0)
  if (top <= 0) return 1
  // Redondeo hacia arriba a un paso legible (1, 2, 5, 10 % …) sin pasar de 1.
  const steps = [0.01, 0.02, 0.05, 0.1, 0.2, 0.25, 0.5, 1]
  return steps.find((s) => s >= top) ?? 1
}
