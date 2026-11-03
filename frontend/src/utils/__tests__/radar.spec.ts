import { describe, expect, it } from 'vitest'
import type { RadarInventory, RadarOpportunity, RadarSummary } from '@/api/types'
import { windowView } from '../customers'
import { buildFeedbackBody, canFollowUp, contributionView, feedbackChoices, feedbackInputError, feedbackScope, inventoryView, snoozeBounds, summaryHorizons, valueBasis } from '../radar'

const value = (over: Partial<RadarOpportunity['value']> = {}): RadarOpportunity['value'] => ({
  expected_gross_value: 100, expected_contribution: 40, cost_status: 'reliable', cost_reasons: [], action_cost: null, incremental_estimate: null,
  attribution: { label: 'potential_unproven', text: 'Oportunidad potencial, atribución no demostrada' }, basis_code: 'forecast_units_x_last_net_price', ...over,
})

describe('Radar: valor sin mezclar naturalezas', () => {
  it('cliente × producto es un pronóstico; a nivel de cliente, una caída observada', () => {
    expect(valueBasis({ entity_type: 'customer_product', value: value() })).toBe('forecast')
    expect(valueBasis({ entity_type: 'customer', value: value({ basis_code: 'observed_spend_decline' }) })).toBe('observed_decline')
    expect(valueBasis({ entity_type: 'customer_product', value: value({ basis_code: 'observed_spend_decline' }) })).toBe('observed_decline')
  })

  it('una contribución nula es «no estimada» con los motivos de la API, nunca 0', () => {
    const unknown = contributionView(value({ expected_contribution: null, cost_status: 'unreliable', cost_reasons: ['cost_dispersion_high', 'cost_dispersion_high'] }))
    expect(unknown).toEqual({ known: false, amount: null, status: 'unreliable', reasons: ['cost_dispersion_high'] })
    expect(contributionView(value({ expected_contribution: 0 }))).toMatchObject({ known: true, amount: 0 })
    expect(contributionView(value({ expected_contribution: 12.5 }))).toMatchObject({ known: true, amount: 12.5, status: 'reliable' })
  })
})

describe('Radar: disponibilidad', () => {
  it('sin fotografía vigente no hay cifras (no es cero)', () => {
    const view = inventoryView({ status: 'unknown', reason: 'no_recent_inventory_snapshot', available: 0 } as RadarInventory)
    expect(view).toMatchObject({ status: 'unknown', available: null, onHand: null, inTransit: null, reason: 'no_recent_inventory_snapshot' })
    expect(inventoryView(null).status).toBe('unknown')
    expect(inventoryView({ status: 'not_applicable' }).status).toBe('not_applicable')
  })

  it('con foto vigente: disponible y tránsito van aparte', () => {
    expect(inventoryView({ status: 'known', on_hand: 10, reserved: 2, available: 8, in_transit: 5 })).toMatchObject({ status: 'known', available: 8, inTransit: 5, onHand: 10, reserved: 2 })
  })
})

describe('Radar: ventana de recompra solo con cifras que la API respalda', () => {
  it('estimated y overdue con cuantiles → rango; insufficient_data o censored → solo el estado, aunque lleguen números', () => {
    expect(windowView({ status: 'estimated', p10_days: 5, p50_days: 9, p90_days: 20 })).toMatchObject({ kind: 'range', p10: 5, p90: 20 })
    expect(windowView({ status: 'overdue', p10_days: 5, p50_days: 9, p90_days: 20 })).toMatchObject({ kind: 'range', overdue: true })
    expect(windowView({ status: 'insufficient_data', p10_days: 5, p50_days: 9, p90_days: 20 })).toEqual({ kind: 'state', status: 'insufficient_data' })
    expect(windowView({ status: 'censored', p10_days: 1, p50_days: 2, p90_days: 3 }).kind).toBe('state')
    expect(windowView({ status: 'estimated', p10_days: null, p50_days: null, p90_days: null }).kind).toBe('state')
  })
})

describe('Radar: resumen por horizonte', () => {
  it('los horizontes se ordenan y se informan por separado: no hay total entre ellos', () => {
    const summary = { by_horizon: { '30': { opportunities: 2 }, '7': { opportunities: 5 }, '90': { opportunities: 1 } } } as unknown as Pick<RadarSummary, 'by_horizon'>
    const list = summaryHorizons(summary)
    expect(list.map((x) => x.horizon)).toEqual([7, 30, 90])
    expect(list.map((x) => x.data.opportunities)).toEqual([5, 2, 1])
    expect(summaryHorizons(null)).toEqual([])
  })
})

