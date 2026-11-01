import { describe, expect, it } from 'vitest'
import {
  caseTone,
  distinctCaseLabels,
  envelopeView,
  horizonSupportView,
  isAbstention,
  isCompleteQuality,
  noteTexts,
  qualityTone,
  rollbackErrorKey,
  shortHash,
} from '../envelope'
import type { HorizonSupport, ResultEnvelope } from '@/api/types'

const support = (status: HorizonSupport['status'], reasons: HorizonSupport['reasons'] = []): HorizonSupport => ({ horizon: 60, status, reasons })

describe('respaldo por horizonte (estados de abstención)', () => {
  it('ok: se presenta como completo y no avisa', () => {
    const v = horizonSupportView(support('ok'))!
    expect(v.showAsComplete).toBe(true)
    expect(v.hideIntervals).toBe(false)
    expect(v.noticeKey).toBeNull()
  })

  it('insufficient_data: NO es completo, oculta los intervalos y conserva los motivos', () => {
    const v = horizonSupportView(support('insufficient_data', [{ code: 'history_lt_2h', message: 'La historia (80 días) es menor que 2×60.' }]))!
    expect(v.showAsComplete).toBe(false)
    expect(v.hideIntervals).toBe(true)
    expect(v.reasons).toEqual(['La historia (80 días) es menor que 2×60.'])
    expect(v.noticeKey).toBe('horizonSupport.insufficient_data')
  })

  it('degraded: se muestra pero avisa que las métricas son optimistas', () => {
    const v = horizonSupportView(support('degraded'))!
    expect(v.showAsComplete).toBe(true)
    expect(v.noticeKey).toBe('horizonSupport.degraded')
  })

  it('not_computed: pide una ejecución con ese horizonte y no es completo', () => {
    const v = horizonSupportView(support('not_computed'))!
    expect(v.needsNewRun).toBe(true)
    expect(v.showAsComplete).toBe(false)
  })

  it('unknown (ejecución antigua): no se afirma que esté respaldado', () => {
    const v = horizonSupportView(support('unknown'))!
    expect(v.showAsComplete).toBe(false)
    expect(v.noticeKey).toBe('horizonSupport.unknown')
  })

  it('acepta motivos como texto y sin información devuelve null', () => {
    expect(horizonSupportView(support('insufficient_data', ['sin ventanas']))!.reasons).toEqual(['sin ventanas'])
    expect(horizonSupportView(null)).toBeNull()
    expect(horizonSupportView(undefined)).toBeNull()
  })
})

describe('envoltura común de resultados', () => {
  const env = (over: Partial<ResultEnvelope> = {}): ResultEnvelope => ({
    run_id: 7, core: 'customer', as_of: '2026-10-26', horizon_start: '2026-10-27', horizon_end: '2026-11-25', target_definition: null,
    entity_keys: ['customer_id', 'product_id'], unit: 'product_unit', currency: 'USD', result_type: 'forecast', model_version: null,
    data_snapshot_id: 12, config_version: 'abcdef0123456789', assumptions: [], limitations: [], quality_status: 'degraded', uncertainty_source: 'empirical_holdout', ...over,
  })

  it('solo "ok" es un resultado completo; los demás estados se muestran explícitos', () => {
    expect(isCompleteQuality('ok')).toBe(true)
    for (const s of ['degraded', 'insufficient_data', 'not_configured', 'insufficient_evidence', null, undefined, 'otro']) expect(isCompleteQuality(s)).toBe(false)
    expect(isAbstention('insufficient_data')).toBe(true)
    expect(isAbstention('not_configured')).toBe(true)
    expect(isAbstention('insufficient_evidence')).toBe(true)
    expect(isAbstention('degraded')).toBe(false)
  })

  it('tonos por estado; un estado desconocido es neutral y nunca "success"', () => {
    expect(qualityTone('ok')).toBe('success')
    expect(qualityTone('degraded')).toBe('warning')
    expect(qualityTone('insufficient_evidence')).toBe('danger')
    expect(qualityTone(null)).toBe('neutral')
    expect(qualityTone('inventado')).toBe('neutral')
  })

  it('arma la vista con calidad, limitaciones y supuestos de la envoltura', () => {
    const v = envelopeView(env({ limitations: ['Sin P50 publicada', { code: 'x', message: 'Corte limitado' }, 'Sin P50 publicada'], assumptions: ['Precio de la última compra'] }))!
    expect(v.quality).toBe('degraded')
    expect(v.complete).toBe(false)
    expect(v.limitations).toEqual(['Sin P50 publicada', 'Corte limitado'])
    expect(v.assumptions).toEqual(['Precio de la última compra'])
    expect(v.snapshotId).toBe(12)
    expect(v.uncertaintyUnknown).toBe(false)
  })

  it('incertidumbre desconocida, ausente o inexistente se marca como desconocida', () => {
    expect(envelopeView(env({ uncertainty_source: 'unknown' }))!.uncertaintyUnknown).toBe(true)
    expect(envelopeView(env({ uncertainty_source: null }))!.uncertaintyUnknown).toBe(true)
    expect(envelopeView(env({ uncertainty_source: 'conformal' }))!.uncertaintyUnknown).toBe(false)
    expect(envelopeView(null)).toBeNull()
  })

  it('textos de notas y huellas cortas', () => {
    expect(noteTexts(['  a  ', '', null, undefined, { message: 'b' }, { code: 'c' }])).toEqual(['a', 'b', 'c'])
    expect(shortHash('0123456789abcdef', 8)).toBe('01234567')
    expect(shortHash(null)).toBeNull()
  })
})

describe('casos especiales y reversión', () => {
  it('etiquetas distintas por orden de aparición', () => {
    expect(distinctCaseLabels([{ case_label: null }, { case_label: 'new_product_category_median' }, { case_label: 'new_product_category_median' }, { case_label: 'discontinued_zero' }, {}])).toEqual(['new_product_category_median', 'discontinued_zero'])
    expect(distinctCaseLabels(undefined)).toEqual([])
  })
  it('tonos de las etiquetas', () => {
    expect(caseTone('discontinued_zero')).toBe('danger')
    expect(caseTone('new_product_category_median')).toBe('warning')
    expect(caseTone('exceptional_event')).toBe('info')
    expect(caseTone(null)).toBe('neutral')
  })
  it('códigos 409 de reversión conocidos devuelven su clave de texto', () => {
    expect(rollbackErrorKey('rollback_target_not_previous')).toBe('rollback.errors.rollback_target_not_previous')
    expect(rollbackErrorKey('rollback_artifact_mismatch')).toBe('rollback.errors.rollback_artifact_mismatch')
    expect(rollbackErrorKey('otra_cosa')).toBeNull()
    expect(rollbackErrorKey(null)).toBeNull()
  })
})
