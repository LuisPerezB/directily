import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ProtocolManifest from '../accuracy/ProtocolManifest.vue'
import ExtendedMetrics from '../accuracy/ExtendedMetrics.vue'
import type { DemandManifest, MetricSet } from '@/api/types'

const manifest: DemandManifest = {
  manifest_version: 'demand-protocol-v1', core: 'demand', as_of: '2026-10-26', history: { from: '2024-10-27', to: '2026-10-26', days: 730 },
  protocol: { backtest: true, independent_holdout: true }, training: { final_fit: { train_from: '2024-10-27', train_through: '2026-10-26' } },
  selection: { origins: ['2025-10-01'], target_from: '2025-10-02', target_to: '2026-04-01', unique_target_dates: 182, shared_target_dates_between_windows: 0 },
  calibration: { residual_target_from: '2025-10-02', residual_target_to: '2026-04-01', observable_at: '2026-07-28', n_residual_rows: 100, residuals_observable_when_calibrating: true },
  holdout: { present: true, independent: true, origin: '2026-07-28', target_from: '2026-07-29', target_to: '2026-10-26', used_for_model_selection: false, used_for_hyperparameters: false, used_for_thresholds: false, used_for_interval_calibration: false },
  label_maturity: { rule: 'Una ventana entra solo cuando terminó su período objetivo.', label_horizon_days: 90, selection_evidence_end: '2026-07-28', latest_selection_window_target_to: '2026-04-01', holdout_evidence_end: '2026-10-26' },
  overlap: { overlapping_origins: false, shared_target_dates_between_selection_windows: 0, shared_target_dates_with_holdout: 0, note: 'Las ventanas de selección no comparten fechas objetivo.' },
  checks: { holdout_disjoint_from_selection: true },
}

describe('ProtocolManifest', () => {
  it('muestra en lenguaje claro las fechas de elección, calibración y prueba final', () => {
    const w = mount(ProtocolManifest, { props: { manifest } })
    expect(w.findAll('[data-step]').map((e) => e.attributes('data-step'))).toEqual(['history', 'selection', 'calibration', 'holdout', 'production'])
    expect(w.get('[data-test="step-holdout-range"]').text()).toMatch(/Del .*2026.* al .*2026/)
    expect(w.get('[data-step="holdout"]').text()).toContain('no participa en ninguna decisión')
    expect(w.get('[data-step="calibration"]').text()).toContain('ya eran observables')
    expect(w.get('[data-test="holdout-rules"]').text()).toContain('Usado para elegir el modelo: No')
    expect(w.text()).toContain('Las ventanas de selección no comparten fechas objetivo.')
    expect(w.findAll('[data-warning]')).toHaveLength(0)
  })

  it('sin período final independiente lo declara y avisa que las métricas son optimistas', () => {
    const w = mount(ProtocolManifest, { props: { manifest: { ...manifest, holdout: { present: false, independent: false, reason: 'La historia no alcanza.' } } } })
    expect(w.get('[data-step="holdout"]').text()).toContain('No hubo período final independiente')
    expect(w.get('[data-warning="manifest.warnings.noHoldout"]').text()).toContain('optimistas')
    expect(w.text()).toContain('La historia no alcanza.')
  })

  it('ventanas solapadas y verificaciones fallidas se muestran como advertencias', () => {
    const w = mount(ProtocolManifest, { props: { manifest: { ...manifest, overlap: { overlapping_origins: true, shared_target_dates_between_selection_windows: 9, shared_target_dates_with_holdout: 0, note: 'Solape.' }, checks: { holdout_disjoint_from_selection: false } } } })
    expect(w.find('[data-warning="manifest.warnings.overlap"]').exists()).toBe(true)
    expect(w.get('[data-warning="manifest.warnings.checksFailed"]').text()).toContain('holdout_disjoint_from_selection')
  })

  it('una ejecución sin manifiesto lo dice', () => {
    expect(mount(ProtocolManifest, { props: { manifest: null } }).get('[data-test="manifest-missing"]').text()).toContain('no tiene manifiesto')
  })
})

describe('ExtendedMetrics', () => {
  const metrics: MetricSet = {
    wape: 0.4926, mae: 1.2, bias: -0.04, mase: 0.84, rmsse: 0.76, pinball: { p10: 0.37, p50: null, p90: 0.64 }, interval_width: { '80': 8.42, '95': 14.06 },
    money: { currency: 'USD', wape: 0.66, mae: 10, bias: -0.06, actual_total: 233944.59, forecast_total: 219938.16, n_obs: 15458 },
    sample: { n_obs: 15458, n_series: 183, n_windows: 1, exclusions: { scale_undefined: 0, price_missing: 0, stockout_days: 1012 } },
  }

  it('muestra MASE y RMSSE con su nota de lectura, y la amplitud de los rangos', () => {
    const w = mount(ExtendedMetrics, { props: { metrics, independentHoldout: true } })
    expect(w.text()).toContain('0,84')
    expect(w.text()).toContain('0,76')
    expect(w.text()).toContain('Menor que 1: mejora al método simple')
    expect(w.get('[data-test="mase-note"]').text()).toContain('Menor que 1 significa que mejora')
    expect(w.text()).toContain('8,4')
  })

  it('P50 sin publicar se declara «no publicada» en lugar de mostrar un valor', () => {
    const w = mount(ExtendedMetrics, { props: { metrics, p50: { published: false, reason: 'no_improvement' }, independentHoldout: true } })
    const p50 = w.get('[data-test="p50-row"]')
    expect(p50.text()).toContain('No publicada')
    expect(p50.text()).toContain('—')
    expect(w.get('[data-test="p50-decision"]').text()).toContain('no_improvement')
  })

  it('el dinero se muestra aparte de las unidades, con la moneda', () => {
    const w = mount(ExtendedMetrics, { props: { metrics, independentHoldout: true } })
    const money = w.get('[data-test="money-metrics"]')
    expect(money.text()).toContain('Error en dinero (USD)')
    expect(money.text()).toMatch(/233\.?945/)
    expect(money.text()).toContain('En unidades')
  })

  it('muestra la muestra y las exclusiones solo si ocurrieron', () => {
    const w = mount(ExtendedMetrics, { props: { metrics, independentHoldout: true } })
    const sample = w.get('[data-test="sample-info"]')
    expect(sample.text()).toMatch(/15\.?458 observaciones, 183 series, 1 ventanas/)
    expect(sample.findAll('li')).toHaveLength(1)
    expect(sample.text()).toMatch(/1\.?012 días con quiebre/)
  })

  it('sin período final independiente avisa que los cuantiles no se evalúan', () => {
    const w = mount(ExtendedMetrics, { props: { metrics: { ...metrics, pinball: { p10: null, p50: null, p90: null } }, independentHoldout: false } })
    expect(w.text()).toContain('Sin un período final independiente no se evalúan los cuantiles')
  })

  it('sin métricas ampliadas (ejecución antigua) no inventa valores', () => {
    const w = mount(ExtendedMetrics, { props: { metrics: { wape: 0.5, mae: 1, bias: 0 }, independentHoldout: false } })
    expect(w.find('[data-test="extended-missing"]').exists()).toBe(true)
    expect(w.find('[data-test="money-metrics"]').exists()).toBe(false)
  })
})
