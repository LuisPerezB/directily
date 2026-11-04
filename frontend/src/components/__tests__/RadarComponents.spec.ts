import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { setupTenant } from '@/test/helpers'
import { horizonSummary, opportunity, opportunityDetail, radarSummary } from '@/test/enterprise'
import { ApiError } from '@/api/errors'
import type { RadarFeedbackResult, TenantRole } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

const api = vi.hoisted(() => ({ agenda: vi.fn(), opportunity: vi.fn(), feedback: vi.fn(), summary: vi.fn(), opportunities: vi.fn(), materialize: vi.fn() }))
vi.mock('@/api', async (orig) => ({ ...(await orig<typeof import('@/api')>()), radarApi: api }))

import RadarSummaryPanel from '../radar/RadarSummaryPanel.vue'
import OpportunityTable from '../radar/OpportunityTable.vue'
import AgendaPanel from '../radar/AgendaPanel.vue'
import OpportunityDetail from '../radar/OpportunityDetail.vue'

enableAutoUnmount(afterEach)
const KEY_RULE = /^[A-Za-z0-9._:-]{8,120}$/
const tenantAs = (role: TenantRole) => {
  const pinia = setupTenant(role)
  useAuthStore().user = { id: 7, name: 'Ana', email: 'a@x.test', locale: 'es', is_superadmin: false }
  return pinia
}
beforeEach(() => { Object.values(api).forEach((m) => m.mockReset()) })

describe('Resumen del Radar: honestidad de cifras', () => {
  it('cada horizonte va aparte y el pronóstico no se suma ni entre horizontes ni con la caída observada', () => {
    const w = mount(RadarSummaryPanel, { props: { summary: radarSummary() }, global: { plugins: [tenantAs('analyst')] } })
    const cards = w.findAll('[data-test="horizon-card"]')
    expect(cards.map((c) => c.attributes('data-horizon'))).toEqual(['7', '30'])
    // 1000 (pronóstico) + 250 (caída) = 1250; 1000 + 1000 entre horizontes = 2000: ninguna suma existe.
    const text = w.text().replace(/\s| /g, '')
    expect(text).not.toMatch(/1\.?250/)
    expect(text).not.toMatch(/2\.?000/)
    expect(text).not.toMatch(/1\.?650/)
    expect(cards[1]!.get('[data-test="forecast-block"]').text()).toContain('1.000')
    expect(cards[1]!.get('[data-test="observed-block"]').text()).toContain('250')
    expect(cards[1]!.get('[data-test="forecast-block"]').text()).not.toContain('250')
    expect(w.text()).toContain('sumarlos contaría dos veces la misma compra')
  })

  it('valor y contribución nulos dicen «No estimado/No estimada», con cuántas no tienen costo confiable', () => {
    const summary = radarSummary({ by_horizon: { '30': horizonSummary({ value: { ...horizonSummary().value, gross_value: null, contribution: null, contribution_unavailable: 3 } }) } })
    const w = mount(RadarSummaryPanel, { props: { summary }, global: { plugins: [tenantAs('analyst')] } })
    expect(w.get('[data-test="gross"]').text()).toContain('No estimado')
    expect(w.get('[data-test="contribution"]').text()).toContain('No estimada')
    expect(w.get('[data-test="contribution-unavailable"]').text()).toContain('3 sin costo confiable')
    expect(w.get('[data-test="gross"]').text()).not.toMatch(/0,00/)
  })

  it('la caída observada de nivel de cliente nula también es «No estimado», no cero', () => {
    const summary = radarSummary({ by_horizon: { '30': horizonSummary({ customer_level: { ...horizonSummary().customer_level, observed_spend_decline: null } }) } })
    const w = mount(RadarSummaryPanel, { props: { summary }, global: { plugins: [tenantAs('analyst')] } })
    expect(w.get('[data-test="decline"]').text()).toContain('No estimado')
  })

  it('rotula el valor como oportunidad potencial, atribución no demostrada, y muestra corte, unidad y moneda', () => {
    const w = mount(RadarSummaryPanel, { props: { summary: radarSummary() }, global: { plugins: [tenantAs('analyst')] } })
    expect(w.text()).toContain('Oportunidad potencial, atribución no demostrada')
    expect(w.get('[data-test="as-of"]').text()).toMatch(/Datos hasta/)
    expect(w.get('[data-test="unit"]').text()).toContain('oportunidades')
    expect(w.get('[data-test="currency"]').text()).toContain('USD')
  })

  it('no revela cuántas oportunidades se retienen para medición, aunque la API lo envíe a quien aprueba', () => {
    const w = mount(RadarSummaryPanel, { props: { summary: radarSummary({ control_withheld: 17 }) }, global: { plugins: [tenantAs('admin')] } })
    const text = w.text().toLowerCase()
    expect(text).not.toContain('17')
    expect(text).not.toContain('control')
    expect(text).not.toContain('retenid')
    expect(text).not.toContain('experiment')
  })

  it('sin predicción muestra el mensaje de la API y ningún cero inventado', () => {
    const summary = radarSummary({ as_of: null, totals: { opportunities: 0, customers: 0, unit: 'oportunidades' }, by_horizon: {}, run_id: null })
    const w = mount(RadarSummaryPanel, { props: { summary, message: 'Hay una predicción vigente pero aún no hay oportunidades propuestas.' }, global: { plugins: [tenantAs('analyst')] } })
    expect(w.find('[data-test="horizon-card"]').exists()).toBe(false)
    expect(w.get('[data-test="summary-message"]').text()).toContain('aún no hay oportunidades')
    expect(w.find('[data-test="as-of-missing"]').exists()).toBe(true)
  })
})

