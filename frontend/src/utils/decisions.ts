/**
 * Acciones del workflow de decisiones y del seguimiento de la agenda (V2.5 · W5-E; ADR-V25-13). Funciones puras:
 *  - qué botones se ofrecen (habilidad del rol; el servidor es la autoridad y se atienden 403/409/422);
 *  - el cuerpo de cada petición, SIEMPRE con `expected_version` y una `idempotency_key` nueva;
 *  - la traducción de los errores del servidor a mensajes claros en español.
 */
import { isApiError } from '@/api/errors'
import type { DecisionAllowedTransition, DecisionRecommendation, DecisionTransitionBody } from '@/api/types'
import type { Ability } from '@/utils/abilities'

/** Largo mínimo del motivo de un rechazo o descarte (igual que el servidor: TransitionRules::MIN_REASON_LENGTH). */
export const MIN_REASON_LENGTH = 5

/** Clave de idempotencia nueva (`^[A-Za-z0-9._:-]{8,120}$`). Usa crypto.randomUUID y cae a getRandomValues solo si no existe. */
export function newIdempotencyKey(prefix = 'ui'): string {
  const c = (globalThis as { crypto?: Crypto }).crypto
  let id: string
  if (c && typeof c.randomUUID === 'function') {
    id = c.randomUUID()
  } else if (c && typeof c.getRandomValues === 'function') {
    const bytes = c.getRandomValues(new Uint8Array(16))
    id = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  } else {
    id = `${Date.now().toString(16)}${Math.random().toString(16).slice(2)}`
  }
  return `${prefix}-${id}`
}

/**
 * Conserva la clave de un intento mientras no se sepa si el servidor lo procesó (red caída, 5xx): reintentar la MISMA acción con la misma clave NO repite el efecto.
 * La clave pertenece a una acción concreta (`scope`: recomendación, transición y cuerpo), no al cuadro de diálogo: otra acción, u otra versión de la misma, siempre
 * lleva una clave nueva y nunca reutiliza la pendiente de otra. Con cualquier respuesta definitiva (éxito o error 4xx) se descarta.
 */
export interface KeyHolder {
  take(scope?: string): string
  settle(): void
}

export function createKeyHolder(prefix = 'ui'): KeyHolder {
  let pending: { key: string; scope: string } | null = null
  return {
    take(scope = '') {
      if (pending === null || pending.scope !== scope) pending = { key: newIdempotencyKey(prefix), scope }
      return pending.key
    },
    settle() {
      pending = null
    },
  }
}

/** Identidad de una acción para su clave de idempotencia: recomendación, transición y todo lo que cambia el cuerpo (versión leída, motivo, responsable). */
export function actionScope(recommendationId: number, body: Pick<DecisionTransitionBody, 'to_status' | 'expected_version' | 'reason' | 'assignee_user_id'>): string {
  return JSON.stringify([recommendationId, body.to_status, body.expected_version, body.reason ?? null, body.assignee_user_id ?? null])
}

/** ¿El resultado de una petición deja el intento sin resolver (se puede reintentar con la misma clave)? */
export function attemptIsUnresolved(error: unknown): boolean {
  return !isApiError(error) || error.status === 0 || error.status >= 500
}

// ─── Qué botones se ofrecen ─────────────────────────────────────────────────

export type TransitionDisposition = 'hidden' | 'disabled' | 'enabled'

/**
 * Oculta la transición si el rol no tiene su habilidad (`decisions.manage`, `decisions.approve`); la deja desactivada, con su motivo, si el
 * servidor ya sabe que hoy no se puede (segregación de funciones, aprobación pendiente, grupo de control, versión sin leer).
 */
export function transitionDisposition(tr: DecisionAllowedTransition, can: (ability: Ability) => boolean, rec?: Pick<DecisionRecommendation, 'version'>): TransitionDisposition {
  if (tr.ability && !can(tr.ability as Ability)) return 'hidden'
  if (!tr.allowed) return 'disabled'
  if (rec && (rec.version === null || rec.version === undefined)) return 'disabled'
  return 'enabled'
}

export interface TransitionInput {
  reason?: string | null
  assigneeUserId?: number | null
}

/** Errores de captura de una transición (el servidor valida igual: esto solo evita un viaje inútil). */
export function transitionInputError(tr: DecisionAllowedTransition, input: TransitionInput): 'reason' | 'assignee' | null {
  if (tr.requires.reason && (input.reason ?? '').trim().length < MIN_REASON_LENGTH) return 'reason'
  if (tr.requires.assignee && !input.assigneeUserId) return 'assignee'
  return null
}

