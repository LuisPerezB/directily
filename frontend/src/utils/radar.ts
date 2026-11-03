/**
 * Presentación honesta del Revenue Radar y de la agenda (V2.5 · W5-E; ADR-V25-12). Funciones puras:
 *  - la ventana de recompra se muestra con `CustomerWindowCell` / `windowView` de utils/customers (cifras solo con estado `estimated` u `overdue`; nunca un día exacto);
 *  - la disponibilidad desconocida no es cero;
 *  - el valor de una relación cliente × producto es un PRONÓSTICO (supuesto) y el de nivel de cliente una caída OBSERVADA del gasto: son cosas distintas
 *    que jamás se suman;
 *  - la contribución `null` (costo no confiable) se dice «no estimada» con el motivo de la API.
 */
import type {
  AgendaState, CostStatus, RadarFeedbackBody, RadarHorizonSummary, RadarInventory, RadarOpportunity, RadarOpportunityDetail, RadarSummary,
} from '@/api/types'
import type { Ability } from './abilities'
import { isKnown } from './enterprise'

export const AGENDA_STATES: AgendaState[] = ['pendiente', 'contactado', 'interesado', 'pedido_recibido', 'pospuesto', 'descartado']

/** Estados que cierran la oportunidad (salen de la agenda). */
export const CLOSING_STATES: AgendaState[] = ['pedido_recibido', 'descartado']

export type ValueBasis = 'forecast' | 'observed_decline'

/** Naturaleza del valor de una oportunidad: pronóstico (cliente × producto) u observado (caída del gasto del cliente). Nunca se mezclan en una misma cifra. */
export function valueBasis(opp: Pick<RadarOpportunity, 'entity_type' | 'value'>): ValueBasis {
  if (opp.value?.basis_code === 'observed_spend_decline' || opp.entity_type === 'customer') return 'observed_decline'
  return 'forecast'
}

export interface ContributionView {
  known: boolean
  amount: number | null
  status: CostStatus
  reasons: string[]
}

/** Contribución esperada: `null` = «no estimada» (costo no confiable o desconocido), con los motivos que la API dio. */
export function contributionView(value: Pick<RadarOpportunity['value'], 'expected_contribution' | 'cost_status' | 'cost_reasons'>): ContributionView {
  const known = isKnown(value.expected_contribution)
  return { known, amount: known ? value.expected_contribution : null, status: value.cost_status ?? 'unknown', reasons: [...new Set(value.cost_reasons ?? [])] }
}

export interface InventoryView {
  status: 'known' | 'unknown' | 'not_applicable'
  available: number | null
  onHand: number | null
  reserved: number | null
  inTransit: number | null
  reason: string | null
}

/** Disponibilidad al corte: sin fotografía vigente (`unknown`) no hay cifras (no es cero); el tránsito va aparte y no cuenta como disponible. */
export function inventoryView(inv: RadarInventory | null | undefined): InventoryView {
  const status = inv?.status === 'known' || inv?.status === 'not_applicable' ? inv.status : 'unknown'
  if (status !== 'known' || !inv) return { status, available: null, onHand: null, reserved: null, inTransit: null, reason: typeof inv?.reason === 'string' ? inv.reason : null }
  return {
    status,
    available: isKnown(inv.available) ? inv.available : null,
    onHand: isKnown(inv.on_hand) ? inv.on_hand : null,
    reserved: isKnown(inv.reserved) ? inv.reserved : null,
    inTransit: isKnown(inv.in_transit) ? inv.in_transit : null,
    reason: null,
  }
}

/** Horizontes del resumen en orden numérico. Cada uno se informa dentro de sí mismo: nada se suma entre horizontes (las ventanas se solapan). */
export function summaryHorizons(summary: Pick<RadarSummary, 'by_horizon'> | null | undefined): { horizon: number; data: RadarHorizonSummary }[] {
  return Object.entries(summary?.by_horizon ?? {})
    .map(([h, data]) => ({ horizon: Number(h), data }))
    .filter((x) => Number.isFinite(x.horizon))
    .sort((a, b) => a.horizon - b.horizon)
}

/** Destinos de seguimiento que ofrece la interfaz: solo los que la API permite desde el estado actual. */
export function feedbackChoices(detail: Pick<RadarOpportunityDetail, 'allowed_feedback'> | null | undefined): AgendaState[] {
  const allowed = new Set(detail?.allowed_feedback ?? [])
  return AGENDA_STATES.filter((s) => allowed.has(s))
}

export interface FeedbackInput {
  state: AgendaState
  note?: string | null
  snoozedUntil?: string | null
}

/** 'pospuesto' exige la fecha de reactivación; el resto la ignora. */
export function feedbackInputError(input: FeedbackInput): 'snooze' | null {
  return input.state === 'pospuesto' && !input.snoozedUntil ? 'snooze' : null
}

/** Cuerpo de POST /radar/opportunities/{id}/feedback con su clave de idempotencia. La nota es texto libre (máx. 500); el servidor enmascara correos y números largos. */
export function buildFeedbackBody(input: FeedbackInput, idempotencyKey: string): RadarFeedbackBody {
  const body: RadarFeedbackBody = { state: input.state, idempotency_key: idempotencyKey }
  const note = (input.note ?? '').trim()
  if (note !== '') body.note = note.slice(0, 500)
  if (input.state === 'pospuesto' && input.snoozedUntil) body.snoozed_until = input.snoozedUntil
  return body
}

/**
 * Fecha mínima (mañana) y máxima (un año o el vencimiento de la oportunidad, lo que ocurra antes) para posponer. Formato AAAA-MM-DD. `available` es false cuando el
 * vencimiento cae antes de mañana: no hay fecha válida (el máximo nunca queda por debajo del mínimo) y no se ofrece posponer.
 */
export function snoozeBounds(today: string, expiresAt: string | null | undefined): { min: string; max: string; available: boolean } {
  const base = new Date(`${today}T12:00:00Z`)
  const add = (days: number): string => new Date(base.getTime() + days * 86_400_000).toISOString().slice(0, 10)
  const min = add(1)
  const year = add(365)
  const expiry = expiresAt ? expiresAt.slice(0, 10) : null
  const max = expiry && expiry < year ? expiry : year
  return { min, max: max < min ? min : max, available: max >= min }
}

/** Estados del flujo en los que una oportunidad admite seguimiento de agenda (igual que `AgendaStatusMap::FEEDBACK_WORKFLOW_STATUSES`). */
export const FEEDBACK_WORKFLOW_STATUSES = ['suggested', 'assigned', 'reviewed', 'approved', 'in_progress', 'executed'] as const

/** ¿Se ofrece «Registrar seguimiento»? Con la habilidad, sin estado heredado final y con el flujo en un estado que lo admite. Una sola regla para la lista y la agenda. */
export function canFollowUp(row: Pick<RadarOpportunity, 'state' | 'status'>, can: (ability: Ability) => boolean): boolean {
  return can('decisions.manage') && !CLOSING_STATES.includes(row.state) && (FEEDBACK_WORKFLOW_STATUSES as readonly string[]).includes(row.status)
}

/** Identidad de un seguimiento para su clave de idempotencia: oportunidad y todo lo que cambia el cuerpo. */
export function feedbackScope(opportunityId: number, input: FeedbackInput): string {
  return JSON.stringify([opportunityId, input.state, (input.note ?? '').trim(), input.state === 'pospuesto' ? (input.snoozedUntil ?? '') : ''])
}