describe('Lista de oportunidades', () => {
  const mountTable = (rows = [opportunity()], role: TenantRole = 'analyst') => mount(OpportunityTable, { props: { rows, currency: 'USD', caption: 'Oportunidades' }, global: { plugins: [tenantAs(role)] } })

  it('muestra probabilidad, ventana, valor rotulado, disponibilidad y próxima acción', () => {
    const w = mountTable()
    expect(w.get('[data-test="probability"]').text()).toMatch(/^62\s?%$/)
    expect(w.get('[data-test="window-range"]').text()).toContain('5 a 20')
    expect(w.get('[data-test="opportunity-value"]').text()).toContain('Valor bruto esperado (supuesto)')
    expect(w.get('[data-test="opportunity-value"]').text()).toContain('Oportunidad potencial, atribución no demostrada')
    expect(w.get('[data-test="inventory-available"]').text()).toContain('36')
    expect(w.text()).toContain('Llamar al comprador')
    expect(w.get('[data-test="customer-link"]').text()).toBe('CLI-003')
  })

  it('contribución nula con costo no confiable: «Costo no confiable» y el motivo de la API, nunca 0', () => {
    const w = mountTable([opportunity({ value: { ...opportunity().value, expected_contribution: null, cost_status: 'unreliable', cost_reasons: ['cost_dispersion_high'] } })])
    const value = w.get('[data-test="opportunity-value"]')
    expect(value.text()).toContain('Costo no confiable')
    expect(value.text()).toContain('Costo muy variable')
    // Solo hay una cifra (el valor bruto supuesto): la contribución no es un 0.
    expect(value.findAll('[data-test="figure"]')).toHaveLength(1)
    expect(value.findAll('[data-test="not-estimated"]')).toHaveLength(1)
  })

  it('contribución nula con costo desconocido: «No estimada»', () => {
    const w = mountTable([opportunity({ value: { ...opportunity().value, expected_contribution: null, cost_status: 'unknown', cost_reasons: [] } })])
    expect(w.get('[data-test="opportunity-value"]').text()).toContain('No estimada')
  })

  it('valor bruto nulo: «No estimado»', () => {
    const w = mountTable([opportunity({ value: { ...opportunity().value, expected_gross_value: null } })])
    expect(w.get('[data-test="opportunity-value"] [data-test="not-estimated"]').text()).toContain('No estimado')
  })

  it('a nivel de cliente el valor es una caída observada, no un pronóstico ni una contribución', () => {
    const row = opportunity({ id: 12, kind: 'recovery', entity_type: 'customer', product: null, probability: null, expected_orders: null, expected_units: null,
      value: { ...opportunity().value, expected_gross_value: 250, expected_contribution: null, basis_code: 'observed_spend_decline', cost_status: 'unknown' }, inventory: { status: 'not_applicable' } })
    const w = mountTable([row])
    const value = w.get('[data-test="opportunity-value"]')
    expect(value.attributes('data-basis')).toBe('observed_decline')
    expect(value.text()).toContain('Caída observada del gasto')
    expect(value.text()).toContain('no se suma al valor pronosticado')
    expect(value.text()).not.toContain('Valor bruto esperado')
    expect(value.text()).not.toContain('Contribución esperada')
    expect(w.text()).toContain('Todo el cliente')
  })

  it('la ventana solo muestra cifras con estado estimated u overdue: con evidencia insuficiente muestra el estado, aunque lleguen números', () => {
    const w = mountTable([opportunity({ window: { status: 'insufficient_data', p10_days: 3, p50_days: 7, p90_days: 11 } })])
    expect(w.find('[data-test="window-range"]').exists()).toBe(false)
    expect(w.get('[data-test="window-state"]').text()).toContain('evidencia insuficiente')
    expect(w.get('[data-test="window"]').text()).not.toMatch(/\b(3|7|11)\b/)
  })

  it('sin foto de inventario dice «Sin dato de inventario» (no es cero) y el tránsito va aparte', () => {
    const unknown = mountTable([opportunity({ inventory: { status: 'unknown', reason: 'no_recent_inventory_snapshot' } })])
    expect(unknown.get('[data-test="inventory-unknown"]').text()).toBe('Sin dato de inventario')
    expect(unknown.find('[data-test="inventory-available"]').exists()).toBe(false)
    const known = mountTable()
    expect(known.get('[data-test="inventory-transit"]').text()).toContain('En tránsito (aparte)')
  })

  it('una probabilidad nula es una abstención, no 0 %', () => {
    const w = mountTable([opportunity({ probability: null, expected_orders: null, expected_units: null })])
    expect(w.find('[data-test="no-probability"]').exists()).toBe(true)
    expect(w.text()).not.toContain('0 %')
  })

  it('no muestra el grupo del experimento (seller-facing), aunque la API lo envíe a quien aprueba', () => {
    const w = mountTable([opportunity({ experiment_group: 'treatment' })], 'admin')
    const text = w.text().toLowerCase()
    expect(text).not.toContain('treatment')
    expect(text).not.toContain('tratamiento')
    expect(text).not.toContain('control')
    expect(text).not.toContain('experiment')
  })

  it.each([['viewer', false], ['analyst', true], ['admin', true]] as const)('%s: botón de seguimiento %s', (role, visible) => {
    const w = mountTable([opportunity()], role)
    expect(w.find('[data-test="open-feedback"]').exists()).toBe(visible)
    expect(w.find('[data-test="open-detail"]').exists()).toBe(true)
  })

  it('una oportunidad cerrada o ya medida no ofrece seguimiento', () => {
    expect(mountTable([opportunity({ state: 'pedido_recibido', status: 'executed' })]).find('[data-test="open-feedback"]').exists()).toBe(false)
    expect(mountTable([opportunity({ state: 'descartado', status: 'rejected' })]).find('[data-test="open-feedback"]').exists()).toBe(false)
    expect(mountTable([opportunity({ status: 'measured' })]).find('[data-test="open-feedback"]').exists()).toBe(false)
  })

  it('L6: todas las columnas, también la de acciones, tienen nombre accesible', () => {
    const w = mountTable()
    expect(w.findAll('thead th').map((th) => th.text())).toContain('Acciones')
    expect(w.findAll('thead th').every((th) => th.text() !== '')).toBe(true)
    expect(w.get('[role="region"]').attributes('tabindex')).toBe('0')
  })

  it('muestra el estado heredado de la agenda y el del flujo', () => {
    const w = mountTable([opportunity({ state: 'interesado', status: 'in_progress', assigned_user: { id: 4, name: 'Luis' } })])
    expect(w.text()).toContain('Interesado')
    expect(w.text()).toContain('En curso')
    expect(w.text()).toContain('Asignada a Luis')
  })

  it('sin filas dice que no hay oportunidades', () => {
    expect(mountTable([]).text()).toContain('No hay oportunidades con estos filtros')
  })
})