describe('Radar: seguimiento', () => {
  it('solo ofrece los destinos que la API permite desde el estado actual', () => {
    expect(feedbackChoices({ allowed_feedback: ['descartado', 'contactado'] })).toEqual(['contactado', 'descartado'])
    expect(feedbackChoices({ allowed_feedback: [] })).toEqual([])
    expect(feedbackChoices(null)).toEqual([])
  })

  it('el cuerpo lleva idempotency_key; la fecha solo al posponer y la nota recortada a 500', () => {
    expect(buildFeedbackBody({ state: 'contactado', note: ' llamé ' }, 'radar-abcdefgh')).toEqual({ state: 'contactado', idempotency_key: 'radar-abcdefgh', note: 'llamé' })
    expect(buildFeedbackBody({ state: 'pospuesto', snoozedUntil: '2026-12-01' }, 'radar-abcdefgh')).toEqual({ state: 'pospuesto', idempotency_key: 'radar-abcdefgh', snoozed_until: '2026-12-01' })
    expect(buildFeedbackBody({ state: 'contactado', snoozedUntil: '2026-12-01' }, 'radar-abcdefgh').snoozed_until).toBeUndefined()
    expect(buildFeedbackBody({ state: 'contactado', note: 'x'.repeat(900) }, 'radar-abcdefgh').note).toHaveLength(500)
  })

  it('posponer exige fecha', () => {
    expect(feedbackInputError({ state: 'pospuesto' })).toBe('snooze')
    expect(feedbackInputError({ state: 'pospuesto', snoozedUntil: '2026-12-01' })).toBeNull()
    expect(feedbackInputError({ state: 'contactado' })).toBeNull()
  })

  it('la reactivación va de mañana a un año o al vencimiento, lo que ocurra antes', () => {
    expect(snoozeBounds('2026-11-01', null)).toEqual({ min: '2026-11-02', max: '2027-11-01', available: true })
    expect(snoozeBounds('2026-11-01', '2026-11-20T23:59:59-05:00')).toEqual({ min: '2026-11-02', max: '2026-11-20', available: true })
    expect(snoozeBounds('2026-11-01', '2030-01-01T00:00:00Z').max).toBe('2027-11-01')
    expect(snoozeBounds('2026-11-01', '2026-11-02')).toEqual({ min: '2026-11-02', max: '2026-11-02', available: true })
  })

  it('L3: el máximo nunca queda por debajo del mínimo; con la oportunidad vencida o por vencer hoy no hay fecha válida y no se ofrece posponer', () => {
    for (const expiry of ['2026-11-01T23:59:59-05:00', '2026-10-15T00:00:00Z', '2026-11-01']) {
      const b = snoozeBounds('2026-11-01', expiry)
      expect(b.available, expiry).toBe(false)
      expect(b.max >= b.min, expiry).toBe(true)
    }
  })

  it('L7: el seguimiento se ofrece con la habilidad, sin estado final y con un flujo que lo admite (una sola regla para la lista y la agenda)', () => {
    const can = (a: string) => a === 'decisions.manage'
    expect(canFollowUp({ state: 'pendiente', status: 'suggested' }, can)).toBe(true)
    expect(canFollowUp({ state: 'pendiente', status: 'suggested' }, () => false)).toBe(false)
    expect(canFollowUp({ state: 'descartado', status: 'rejected' }, can)).toBe(false)
    expect(canFollowUp({ state: 'pedido_recibido', status: 'executed' }, can)).toBe(false)
    for (const status of ['measured', 'closed', 'expired', 'rejected'] as const) expect(canFollowUp({ state: 'pendiente', status }, can), status).toBe(false)
  })

  it('la clave de un seguimiento es de la oportunidad y del cuerpo', () => {
    const a = feedbackScope(1, { state: 'contactado', note: ' hola ' })
    expect(feedbackScope(1, { state: 'contactado', note: 'hola' })).toBe(a)
    expect(feedbackScope(2, { state: 'contactado', note: 'hola' })).not.toBe(a)
    expect(feedbackScope(1, { state: 'interesado', note: 'hola' })).not.toBe(a)
    expect(feedbackScope(1, { state: 'pospuesto', snoozedUntil: '2026-12-01' })).not.toBe(feedbackScope(1, { state: 'pospuesto', snoozedUntil: '2026-12-02' }))
  })
})