/** Cuerpo de POST /decisions/{id}/transition: `expected_version` (la versión leída) e `idempotency_key` siempre presentes. */
export function buildTransitionBody(rec: Pick<DecisionRecommendation, 'version'>, tr: DecisionAllowedTransition, input: TransitionInput, idempotencyKey: string): DecisionTransitionBody {
  if (rec.version === null || rec.version === undefined) throw new Error('La recomendación no trae versión: recárguela antes de actuar.')
  const body: DecisionTransitionBody = { to_status: tr.to_status, idempotency_key: idempotencyKey, expected_version: rec.version }
  const reason = (input.reason ?? '').trim()
  if (reason !== '') body.reason = reason
  if (tr.requires.assignee && input.assigneeUserId) body.assignee_user_id = input.assigneeUserId
  return body
}

// ─── Errores del servidor → mensaje ─────────────────────────────────────────

export type ActionErrorKind =
  | 'version_conflict' | 'forbidden' | 'segregation' | 'approval_required' | 'not_allowed' | 'control' | 'key_reused'
  | 'validation' | 'not_found' | 'conflict' | 'network' | 'server' | 'unknown'

export interface ActionError {
  kind: ActionErrorKind
  status: number
  code: string | null
  /** Funciones que impiden decidir (segregación de funciones), sin identificadores personales. */
  roles: string[]
  requiredAbility: string | null
  /** Mensaje del servidor (ya en español), para los casos sin texto propio. */
  serverMessage: string | null
  fieldErrors: Record<string, string[]>
}

const CONFLICT_CODES: Record<string, ActionErrorKind> = {
  decision_version_conflict: 'version_conflict',
  decision_approval_required: 'approval_required',
  decision_transition_not_allowed: 'not_allowed',
  model_transition_not_allowed: 'not_allowed',
  control_group_not_actionable: 'control',
  idempotency_key_reused: 'key_reused',
}

export function classifyActionError(error: unknown): ActionError {
  if (!isApiError(error)) {
    return { kind: 'unknown', status: -1, code: null, roles: [], requiredAbility: null, serverMessage: error instanceof Error ? error.message : null, fieldErrors: {} }
  }
  const body = error.body
  const ctxRoles = body?.context && Array.isArray((body.context as { roles?: unknown }).roles) ? ((body.context as { roles: unknown[] }).roles.filter((r): r is string => typeof r === 'string')) : []
  const base = {
    status: error.status,
    code: error.code,
    roles: ctxRoles,
    requiredAbility: typeof body?.required_ability === 'string' ? body.required_ability : null,
    serverMessage: error.message || null,
    fieldErrors: error.fieldErrors,
  }
  if (error.status === 0) return { ...base, kind: 'network' }
  if (error.status >= 500) return { ...base, kind: 'server' }
  if (error.status === 403) {
    return { ...base, kind: error.code === 'decision_segregation_of_duties' || error.code === 'approval_segregation_of_duties' ? 'segregation' : 'forbidden' }
  }
  if (error.status === 404) return { ...base, kind: 'not_found' }
  if (error.status === 409) return { ...base, kind: (error.code && CONFLICT_CODES[error.code]) || 'conflict' }
  if (error.status === 422) return { ...base, kind: 'validation' }
  return { ...base, kind: 'unknown' }
}

/** Códigos de 422 de la agenda con texto propio (`enterprise.errors.codes.<código>`). */
export const VALIDATION_CODES = ['invalid_transition', 'opportunity_closed', 'snooze_beyond_expiry', 'horizon_not_available'] as const

/** Clave i18n (`enterprise.roles.<clave>`) de una función que impide decidir; los puntos del identificador técnico pasan a guion bajo. */
export function segregationRoleKey(role: string): string {
  return role.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
}

/**
 * Códigos de restricción que emiten los servicios del Decision Engine y del Radar (`constraints[].code`). Cada uno tiene su texto en `decisions.constraint.<código>`;
 * el plan de compras añade restricciones con `family` (`supply.families.<familia>`). Una prueba cruza esta lista con el código del backend.
 */
export const KNOWN_CONSTRAINT_CODES = [
  'out_of_stock', 'stock_below_expected_units', 'over_budget', 'budget', 'window_without_figures', 'overstock_without_demand', 'demand_exceeds_availability', 'note',
] as const

/**
 * Códigos de las explicaciones (`explanation[].code`): llegan con su propio `label` en español desde la API y no se traducen aquí; se listan para que la prueba que
 * cruza el backend distinga una explicación de una restricción nueva sin texto.
 */
export const EXPLANATION_CODES = [
  'value_calculation', 'unmet_by_budget', 'units_basis', 'window', 'purchase_probability', 'excess_vs_demand', 'demand_vs_availability',
] as const

/** Clave de la restricción para mostrarla: su `code` o, en las del plan de compras, su `family`. */
export function constraintKey(c: { code?: unknown; family?: unknown; [key: string]: unknown }): { kind: 'code' | 'family'; key: string } | null {
  if (typeof c.code === 'string' && c.code !== '') return { kind: 'code', key: c.code }
  if (typeof c.family === 'string' && c.family !== '') return { kind: 'family', key: c.family }
  return null
}
