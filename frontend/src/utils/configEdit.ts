import { isApiError } from '@/api/errors'
import type { ConfigValidationIssue } from '@/api/types'

/**
 * Edición de la configuración por core. Los errores de validación llegan de dos formas:
 * lista `[{key, code, message}]` (motor) o mapa `{campo: [mensajes]}` (Laravel). Ambas se normalizan.
 */
export function configIssues(error: unknown): ConfigValidationIssue[] {
  if (!isApiError(error)) return []
  const raw = (error.body as { errors?: unknown } | null)?.errors
  if (Array.isArray(raw)) {
    return raw
      .filter((e): e is Record<string, unknown> => !!e && typeof e === 'object')
      .map((e) => ({ key: String(e.key ?? '_'), code: typeof e.code === 'string' ? e.code : undefined, message: String(e.message ?? '') }))
      .filter((e) => e.message !== '')
  }
  if (raw && typeof raw === 'object') {
    return Object.entries(raw as Record<string, unknown>).flatMap(([key, messages]) =>
      (Array.isArray(messages) ? messages : [messages]).map((m) => ({ key: key.replace(/^overrides\./, ''), message: String(m) })),
    )
  }
  return []
}

export function issuesByKey(issues: readonly ConfigValidationIssue[]): Record<string, string[]> {
  const out: Record<string, string[]> = {}
  for (const i of issues) (out[i.key] ??= []).push(i.message)
  return out
}

export type ParsedValue = { ok: true; value: unknown } | { ok: false; message: string }

/** Convierte el texto de un campo al tipo del valor original (número, booleano, texto o JSON para listas y objetos). */
export function parseConfigValue(raw: string, original: unknown): ParsedValue {
  const text = raw.trim()
  if (typeof original === 'boolean') {
    if (text === 'true') return { ok: true, value: true }
    if (text === 'false') return { ok: true, value: false }
    return { ok: false, message: 'booleano' }
  }
  if (typeof original === 'number') {
    if (text === '') return { ok: false, message: 'vacío' }
    const n = Number(text.replace(',', '.'))
    return Number.isFinite(n) ? { ok: true, value: n } : { ok: false, message: 'número' }
  }
  if (Array.isArray(original) || (original !== null && typeof original === 'object')) {
    try {
      const parsed: unknown = JSON.parse(text)
      if (Array.isArray(original) && !Array.isArray(parsed)) return { ok: false, message: 'lista' }
      if (!Array.isArray(original) && (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed))) return { ok: false, message: 'objeto' }
      return { ok: true, value: parsed }
    } catch {
      return { ok: false, message: 'json' }
    }
  }
  if (original === null) {
    if (text === '') return { ok: true, value: null }
    try {
      return { ok: true, value: JSON.parse(text) as unknown }
    } catch {
      return { ok: true, value: text }
    }
  }
  return { ok: true, value: text }
}

export function formatConfigValue(value: unknown): string {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'string') return value
  if (typeof value === 'boolean' || typeof value === 'number') return String(value)
  return JSON.stringify(value)
}

export function valuesEqual(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

export type SourceKind = 'core' | 'profile' | 'tenant'

/** Procedencia de un valor: core, perfil sectorial (`profile:codigo@version`) o empresa. */
export function sourceKind(source: string | null | undefined): SourceKind {
  if (!source || source === 'core') return 'core'
  if (source.startsWith('profile:')) return 'profile'
  return 'tenant'
}

export function profileOfSource(source: string): string | null {
  return source.startsWith('profile:') ? source.slice('profile:'.length) : null
}

/**
 * Borrador de valores de empresa: parte de los actuales; cambiar un valor al del core/perfil lo quita del borrador
 * (no se guarda una sobrescritura idéntica al valor heredado).
 */
export function applyDraftValue(
  draft: Record<string, unknown>,
  key: string,
  value: unknown,
  inherited: unknown,
): Record<string, unknown> {
  const next = { ...draft }
  if (valuesEqual(value, inherited)) delete next[key]
  else next[key] = value
  return next
}