describe('Agenda: por cliente con estados heredados', () => {
  const group = (over = {}) => ({
    customer: opportunity().customer, opportunities_count: 2, best_score: 42.5, top_priority: 1, states: { pendiente: 1, contactado: 1 }, next_action: 'Llamar al comprador',
    opportunities: [opportunity({ id: 11 }), opportunity({ id: 12, state: 'contactado', status: 'in_progress', product: { id: 9, sku: 'S9', name: 'Leche', unit: 'litro', category: null } })], ...over,
  })
  const page = (groups = [group()]) => ({ data: groups, meta: { current_page: 1, per_page: 10, total: groups.length, last_page: 1 }, date: '2026-11-02', envelope: { as_of: '2026-10-31', uncertainty_source: 'unknown' }, label: 'x' })
  const mountAgenda = async (role: TenantRole = 'analyst', data: unknown = page()) => {
    api.agenda.mockResolvedValue(data)
    const w = mount(AgendaPanel, { props: { filters: {}, currency: 'USD' }, global: { plugins: [tenantAs(role)] } })
    await flushPromises()
    return w
  }

  it('agrupa por cliente (no se duplica por SKU) y muestra los estados heredados con su conteo', async () => {
    const w = await mountAgenda()
    expect(w.findAll('[data-test="agenda-group"]')).toHaveLength(1)
    expect(w.findAll('[data-test="agenda-row"]')).toHaveLength(2)
    const chips = w.findAll('[data-test="agenda-state-chip"]').map((c) => c.text())
    expect(chips).toEqual(['Pendiente: 1', 'Contactado: 1'])
    expect(w.text()).toContain('Próxima acción')
    expect(api.agenda).toHaveBeenCalledWith(expect.objectContaining({ date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/), page: 1 }))
  })

  it('lleva corte y unidad; sin oportunidades muestra el estado vacío', async () => {
    const w = await mountAgenda('analyst', page([]))
    expect(w.find('[data-test="agenda-empty"]').exists()).toBe(true)
    expect(w.get('[data-test="as-of"]').text()).toMatch(/Datos hasta/)
  })

  it('el seguimiento solo se ofrece con decisions.manage y no en las cerradas', async () => {
    const viewer = await mountAgenda('viewer')
    expect(viewer.findAll('[data-test="agenda-feedback"]')).toHaveLength(0)
    const closed = await mountAgenda('analyst', page([group({ opportunities: [opportunity({ state: 'descartado', status: 'rejected' })] })]))
    expect(closed.findAll('[data-test="agenda-feedback"]')).toHaveLength(0)
    const analyst = await mountAgenda('analyst')
    expect(analyst.findAll('[data-test="agenda-feedback"]')).toHaveLength(2)
  })

  it('L7: la agenda aplica la misma regla de seguimiento que la lista: no se ofrece con un flujo medido, cerrado, vencido o rechazado', async () => {
    const rows = ['measured', 'closed', 'expired', 'rejected', 'suggested'].map((status, i) => opportunity({ id: 200 + i, status: status as never }))
    const w = await mountAgenda('analyst', page([group({ opportunities: rows })]))
    const buttons = w.findAll('[data-test="agenda-row"]').map((r) => r.find('[data-test="agenda-feedback"]').exists())
    expect(buttons).toEqual([false, false, false, false, true])
  })

  it('no muestra el grupo del experimento en la agenda', async () => {
    const w = await mountAgenda('admin', page([group({ opportunities: [opportunity({ experiment_group: 'treatment' })] })]))
    const text = w.text().toLowerCase()
    expect(text).not.toContain('treatment')
    expect(text).not.toContain('tratamiento')
    expect(text).not.toContain('experiment')
  })

  it('emite abrir el detalle con el id de la oportunidad', async () => {
    const w = await mountAgenda()
    await w.findAll('[data-test="agenda-feedback"]')[0]!.trigger('click')
    expect(w.emitted('feedback')![0]).toEqual([11])
  })
})

