import type {
  HorizonSupport,
  HorizonSupportStatus,
  QualityStatus,
  ResultEnvelope,
  UncertaintySource,
} from '@/api/types'

/**
 * Presentación de la envoltura común de resultados (ADR-V2-03) y del respaldo por horizonte.
 * Funciones puras: deciden QUÉ se puede mostrar como completo y con qué advertencias; los textos salen de i18n.
 */

export type Tone = 'success' | 'info' | 'warning' | 'danger' | 'neutral'

const QUALITY_TONE: Record<QualityStatus, Tone> = {
  ok: 'success',
  degraded: 'warning',
  insufficient_data: 'warning',
  not_configured: 'neutral',
  insufficient_evidence: 'danger',
}

export function qualityTone(status: string | null | undefined): Tone {
  return (status && QUALITY_TONE[status as QualityStatus]) || 'neutral'
}

/** Solo `ok` se puede presentar como un resultado completo. Los demás estados se muestran explícitamente. */
export function isCompleteQuality(status: string | null | undefined): boolean {
  return status === 'ok'
}

/** Estados de abstención: el resultado no existe o no es aplicable (nunca se rellena con un número). */
export function isAbstention(status: string | null | undefined): boolean {
  return status === 'insufficient_data' || status === 'not_configured' || status === 'insufficient_evidence'
}

export const UNCERTAINTY_SOURCES: UncertaintySource[] = ['empirical_holdout', 'conformal', 'model', 'unknown', 'none']

/** Convierte supuestos o limitaciones (texto u objeto {code, message}) en una lista de textos sin vacíos ni repetidos. */
export function noteTexts(list: readonly (string | { code?: string; message?: string } | null | undefined)[] | null | undefined): string[] {
  const out: string[] = []
  for (const item of list ?? []) {
    const text = typeof item === 'string' ? item : (item?.message ?? item?.code ?? '')
    const clean = text.trim()
    if (clean && !out.includes(clean)) out.push(clean)
  }
  return out
}

/** Primeros caracteres de una huella o versión de configuración (para mostrar sin ocupar la pantalla). */
export function shortHash(value: string | null | undefined, length = 10): string | null {
  if (!value) return null
  return value.length > length ? value.slice(0, length) : value
}

export interface EnvelopeView {
  quality: QualityStatus | null
  tone: Tone
  complete: boolean
  abstains: boolean
  uncertainty: UncertaintySource | null
  /** El sistema no conoce la incertidumbre: no se debe dibujar una banda ni hablar de "seguridad estadística". */
  uncertaintyUnknown: boolean
  limitations: string[]
  assumptions: string[]
  asOf: string | null
  horizonStart: string | null
  horizonEnd: string | null
  configVersion: string | null
  snapshotId: number | null
  resultType: string | null
}

export function envelopeView(env: ResultEnvelope | null | undefined): EnvelopeView | null {
  if (!env) return null
  const quality = env.quality_status ?? null
  const uncertainty = env.uncertainty_source ?? null
  return {
    quality,
    tone: qualityTone(quality),
    complete: isCompleteQuality(quality),
    abstains: isAbstention(quality),
    uncertainty,
    uncertaintyUnknown: uncertainty === null || uncertainty === 'unknown' || uncertainty === 'none',
    limitations: noteTexts(env.limitations),
    assumptions: noteTexts(env.assumptions),
    asOf: env.as_of ?? null,
    horizonStart: env.horizon_start ?? null,
    horizonEnd: env.horizon_end ?? null,
    configVersion: env.config_version ?? null,
    snapshotId: env.data_snapshot_id ?? null,
    resultType: env.result_type ?? null,
  }
}

// ─── Respaldo por horizonte ────────────────────────────────────────────────

export interface HorizonSupportView {
  status: HorizonSupportStatus
  tone: Tone
  /** false = la cifra existe pero NO debe presentarse como completa (aviso, estilo punteado, sin banda). */
  showAsComplete: boolean
  /** No dibujar bandas de intervalo (el motor tampoco las escribe en estos casos). */
  hideIntervals: boolean
  /** Hay que pedir una ejecución con ese horizonte. */
  needsNewRun: boolean
  reasons: string[]
  /** Clave i18n del texto principal del aviso; null cuando no hay nada que advertir. */
  noticeKey: string | null
}

export function horizonSupportView(support: HorizonSupport | null | undefined): HorizonSupportView | null {
  if (!support) return null
  const reasons = (support.reasons ?? []).map((r) => (typeof r === 'string' ? r : r.message)).filter((r): r is string => !!r)
  switch (support.status) {
    case 'ok':
      return { status: 'ok', tone: 'success', showAsComplete: true, hideIntervals: false, needsNewRun: false, reasons: [], noticeKey: null }
    case 'degraded':
      return { status: 'degraded', tone: 'warning', showAsComplete: true, hideIntervals: false, needsNewRun: false, reasons, noticeKey: 'horizonSupport.degraded' }
    case 'insufficient_data':
      return { status: 'insufficient_data', tone: 'warning', showAsComplete: false, hideIntervals: true, needsNewRun: false, reasons, noticeKey: 'horizonSupport.insufficient_data' }
    case 'not_computed':
      return { status: 'not_computed', tone: 'info', showAsComplete: false, hideIntervals: true, needsNewRun: true, reasons, noticeKey: 'horizonSupport.not_computed' }
    default:
      return { status: 'unknown', tone: 'neutral', showAsComplete: false, hideIntervals: false, needsNewRun: false, reasons, noticeKey: 'horizonSupport.unknown' }
  }
}

/** Casos especiales de una serie (producto nuevo, descontinuado...). Etiquetas conocidas; otras se muestran tal cual. */
export const CASE_LABELS = [
  'new_product_category_median',
  'new_product_own_average',
  'new_product_own_average_low_evidence',
  'sparse_history',
  'discontinued_zero',
  'branch_closed_declared',
  'branch_opening',
  'exceptional_event',
] as const

export function caseTone(label: string | null | undefined): Tone {
  if (!label) return 'neutral'
  if (label === 'discontinued_zero' || label === 'branch_closed_declared') return 'danger'
  if (label === 'exceptional_event') return 'info'
  return 'warning'
}

/** Etiquetas distintas presentes en los puntos de una serie, en orden de aparición. */
export function distinctCaseLabels(points: readonly { case_label?: string | null }[] | null | undefined): string[] {
  const out: string[] = []
  for (const p of points ?? []) {
    if (p.case_label && !out.includes(p.case_label)) out.push(p.case_label)
  }
  return out
}

// ─── Reversión: códigos de error ───────────────────────────────────────────

export const ROLLBACK_ERROR_CODES = [
  'no_current_run',
  'rollback_already_current',
  'rollback_target_not_production',
  'rollback_target_not_previous',
  'rollback_target_without_models',
  'rollback_target_without_forecasts',
  'rollback_artifact_mismatch',
  'rollback_target_not_restorable',
  'rollback_target_without_slot',
  'rollback_target_without_artifact',
  'rollback_target_incompatible',
] as const

export function rollbackErrorKey(code: string | null | undefined): string | null {
  return code && (ROLLBACK_ERROR_CODES as readonly string[]).includes(code) ? `rollback.errors.${code}` : null
}
