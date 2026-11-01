import type { CustomerManifestHorizon, CustomerTier, DemandManifest } from '@/api/types'

/**
 * Manifiestos del protocolo de evaluación en un formato listo para mostrar (lenguaje claro).
 * Las fechas vienen del manifiesto construido por el motor a partir de las filas reales del backtest.
 */

export type ProtocolStepKey = 'history' | 'selection' | 'calibration' | 'holdout' | 'production'

export interface ProtocolStep {
  key: ProtocolStepKey
  from: string | null
  to: string | null
  /** Fecha adicional relevante (origen de la prueba, momento en que los residuos ya eran observables). */
  extraDate?: string | null
  /** La etapa no existe (p. ej. no hubo período final independiente). */
  missing?: boolean
  count?: number | null
}

export function demandProtocolSteps(m: DemandManifest | null | undefined): ProtocolStep[] {
  if (!m) return []
  const steps: ProtocolStep[] = [{ key: 'history', from: m.history?.from ?? null, to: m.history?.to ?? null, count: m.history?.days ?? null }]
  if (!m.protocol?.backtest) {
    steps.push({ key: 'production', from: m.training?.final_fit?.train_from ?? null, to: m.training?.final_fit?.train_through ?? null })
    return steps
  }
  steps.push({
    key: 'selection',
    from: m.selection?.target_from ?? null,
    to: m.selection?.target_to ?? null,
    count: m.selection?.origins?.length ?? null,
  })
  steps.push({
    key: 'calibration',
    from: m.calibration?.residual_target_from ?? null,
    to: m.calibration?.residual_target_to ?? null,
    extraDate: m.calibration?.observable_at ?? null,
    count: m.calibration?.n_residual_rows ?? null,
  })
  if (m.holdout?.present) {
    steps.push({ key: 'holdout', from: m.holdout.target_from ?? null, to: m.holdout.target_to ?? null, extraDate: m.holdout.origin ?? null, count: m.holdout.series ?? null })
  } else {
    steps.push({ key: 'holdout', from: null, to: null, missing: true })
  }
  steps.push({ key: 'production', from: m.training?.final_fit?.train_from ?? null, to: m.training?.final_fit?.train_through ?? null })
  return steps
}

/** Advertencias del manifiesto, como claves i18n (se muestran todas: ninguna se oculta). */
export function demandProtocolWarnings(m: DemandManifest | null | undefined): string[] {
  if (!m) return []
  const out: string[] = []
  if (!m.protocol?.backtest) out.push('manifest.warnings.noBacktest')
  else if (!m.holdout?.present) out.push('manifest.warnings.noHoldout')
  if (m.overlap?.overlapping_origins) out.push('manifest.warnings.overlap')
  const failed = Object.entries(m.checks ?? {}).filter(([, v]) => v === false)
  if (failed.length) out.push('manifest.warnings.checksFailed')
  if (m.calibration && !m.calibration.residuals_observable_when_calibrating) out.push('manifest.warnings.residualsNotObservable')
  return out
}

export function demandChecksFailed(m: DemandManifest | null | undefined): string[] {
  return Object.entries(m?.checks ?? {}).filter(([, v]) => v === false).map(([k]) => k)
}

// ─── Customer Core ─────────────────────────────────────────────────────────

export type CustomerBlockKey = 'train' | 'calibration' | 'selection' | 'holdout'
export const CUSTOMER_BLOCK_ORDER: CustomerBlockKey[] = ['train', 'calibration', 'selection', 'holdout']

export interface CustomerBlockRow {
  key: CustomerBlockKey
  cutoffFrom: string
  cutoffTo: string
  labelFrom: string
  labelTo: string
  nCutoffs: number
  rows: number | null
  positives: number | null
}

/** Bloques cronológicos del protocolo de un horizonte (el nivel `lean` no tiene bloque de selección). */
export function customerBlockRows(h: CustomerManifestHorizon | null | undefined): CustomerBlockRow[] {
  if (!h?.blocks) return []
  const out: CustomerBlockRow[] = []
  for (const key of CUSTOMER_BLOCK_ORDER) {
    const b = h.blocks[key]
    if (!b) continue
    out.push({
      key,
      cutoffFrom: b.cutoff_from,
      cutoffTo: b.cutoff_to,
      labelFrom: b.label_window_from,
      labelTo: b.label_window_to,
      nCutoffs: b.n_cutoffs,
      rows: h.block_rows?.[key] ?? null,
      positives: h.block_positives?.[key] ?? null,
    })
  }
  return out
}

/** Un nivel que no entrena (`abstained`) o que solo usa la referencia no se presenta como un modelo completo. */
export function tierIsFullModel(tier: CustomerTier | string | null | undefined): boolean {
  return tier === 'full' || tier === 'lean'
}

export function tierAbstains(tier: CustomerTier | string | null | undefined): boolean {
  return tier === 'abstained'
}