describe('Detalle y seguimiento de una oportunidad', () => {
  const feedbackResult = (over: Partial<RadarFeedbackResult['data']> = {}): RadarFeedbackResult => ({
    data: { opportunity: opportunityDetail({ state: 'contactado', status: 'in_progress', allowed_feedback: ['interesado', 'pedido_recibido'] }),
      event: { id: 900, event: 'feedback', at: '2026-11-02T10:00:00-05:00', from_agenda_status: 'pendiente', agenda_status: 'contactado', from_status: null, to_status: 'in_progress', actor_kind: 'user', actor_user_id: 7, note: null, interest: null, snoozed_until: null, implied_workflow_status: 'in_progress', workflow_sync: 'applied', workflow_sync_reason: null, workflow_status_after: 'in_progress' },
      replayed: false, ...over },
  })
  const mountDetail = async (role: TenantRole = 'analyst', detail = opportunityDetail()) => {
    api.opportunity.mockResolvedValue({ data: detail, status_map: { version: 'radar-agenda-map-v1', table: [{ legacy: 'pendiente', workflow: 'suggested', note: 'Sin gestión', allowed_next: ['contactado'] }] } })
    const w = mount(OpportunityDetail, { props: { id: 11, open: true }, global: { plugins: [tenantAs(role)] } })
    await flushPromises()
    return w
  }
  const submit = async (w: Awaited<ReturnType<typeof mountDetail>>, state = 'contactado') => {
    await w.get('#fb-state').setValue(state)
    await w.get('[data-test="submit-feedback"]').trigger('click')
    await flushPromises()
  }

  it('carga la oportunidad con su historial inmutable y la tabla de mapeo de estados', async () => {
    const w = await mountDetail('analyst', opportunityDetail({ history: [{ id: 1, event: 'feedback', at: '2026-11-01T09:00:00-05:00', from_agenda_status: 'pendiente', agenda_status: 'contactado', from_status: null, to_status: 'in_progress', actor_kind: 'user', actor_user_id: 7, note: 'Llamé', interest: null, snoozed_until: null, implied_workflow_status: 'in_progress', workflow_sync: 'skipped', workflow_sync_reason: 'decision_approval_required' }] }))
    expect(api.opportunity).toHaveBeenCalledWith(11)
    expect(w.get('[data-test="history"]').text()).toContain('Seguimiento: de Pendiente a Contactado')
    expect(w.get('[data-test="history"]').text()).toContain('Llamé')
    expect(w.get('[data-test="sync"]').text()).toContain('el flujo de trabajo no se movió: la acción es sensible y necesita aprobación')
    expect(w.text()).toContain('radar-agenda-map-v1')
  })

  it('el seguimiento manda state e idempotency_key y confirma sin enviar ningún mensaje', async () => {
    api.feedback.mockResolvedValue(feedbackResult())
    const w = await mountDetail()
    expect(w.text()).toContain('Predictily no envía ningún mensaje al cliente')
    await w.get('#fb-note').setValue('  Llamé por teléfono  ')
    await submit(w)
    expect(api.feedback).toHaveBeenCalledTimes(1)
    const [id, body] = api.feedback.mock.calls[0]!
    expect(id).toBe(11)
    expect(body).toMatchObject({ state: 'contactado', note: 'Llamé por teléfono' })
    expect(body.idempotency_key).toMatch(KEY_RULE)
    expect(w.get('[data-test="feedback-done"]').text()).toContain('Seguimiento registrado')
    expect(w.get('[data-test="feedback-sync"]').text()).toContain('avanzó a «En curso»')
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('cada seguimiento lleva una clave nueva', async () => {
    api.feedback.mockResolvedValue(feedbackResult())
    const w = await mountDetail()
    await submit(w, 'contactado')
    await submit(w, 'interesado')
    const [a, b] = api.feedback.mock.calls.map((c) => c[1].idempotency_key)
    expect(a).toMatch(KEY_RULE)
    expect(b).toMatch(KEY_RULE)
    expect(a).not.toBe(b)
  })

  it('S01: tras un éxito la clave se descarta aunque el siguiente seguimiento tenga el mismo cuerpo: nunca se reutiliza una clave ya resuelta', async () => {
    api.feedback.mockResolvedValue(feedbackResult({ opportunity: opportunityDetail({ allowed_feedback: ['contactado', 'interesado'] }) }))
    const w = await mountDetail()
    await submit(w, 'contactado')
    await submit(w, 'contactado')
    const [a, b] = api.feedback.mock.calls.map((c) => c[1].idempotency_key)
    expect(a).toMatch(KEY_RULE)
    expect(b).not.toBe(a)
  })

  it('tras una caída de red el reintento conserva la clave (no repite el efecto si ya se registró)', async () => {
    api.feedback.mockRejectedValueOnce(new ApiError(0, null, 'sin red')).mockResolvedValueOnce(feedbackResult())
    const w = await mountDetail()
    await submit(w)
    expect(w.get('[data-test="action-error"]').attributes('data-kind')).toBe('network')
    await w.get('[data-test="submit-feedback"]').trigger('click')
    await flushPromises()
    const [first, second] = api.feedback.mock.calls.map((c) => c[1].idempotency_key)
    expect(second).toBe(first)
  })

  it('posponer exige la fecha de reactivación y no viaja sin ella', async () => {
    const w = await mountDetail()
    await w.get('#fb-state').setValue('pospuesto')
    await w.get('[data-test="submit-feedback"]').trigger('click')
    await flushPromises()
    expect(api.feedback).not.toHaveBeenCalled()
    expect(w.text()).toContain('Indique la fecha de reactivación')
    await w.get('#fb-snooze').setValue('2026-12-15')
    await w.get('[data-test="submit-feedback"]').trigger('click')
    await flushPromises()
    expect(api.feedback.mock.calls[0]![1]).toMatchObject({ state: 'pospuesto', snoozed_until: '2026-12-15' })
  })

  it('reintento exacto (200 replayed): avisa que ya estaba registrado', async () => {
    api.feedback.mockResolvedValue(feedbackResult({ replayed: true }))
    const w = await mountDetail()
    await submit(w)
    expect(w.get('[data-test="feedback-done"]').text()).toContain('ya estaba registrado')
  })

  it('403 de segregación o de habilidad: mensaje claro, sin identificadores', async () => {
    api.feedback.mockRejectedValue(new ApiError(403, { message: 'x', code: 'decision_segregation_of_duties', context: { roles: ['proposer'] } } as never, 'x'))
    const w = await mountDetail()
    await submit(w, 'descartado')
    expect(w.get('[data-test="action-error"]').attributes('data-kind')).toBe('segregation')
    expect(w.get('[data-test="action-error"]').text()).toContain('quien la propuso')
  })

  it('409 de clave reutilizada y 422 de transición inválida: mensajes propios y se recarga el estado vigente', async () => {
    api.feedback.mockRejectedValueOnce(new ApiError(409, { message: 'x', code: 'idempotency_key_reused' } as never, 'x'))
    const w = await mountDetail()
    await submit(w)
    expect(w.get('[data-test="action-error"]').text()).toContain('ya se usó con otro contenido')
    api.feedback.mockRejectedValueOnce(new ApiError(422, { message: 'x', code: 'invalid_transition' } as never, 'x'))
    await submit(w, 'interesado')
    expect(w.get('[data-test="action-error"]').text()).toContain('no está permitido desde el estado actual')
    expect(api.opportunity.mock.calls.length).toBeGreaterThanOrEqual(2)
  })

  it('un viewer ve la oportunidad pero no el formulario de seguimiento', async () => {
    const w = await mountDetail('viewer')
    expect(w.find('[data-test="feedback-form"]').exists()).toBe(false)
    expect(w.get('[data-test="read-only"]').text()).toContain('no registrar seguimiento')
  })

  it('una oportunidad cerrada (sin destinos permitidos) no ofrece seguimiento', async () => {
    const w = await mountDetail('analyst', opportunityDetail({ allowed_feedback: [], state: 'descartado', status: 'rejected' }))
    expect(w.find('[data-test="no-feedback"]').exists()).toBe(true)
    expect(w.find('#fb-state').exists()).toBe(false)
  })

  it('no muestra el grupo del experimento', async () => {
    const w = await mountDetail('admin', opportunityDetail({ experiment_group: 'treatment' }))
    const text = w.text().toLowerCase()
    expect(text).not.toContain('treatment')
    expect(text).not.toContain('tratamiento')
    expect(text).not.toContain('experiment')
  })

  it('L3: si la oportunidad vence antes de mañana no se ofrece posponer y se explica; con fecha válida sí', async () => {
    const expired = await mountDetail('analyst', opportunityDetail({ expires_at: '2020-01-01T04:59:59-05:00' }))
    const option = expired.findAll('#fb-state option').find((o) => o.attributes('value') === 'pospuesto')!
    expect(option.attributes('disabled')).toBeDefined()
    expect(expired.get('[data-test="snooze-unavailable"]').text()).toContain('vence antes de mañana')
    expect(expired.find('#fb-snooze').exists()).toBe(false)
    const fine = await mountDetail('analyst', opportunityDetail({ expires_at: '2099-12-31T04:59:59-05:00' }))
    expect(fine.findAll('#fb-state option').find((o) => o.attributes('value') === 'pospuesto')!.attributes('disabled')).toBeUndefined()
    expect(fine.find('[data-test="snooze-unavailable"]').exists()).toBe(false)
    await fine.get('#fb-state').setValue('pospuesto')
    const min = fine.get('#fb-snooze').attributes('min')!
    const max = fine.get('#fb-snooze').attributes('max')!
    expect(max >= min).toBe(true)
  })

  it('M5: con el seguimiento en vuelo el cuadro no se cierra (ni con Escape ni con la X) y el error no se pierde', async () => {
    let fail: (e: unknown) => void = () => undefined
    api.feedback.mockImplementation(() => new Promise((_, reject) => { fail = reject }))
    const w = await mountDetail()
    await w.get('#fb-state').setValue('contactado')
    await w.get('[data-test="submit-feedback"]').trigger('click')
    await flushPromises()
    expect(w.find('[aria-label="Cerrar"]').exists()).toBe(false)
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(w.emitted('close')).toBeUndefined()
    fail(new ApiError(403, { message: 'x', code: 'forbidden' } as never, 'x'))
    await flushPromises()
    expect(w.get('[data-test="action-error"]').attributes('data-kind')).toBe('forbidden')
    expect(w.find('[aria-label="Cerrar"]').exists()).toBe(true)
  })

  it('L1: un segundo clic con el primero en vuelo no envía un segundo seguimiento', async () => {
    api.feedback.mockImplementation(() => new Promise(() => undefined))
    const w = await mountDetail()
    await w.get('#fb-state').setValue('contactado')
    const submit = (w.vm as unknown as { $: { setupState: { submit: () => Promise<void> } } }).$.setupState.submit
    void submit()
    void submit()
    await flushPromises()
    expect(api.feedback).toHaveBeenCalledTimes(1)
  })

  it('L2: tras una caída de red se recarga; si el seguimiento ya figura registrado se avisa y no se repite; si no, el reintento conserva la clave', async () => {
    api.feedback.mockRejectedValue(new ApiError(0, null, 'sin red'))
    const w = await mountDetail()
    api.opportunity.mockResolvedValue({ data: opportunityDetail({ state: 'contactado', status: 'in_progress', allowed_feedback: ['interesado'] }), status_map: { version: 'radar-agenda-map-v1', table: [] } })
    await submit(w, 'contactado')
    expect(api.opportunity).toHaveBeenCalledTimes(2)
    expect(w.get('[data-test="feedback-applied"]').text()).toContain('ya figuraba registrado')
    expect(w.find('[data-test="action-error"]').exists()).toBe(false)
    expect(api.feedback).toHaveBeenCalledTimes(1)
    // Si la oportunidad sigue igual, queda el error y el mismo reintento lleva la misma clave; otro cuerpo, otra clave.
    api.opportunity.mockResolvedValue({ data: opportunityDetail(), status_map: { version: 'radar-agenda-map-v1', table: [] } })
    api.feedback.mockClear()
    api.feedback.mockRejectedValue(new ApiError(503, null, 'caído'))
    await submit(w, 'contactado')
    expect(w.get('[data-test="action-error"]').attributes('data-kind')).toBe('server')
    await w.get('[data-test="submit-feedback"]').trigger('click')
    await flushPromises()
    await w.get('#fb-note').setValue('otra nota')
    await w.get('[data-test="submit-feedback"]').trigger('click')
    await flushPromises()
    const keys = api.feedback.mock.calls.map((c) => c[1].idempotency_key)
    expect(keys[1]).toBe(keys[0])
    expect(keys[2]).not.toBe(keys[0])
  })

  it('con costo no confiable avisa que no se estima la contribución', async () => {
    const w = await mountDetail('analyst', opportunityDetail({ value: { ...opportunity().value, expected_contribution: null, cost_status: 'unreliable', cost_reasons: ['cost_coverage_low'] } }))
    expect(w.get('[data-test="cost-notice"]').text()).toContain('no se estima la contribución')
    expect(w.get('[data-test="cost-notice"]').text()).toContain('Cobertura de costo baja')
  })
})
