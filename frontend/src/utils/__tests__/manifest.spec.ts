import { describe, expect, it } from 'vitest'
import { customerBlockRows, demandChecksFailed, demandProtocolSteps, demandProtocolWarnings, tierAbstains, tierIsFullModel } from '../manifest'
import type { CustomerManifestHorizon, DemandManifest } from '@/api/types'

const manifest = (over: Partial<DemandManifest> = {}): DemandManifest => ({
  manifest_version: 'demand-protocol-v1', core: 'demand', as_of: '2026-10-26',
  history: { from: '2024-10-27', to: '2026-10-26', days: 730 },
  protocol: { backtest: true, horizon_days: 90, step_days: 90, selection_windows: 2, independent_holdout: true },
  training: { final_fit: { train_from: '2024-10-27', train_through: '2026-10-26' } },
  selection: { origins: ['2025-10-01', '2026-01-01'], target_from: '2025-10-02', target_to: '2026-04-01', unique_target_dates: 182, shared_target_dates_between_windows: 0 },
  calibration: { residual_target_from: '2025-10-02', residual_target_to: '2026-04-01', observable_at: '2026-07-28', n_residual_rows: 15000, residuals_observable_when_calibrating: true },
  holdout: { present: true, independent: true, origin: '2026-07-28', target_from: '2026-07-29', target_to: '2026-10-26', horizon_days: 90, series: 183, used_for_model_selection: false, used_for_hyperparameters: false, used_for_thresholds: false, used_for_interval_calibration: false },
  label_maturity: null,
  overlap: { overlapping_origins: false, shared_target_dates_between_selection_windows: 0, shared_target_dates_with_holdout: 0, note: 'Sin solape.' },
  checks: { holdout_disjoint_from_selection: true, calibration_residuals_observable_at_holdout_origin: true, holdout_not_used_for_decisions: true, selection_windows_end_before_holdout_origin: true },
  ...over,
})

describe('manifiesto del protocolo de demanda', () => {
  it('ordena las etapas con sus fechas: historia, elección, calibración, prueba final y producción', () => {
    const steps = demandProtocolSteps(manifest())
    expect(steps.map((s) => s.key)).toEqual(['history', 'selection', 'calibration', 'holdout', 'production'])
    expect(steps.find((s) => s.key === 'selection')).toMatchObject({ from: '2025-10-02', to: '2026-04-01', count: 2 })
    expect(steps.find((s) => s.key === 'calibration')).toMatchObject({ extraDate: '2026-07-28' })
    expect(steps.find((s) => s.key === 'holdout')).toMatchObject({ from: '2026-07-29', to: '2026-10-26', extraDate: '2026-07-28' })
    expect(steps.find((s) => s.key === 'holdout')!.missing).toBeFalsy()
    expect(steps.find((s) => s.key === 'production')).toMatchObject({ from: '2024-10-27', to: '2026-10-26' })
  })

  it('un protocolo limpio no genera advertencias', () => {
    expect(demandProtocolWarnings(manifest())).toEqual([])
    expect(demandChecksFailed(manifest())).toEqual([])
  })

  it('sin período final independiente la etapa queda marcada como ausente y se advierte que las métricas son optimistas', () => {
    const m = manifest({ holdout: { present: false, independent: false, reason: 'La historia no alcanza.' } })
    expect(demandProtocolSteps(m).find((s) => s.key === 'holdout')).toMatchObject({ missing: true, from: null, to: null })
    expect(demandProtocolWarnings(m)).toContain('manifest.warnings.noHoldout')
  })

  it('ventanas solapadas, verificaciones fallidas y residuos no observables generan advertencias', () => {
    const m = manifest({
      overlap: { overlapping_origins: true, shared_target_dates_between_selection_windows: 12, shared_target_dates_with_holdout: 0, note: 'Solape.' },
      checks: { holdout_disjoint_from_selection: false, holdout_not_used_for_decisions: true },
      calibration: { residual_target_from: '2025-10-02', residual_target_to: '2026-08-01', observable_at: '2026-07-28', n_residual_rows: 10, residuals_observable_when_calibrating: false },
    })
    const w = demandProtocolWarnings(m)
    expect(w).toEqual(expect.arrayContaining(['manifest.warnings.overlap', 'manifest.warnings.checksFailed', 'manifest.warnings.residualsNotObservable']))
    expect(demandChecksFailed(m)).toEqual(['holdout_disjoint_from_selection'])
  })

  it('sin backtesting solo hay historia y modelo en producción, con aviso', () => {
    const m = manifest({ protocol: { backtest: false, reason: 'Historia insuficiente' }, selection: null, calibration: null, holdout: { present: false, independent: false } })
    expect(demandProtocolSteps(m).map((s) => s.key)).toEqual(['history', 'production'])
    expect(demandProtocolWarnings(m)).toContain('manifest.warnings.noBacktest')
  })

  it('sin manifiesto (ejecución antigua) no hay etapas ni advertencias inventadas', () => {
    expect(demandProtocolSteps(null)).toEqual([])
    expect(demandProtocolWarnings(undefined)).toEqual([])
  })
})

