import type { AccuracyByHorizon, HorizonSupport } from '@/api/types'

/** Tramo de evaluación diaria `h8-14` → { from: 8, to: 14 }. Las ejecuciones antiguas usan tramos distintos (h8-30, h31-90). */
export function horizonBand(levelKey: string | null | undefined): { from: number; to: number } | null {
  const m = /^h(\d+)-(\d+)$/.exec(levelKey ?? '')
  return m ? { from: Number(m[1]), to: Number(m[2]) } : null
}

/** Etiqueta de un tramo: "Días 8–14" (i18n lo aporta el llamador con la clave y los valores). */
export function bandLabelParams(row: Pick<AccuracyByHorizon, 'level_key' | 'horizon_days'>): { key: 'accuracy.bandLabel'; from: number; to: number } | { key: 'accuracy.horizonLabel'; n: number } {
  const band = horizonBand(row.level_key)
  return band ? { key: 'accuracy.bandLabel', ...band } : { key: 'accuracy.horizonLabel', n: row.horizon_days }
}

/** Horizontes con respaldo, ordenados; los que no tienen respaldo suficiente quedan separados con sus motivos. */
export function splitHorizonSupport(support: Record<string, HorizonSupport> | null | undefined): { supported: HorizonSupport[]; unsupported: HorizonSupport[] } {
  const all = Object.values(support ?? {}).sort((a, b) => a.horizon - b.horizon)
  return {
    supported: all.filter((h) => h.status === 'ok' || h.status === 'degraded'),
    unsupported: all.filter((h) => h.status !== 'ok' && h.status !== 'degraded'),
  }
}

export interface AbstentionSummary {
  total: number
  noRecentSales: number
  discontinued: number
  branchClosed: number
  afterDays: number | null
  /** Series con una cifra marcada (producto nuevo, historia escasa, descontinuado en cero...): no son abstenciones. */
  labelled: Record<string, number>
}

/**
 * Series SIN pronóstico (abstención) de una ejecución: no tienen filas, así que la interfaz las cuenta aparte
 * (`summary.special_cases`). Ausente o ejecución antigua → null (no se inventan conteos).
 */
export function abstentionSummary(summary: Record<string, unknown> | null | undefined): AbstentionSummary | null {
  const sc = summary?.special_cases as Record<string, Record<string, unknown>> | undefined
  if (!sc || typeof sc !== 'object') return null
  const n = (v: unknown): number => (typeof v === 'number' && Number.isFinite(v) ? v : 0)
  const noRecentSales = n(sc.no_recent_sales?.abstained)
  const discontinued = n(sc.discontinued?.abstained)
  const branchClosed = n(sc.branch_closed_declared?.abstained)
  const labelCounts = (sc.label_counts ?? {}) as Record<string, unknown>
  const labelled = Object.fromEntries(Object.entries(labelCounts).filter(([, v]) => n(v) > 0).map(([k, v]) => [k, n(v)]))
  return { total: noRecentSales + discontinued + branchClosed, noRecentSales, discontinued, branchClosed, afterDays: typeof sc.no_recent_sales?.after_days === 'number' ? (sc.no_recent_sales.after_days as number) : null, labelled }
}
