import { isApiError } from '@/api/errors'
import type { AssistantFigure, AssistantReply } from '@/api/types'
import { DASH } from './format'

/**
 * El asistente solo EXPLICA resultados ya calculados. La interfaz muestra las cifras exclusivamente desde `figures`
 * (con su envoltura) y jamás extrae números del texto de la respuesta.
 */

export const USABLE_FIGURE_STATUSES = ['ok', 'no_data'] as const

export function figureHasData(f: Pick<AssistantFigure, 'status'>): boolean {
  return f.status === 'ok'
}

export interface FigureRow {
  path: string
  value: number | string | boolean | null
}

/**
 * Aplana `data` de una cifra en filas ruta → valor escalar (listas: solo los primeros elementos). Es lo único que se
 * muestra como cifra; el orden y los valores son los de la herramienta.
 */
export function flattenFigure(data: unknown, options: { maxRows?: number; maxListItems?: number } = {}): { rows: FigureRow[]; truncated: boolean } {
  const maxRows = options.maxRows ?? 40
  const maxList = options.maxListItems ?? 5
  const rows: FigureRow[] = []
  let truncated = false
  const walk = (node: unknown, path: string, depth: number): void => {
    if (rows.length >= maxRows) {
      truncated = true
      return
    }
    if (node === null || node === undefined) {
      if (path) rows.push({ path, value: null })
      return
    }
    if (typeof node === 'number' || typeof node === 'string' || typeof node === 'boolean') {
      rows.push({ path: path || 'valor', value: node })
      return
    }
    if (depth > 4) {
      truncated = true
      return
    }
    if (Array.isArray(node)) {
      node.slice(0, maxList).forEach((item, i) => walk(item, `${path}[${i + 1}]`, depth + 1))
      if (node.length > maxList) truncated = true
      return
    }
    if (typeof node === 'object') {
      for (const [k, v] of Object.entries(node as Record<string, unknown>)) walk(v, path ? `${path}.${k}` : k, depth + 1)
    }
  }
  walk(data, '', 0)
  return { rows, truncated }
}

export function formatFigureValue(value: FigureRow['value'], formatNumber: (n: number) => string, yes: string, no: string): string {
  if (value === null) return DASH
  if (typeof value === 'number') return formatNumber(value)
  if (typeof value === 'boolean') return value ? yes : no
  return value
}

export interface ReplyView {
  /** El texto del modelo solo se muestra como explicación cuando las cifras citadas están respaldadas. */
  showExplanation: boolean
  /** Mensaje fijo del sistema (no es texto libre del modelo). */
  fixedMessage: string | null
  figures: AssistantFigure[]
  deniedFigures: AssistantFigure[]
  discardedNumbers: string[]
}

export function replyView(reply: AssistantReply): ReplyView {
  const answered = reply.status === 'answered'
  return {
    showExplanation: answered && reply.answer.trim() !== '',
    fixedMessage: answered ? null : reply.answer,
    figures: reply.figures.filter((f) => f.status === 'ok' || f.status === 'no_data'),
    deniedFigures: reply.figures.filter((f) => f.status !== 'ok' && f.status !== 'no_data'),
    discardedNumbers: reply.status === 'ungrounded_rejected' ? reply.grounding.ungrounded_numbers : [],
  }
}

/** Historial que se reenvía (máx. 6 mensajes): solo texto, nunca cifras reinterpretadas. */
export function trimHistory<T>(items: readonly T[], max = 6): T[] {
  return items.slice(-max)
}

export type AssistantErrorKind = 'disabled' | 'plan' | 'rate_limited' | 'quota' | 'provider' | 'validation' | 'other'

export function assistantError(error: unknown): { kind: AssistantErrorKind; retryAfter: number | null } {
  if (!isApiError(error)) return { kind: 'other', retryAfter: null }
  if (error.status === 503 && error.code === 'assistant_disabled') return { kind: 'disabled', retryAfter: null }
  if (error.status === 402) return { kind: 'plan', retryAfter: null }
  if (error.status === 429) {
    const retry = Number((error.body as { retry_after?: unknown } | null)?.retry_after)
    return { kind: error.code === 'assistant_quota_exceeded' ? 'quota' : 'rate_limited', retryAfter: Number.isFinite(retry) && retry > 0 ? retry : null }
  }
  if (error.status === 502) return { kind: 'provider', retryAfter: null }
  if (error.status === 422) return { kind: 'validation', retryAfter: null }
  return { kind: 'other', retryAfter: null }
}