describe('manifiesto del Customer Core', () => {
  // Fechas y conteos tomados de la corrida de demostración (datos ficticios) a 30 días.
  const h30: CustomerManifestHorizon = {
    tier: 'full', status: 'trained', reason: null, horizon_days: 30,
    blocks: {
      train: { cutoff_from: '2025-01-27', cutoff_to: '2025-11-03', label_window_from: '2025-01-28', label_window_to: '2025-12-03', n_cutoffs: 21 },
      calibration: { cutoff_from: '2025-12-15', cutoff_to: '2026-02-09', label_window_from: '2025-12-16', label_window_to: '2026-03-11', n_cutoffs: 5 },
      selection: { cutoff_from: '2026-03-23', cutoff_to: '2026-05-18', label_window_from: '2026-03-24', label_window_to: '2026-06-17', n_cutoffs: 5 },
      holdout: { cutoff_from: '2026-06-29', cutoff_to: '2026-09-21', label_window_from: '2026-06-30', label_window_to: '2026-10-21', n_cutoffs: 7 },
      gap_days_between_blocks: 30,
    },
    block_rows: { train: 202778, holdout: 100118, selection: 71779, calibration: 72899 },
    block_positives: { train: 11830, holdout: 6215, selection: 4237, calibration: 4142 },
  }

  it('los bloques salen en orden cronológico con filas y positivos', () => {
    const rows = customerBlockRows(h30)
    expect(rows.map((r) => r.key)).toEqual(['train', 'calibration', 'selection', 'holdout'])
    expect(rows[3]).toMatchObject({ cutoffFrom: '2026-06-29', labelTo: '2026-10-21', nCutoffs: 7, rows: 100118, positives: 6215 })
    // Cada bloque empieza después de que termina la ventana de resultado del anterior.
    for (let i = 1; i < rows.length; i++) expect(rows[i]!.cutoffFrom > rows[i - 1]!.labelTo).toBe(true)
  })

  it('el nivel reducido no tiene bloque de selección', () => {
    const lean = { ...h30, tier: 'lean' as const, blocks: { ...h30.blocks!, selection: undefined } }
    expect(customerBlockRows(lean).map((r) => r.key)).toEqual(['train', 'calibration', 'holdout'])
  })

  it('niveles: completo y reducido son modelos; solo referencia no; sin entrenar se abstiene', () => {
    expect(tierIsFullModel('full')).toBe(true)
    expect(tierIsFullModel('lean')).toBe(true)
    expect(tierIsFullModel('reference_only')).toBe(false)
    expect(tierAbstains('abstained')).toBe(true)
    expect(tierAbstains('full')).toBe(false)
    expect(customerBlockRows(null)).toEqual([])
  })
})
