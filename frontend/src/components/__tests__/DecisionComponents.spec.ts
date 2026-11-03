import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { setupTenant } from '@/test/helpers'
import { TRANSITIONS, decisionSummary, recommendation, transition } from '@/test/enterprise'
import { ApiError } from '@/api/errors'
import type { DecisionRecommendation, TenantRole } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

const api = vi.hoisted(() => ({ list: vi.fn(), summary: vi.fn(), show: vi.fn(), transition: vi.fn(), settings: vi.fn(), users: vi.fn() }))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  decisionApi: { list: api.list, summary: api.summary, show: api.show, transition: api.transition, settings: api.settings },
  tenantApi: { users: api.users },
}))

import DecisionDetail from '../decisions/DecisionDetail.vue'
import TransitionDialog from '../decisions/TransitionDialog.vue'
import DecisionList from '../decisions/DecisionList.vue'
import DecisionSummaryCards from '../decisions/DecisionSummaryCards.vue'

enableAutoUnmount(afterEach)
const KEY_RULE = /^[A-Za-z0-9._:-]{8,120}$/
const tenantAs = (role: TenantRole) => {
  const pinia = setupTenant(role)
  useAuthStore().user = { id: 7, name: 'Ana', email: 'a@x.test', locale: 'es', is_superadmin: false }
  return pinia
}
beforeEach(() => { Object.values(api).forEach((m) => m.mockReset()) })

const ok = (data: DecisionRecommendation, replay = false) => ({ data, meta: { idempotent_replay: replay, event_id: 5 } })
const mountDetail = async (role: TenantRole, rec: DecisionRecommendation = recommendation()) => {
  api.show.mockResolvedValue(rec)
  const w = mount(DecisionDetail, { props: { id: rec.id }, global: { plugins: [tenantAs(role)] } })
  await flushPromises()
  return w
}
const actions = (w: Awaited<ReturnType<typeof mountDetail>>) => w.findAll('[data-action]').map((b) => b.attributes('data-action'))

describe('Detalle de la recomendación: contrato completo y cifras honestas', () => {
  it('carga por id y muestra el contrato: valor, contribución, puntaje y fórmula, probabilidad, disponibilidad, aprobación y atribución', async () => {
    const w = await mountDetail('analyst', recommendation({ constraints: [{ code: 'over_budget' }], requires_approval: true }))
    expect(api.show).toHaveBeenCalledWith(21)
    expect(w.get('[data-test="gross"]').text()).toContain('350')
    expect(w.get('[data-test="contribution"]').text()).toContain('120')
    expect(w.get('[data-test="score"]').text()).toBe('42,5')
    expect(w.text()).toContain('Fórmula bbbbbbbbbbbb')
    expect(w.get('[data-test="probability"]').text()).toMatch(/62\s?%/)
    expect(w.get('[data-test="availability"]').text()).toContain('36')
    expect(w.get('[data-test="requires-approval"]').text()).toBe('Sí')
    expect(w.get('[data-test="constraints"]').text()).toContain('Sobre presupuesto')
    expect(w.get('[data-test="attribution-note"]').text()).toContain('atribución no demostrada')
    expect(w.get('[data-test="as-of"]').text()).toMatch(/Datos hasta/)
    expect(w.get('[data-test="currency"]').text()).toContain('USD')
  })

  it('los valores nulos se dicen: contribución con costo no confiable, costo de acción sin configurar, sin incremento ni puntaje', async () => {
    const w = await mountDetail('analyst', recommendation({
      expected_contribution: null, action_cost: null, incremental_estimate: null, score: null, probability: null, availability: { status: 'unknown' },
      uncertainty: { expected_contribution_null_because: ['cost_dispersion_high'], cost: { reliable: false, reasons: ['cost_dispersion_high'] } },
    }))
    expect(w.get('[data-test="contribution"]').text()).toContain('Costo no confiable')
    expect(w.get('[data-test="contribution"]').text()).toContain('Costo muy variable')
    expect(w.get('[data-test="action-cost"]').text()).toContain('Costo no configurado')
    expect(w.get('[data-test="incremental"]').text()).toContain('Sin evidencia de incremento')
    expect(w.get('[data-test="score"]').text()).toBe('Sin puntaje')
    expect(w.get('[data-test="probability"]').text()).toBe('No aplica')
    expect(w.get('[data-test="availability"]').text()).toBe('Sin dato de inventario')
    expect(w.get('[data-test="contribution"]').findAll('[data-test="figure"]')).toHaveLength(0)
    expect(w.get('[data-test="action-cost"]').findAll('[data-test="figure"]')).toHaveLength(0)
  })

  it('el valor bruto nulo es «No estimado», no 0', async () => {
    const w = await mountDetail('analyst', recommendation({ expected_gross_value: null, expected_contribution: null }))
    expect(w.get('[data-test="gross"]').text()).toContain('No estimado')
    expect(w.get('[data-test="gross"]').find('[data-test="figure"]').exists()).toBe(false)
  })

  it('la línea de tiempo muestra transiciones con persona y fecha, sin comentarios del sistema ni datos del experimento', async () => {
    const w = await mountDetail('admin', recommendation({ events: [
      { id: 1, event: 'transition', from_status: null, to_status: 'suggested', agenda_status: null, actor_user_id: null, actor_kind: 'system', reason: null, payload: { experiment: { experiment_key: 'k', group: 'control' } }, created_at: '2026-11-01T08:00:00-05:00' },
      { id: 2, event: 'transition', from_status: 'suggested', to_status: 'assigned', agenda_status: null, actor_user_id: 7, actor_kind: 'user', reason: 'Para Ana', payload: {}, created_at: '2026-11-01T09:00:00-05:00' },
      { id: 3, event: 'comment', from_status: null, to_status: null, agenda_status: null, actor_user_id: null, actor_kind: 'system', reason: null, payload: { kind: 'refresh' }, created_at: '2026-11-01T10:00:00-05:00' },
    ] }))
    const items = w.findAll('[data-test="timeline"] li')
    expect(items).toHaveLength(2)
    expect(items[1]!.text()).toContain('De «Sugerida» a «Asignada»')
    expect(items[1]!.text()).toContain('Usted')
    expect(items[1]!.text()).toContain('Para Ana')
    expect(w.get('[data-test="timeline"]').text().toLowerCase()).not.toContain('control')
  })
})

describe('Detalle: grupo del experimento solo para quien aprueba', () => {
  const grouped = recommendation({ experiment_group: 'control', status: 'suggested' })
  it('un aprobador lo ve en el detalle', async () => {
    const w = await mountDetail('admin', grouped)
    expect(w.get('[data-test="experiment-group"]').text()).toContain('Control (retenida para medición)')
  })
  it('un analista no lo ve aunque la respuesta lo trajera', async () => {
    const w = await mountDetail('analyst', grouped)
    expect(w.find('[data-test="experiment-group"]').exists()).toBe(false)
    expect(w.text().toLowerCase()).not.toContain('control (')
  })
  it('sin el campo (la API lo quita) no hay fila ni siquiera para un aprobador', async () => {
    const w = await mountDetail('owner', recommendation())
    expect(w.find('[data-test="experiment-group"]').exists()).toBe(false)
  })
})

describe('Detalle: acciones del workflow por habilidad', () => {
  const rec = () => recommendation({ allowed_transitions: [TRANSITIONS.assign!, TRANSITIONS.dismiss!, TRANSITIONS.approve!, TRANSITIONS.reject!] })

  it('un viewer ve la recomendación pero no puede actuar', async () => {
    const w = await mountDetail('viewer', rec())
    expect(actions(w)).toEqual([])
    expect(w.get('[data-test="read-only"]').text()).toContain('no actuar')
  })

  it('un analista gestiona (asignar, descartar) pero no aprueba ni rechaza formalmente', async () => {
    const w = await mountDetail('analyst', rec())
    expect(actions(w)).toEqual(['assign', 'dismiss'])
  })

  it.each(['admin', 'owner'] as const)('%s también aprueba y rechaza', async (role) => {
    const w = await mountDetail(role, rec())
    expect(actions(w)).toEqual(['assign', 'dismiss', 'approve', 'reject'])
  })

  it('una acción bloqueada por el servidor (segregación) se ofrece desactivada con su motivo', async () => {
    const w = await mountDetail('admin', recommendation({ status: 'reviewed', allowed_transitions: [transition({ action: 'approve', to_status: 'approved', ability: 'decisions.approve', allowed: false, blocked_by: 'decision_segregation_of_duties', requires: { assignee: false, reason: false, expected_version: true } })] }))
    const button = w.get('[data-action="approve"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(w.get('[data-test="blocked"]').text()).toContain('segregación de funciones')
  })

  it('una acción sensible sin aprobación previa se explica', async () => {
    const w = await mountDetail('analyst', recommendation({ status: 'reviewed', allowed_transitions: [transition({ action: 'start_without_approval', to_status: 'in_progress', allowed: false, blocked_by: 'decision_approval_required', requires: { assignee: false, reason: false, expected_version: true } })] }))
    expect(w.get('[data-test="blocked"]').text()).toContain('necesita aprobación')
  })

  it('sin versión (no se puede garantizar el bloqueo optimista) no se ofrece actuar', async () => {
    const w = await mountDetail('analyst', recommendation({ version: null }))
    expect(w.get('[data-action="assign"]').attributes('disabled')).toBeDefined()
  })
})

describe('Detalle: la transición lleva expected_version e idempotency_key y atiende 403/409/422', () => {
  const confirm = async (w: Awaited<ReturnType<typeof mountDetail>>, action: string, fill?: (w: Awaited<ReturnType<typeof mountDetail>>) => Promise<void>) => {
    await w.get(`[data-action="${action}"]`).trigger('click')
    await flushPromises()
    await fill?.(w)
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
  }

  it('asignar manda to_status, expected_version (la leída), una clave nueva y el responsable; muestra el resultado', async () => {
    const assigned = recommendation({ status: 'assigned', assigned_user_id: 7, version: 78, allowed_transitions: [TRANSITIONS.review!] })
    api.transition.mockResolvedValue(ok(assigned))
    const w = await mountDetail('analyst')
    await confirm(w, 'assign')
    expect(api.transition).toHaveBeenCalledTimes(1)
    const [id, body] = api.transition.mock.calls[0]!
    expect(id).toBe(21)
    expect(body).toMatchObject({ to_status: 'assigned', expected_version: 77, assignee_user_id: 7 })
    expect(body.idempotency_key).toMatch(KEY_RULE)
    expect(w.get('[data-test="done-notice"]').text()).toContain('Acción registrada')
    expect(actions(w)).toEqual(['review'])
    expect(w.text()).toContain('Asignada')
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('la siguiente acción usa la versión nueva y una clave distinta', async () => {
    api.transition
      .mockResolvedValueOnce(ok(recommendation({ status: 'assigned', version: 78, allowed_transitions: [TRANSITIONS.review!] })))
      .mockResolvedValueOnce(ok(recommendation({ status: 'reviewed', version: 79, allowed_transitions: [] })))
    const w = await mountDetail('analyst')
    await confirm(w, 'assign')
    await confirm(w, 'review')
    const [first, second] = api.transition.mock.calls.map((c) => c[1])
    expect(first.expected_version).toBe(77)
    expect(second.expected_version).toBe(78)
    expect(second.to_status).toBe('reviewed')
    expect(first.idempotency_key).toMatch(KEY_RULE)
    expect(second.idempotency_key).toMatch(KEY_RULE)
    expect(second.idempotency_key).not.toBe(first.idempotency_key)
  })

  it('S02: tras un éxito la clave se descarta aunque la acción siguiente tenga el mismo cuerpo (misma versión): nunca se reutiliza una clave ya resuelta', async () => {
    const same = () => ok(recommendation({ status: 'suggested', version: 77, allowed_transitions: [TRANSITIONS.assign!, TRANSITIONS.dismiss!] }))
    api.transition.mockResolvedValueOnce(same()).mockResolvedValueOnce(same())
    const w = await mountDetail('analyst')
    await confirm(w, 'assign')
    await confirm(w, 'assign')
    const [first, second] = api.transition.mock.calls.map((c) => c[1])
    expect(second.expected_version).toBe(first.expected_version)
    expect(second.assignee_user_id).toBe(first.assignee_user_id)
    expect(second.idempotency_key).not.toBe(first.idempotency_key)
  })

  it('descartar exige un motivo de al menos 5 caracteres y no viaja sin él', async () => {
    api.transition.mockResolvedValue(ok(recommendation({ status: 'rejected', allowed_transitions: [] })))
    const w = await mountDetail('analyst')
    await confirm(w, 'dismiss')
    expect(api.transition).not.toHaveBeenCalled()
    expect(w.get('[data-test="transition-dialog"]').text()).toContain('Escriba un motivo de al menos 5 caracteres')
    await w.get('#tr-reason').setValue('no')
    await w.get('[data-test="confirm-transition"]').trigger('click')
    expect(api.transition).not.toHaveBeenCalled()
    await w.get('#tr-reason').setValue('El comprador cerró su negocio')
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    expect(api.transition.mock.calls[0]![1]).toMatchObject({ to_status: 'rejected', reason: 'El comprador cerró su negocio', expected_version: 77 })
  })

  it('un administrador puede asignar a otro usuario con permiso de gestión (no a un viewer)', async () => {
    api.users.mockResolvedValue([
      { id: 7, name: 'Ana', email: 'a@x', role: 'admin', joined_at: null },
      { id: 8, name: 'Beto', email: 'b@x', role: 'analyst', joined_at: null },
      { id: 9, name: 'Cata', email: 'c@x', role: 'viewer', joined_at: null },
    ])
    api.transition.mockResolvedValue(ok(recommendation({ status: 'assigned', version: 78 })))
    const w = await mountDetail('admin')
    await w.get('[data-action="assign"]').trigger('click')
    await flushPromises()
    const options = w.findAll('#tr-assignee option').map((o) => o.text())
    expect(options).toEqual(['Asignarme a mí', 'Beto'])
    await w.get('#tr-assignee').setValue(8)
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    expect(api.transition.mock.calls[0]![1].assignee_user_id).toBe(8)
  })

  it('409 de versión: recarga el detalle, cierra el cuadro y avisa que otra persona lo cambió', async () => {
    api.transition.mockRejectedValue(new ApiError(409, { message: 'La recomendación cambió', code: 'decision_version_conflict', context: { current_version: 90 } } as never, 'x'))
    const w = await mountDetail('analyst')
    api.show.mockResolvedValue(recommendation({ status: 'assigned', assigned_user_id: 8, version: 90, allowed_transitions: [TRANSITIONS.review!] }))
    await confirm(w, 'assign')
    expect(api.show).toHaveBeenCalledTimes(2)
    expect(w.get('[data-test="conflict-notice"]').text()).toContain('Otra persona cambió esta recomendación')
    expect(w.find('[data-test="transition-dialog"]').exists()).toBe(false)
    expect(actions(w)).toEqual(['review'])
    expect(w.emitted('changed')).toBeTruthy()
  })

  it('403 sin habilidad: el cuadro sigue abierto con un mensaje claro', async () => {
    api.transition.mockRejectedValue(new ApiError(403, { message: 'x', code: 'forbidden', required_ability: 'decisions.manage' } as never, 'x'))
    const w = await mountDetail('analyst')
    await confirm(w, 'assign')
    expect(w.get('[data-test="transition-dialog"] [data-test="action-error"]').attributes('data-kind')).toBe('forbidden')
    expect(w.get('[data-test="transition-dialog"]').text()).toContain('no tiene permiso')
  })

  it('403 por segregación de funciones nombra la función que lo impide', async () => {
    api.transition.mockRejectedValue(new ApiError(403, { message: 'x', code: 'decision_segregation_of_duties', context: { roles: ['proposer', 'executor'] } } as never, 'x'))
    const w = await mountDetail('admin', recommendation({ status: 'reviewed', allowed_transitions: [TRANSITIONS.approve!] }))
    await confirm(w, 'approve')
    expect(w.get('[data-test="action-error"]').text()).toContain('quien la propuso, quien debe ejecutarla')
  })

  it('409 de aprobación pendiente y 422 de validación', async () => {
    api.transition.mockRejectedValueOnce(new ApiError(409, { message: 'x', code: 'decision_approval_required' } as never, 'x'))
    const w = await mountDetail('analyst')
    await confirm(w, 'assign')
    expect(w.get('[data-test="action-error"]').text()).toContain('exige la aprobación de otra persona')
    api.transition.mockRejectedValueOnce(new ApiError(422, { message: 'Indique expected_version.', errors: { expected_version: ['Indique expected_version.'] } } as never, 'x'))
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="action-error"]').attributes('data-kind')).toBe('validation')
  })

  it('una transición ya no permitida (409) recarga el estado vigente', async () => {
    api.transition.mockRejectedValue(new ApiError(409, { message: 'x', code: 'decision_transition_not_allowed' } as never, 'x'))
    const w = await mountDetail('analyst')
    await confirm(w, 'assign')
    expect(api.show).toHaveBeenCalledTimes(2)
  })

  it('tras una caída de red el reintento conserva la clave de idempotencia', async () => {
    api.transition.mockRejectedValueOnce(new ApiError(0, null, 'sin red')).mockResolvedValueOnce(ok(recommendation({ status: 'assigned', version: 78 })))
    const w = await mountDetail('analyst')
    await confirm(w, 'assign')
    expect(w.get('[data-test="action-error"]').attributes('data-kind')).toBe('network')
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    const [a, b] = api.transition.mock.calls.map((c) => c[1].idempotency_key)
    expect(b).toBe(a)
  })

  it('un reintento idempotente del servidor se avisa y no se presenta como una acción nueva', async () => {
    api.transition.mockResolvedValue(ok(recommendation({ status: 'assigned', version: 78 }), true))
    const w = await mountDetail('analyst')
    await confirm(w, 'assign')
    expect(w.get('[data-test="replay-notice"]').text()).toContain('ya estaba registrada')
    expect(w.find('[data-test="done-notice"]').exists()).toBe(false)
  })

  it('explica que registrar no envía mensajes ni escribe en el ERP', async () => {
    const w = await mountDetail('analyst')
    await w.get('[data-action="assign"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="transition-dialog"]').text()).toContain('No envía mensajes ni escribe en el ERP')
  })
})

describe('Detalle: una acción a la vez, sin cerrar el cuadro en vuelo y con clave propia (M5, L1, L2)', () => {
  type W = Awaited<ReturnType<typeof mountDetail>>
  const open = async (w: W, action: string) => {
    await w.get(`[data-action="${action}"]`).trigger('click')
    await flushPromises()
  }
  const escape = () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))

  it('M5 y S14: con la petición en vuelo el botón queda desactivado, el cuadro no se cierra (ni con Escape ni con la X) y el error no se pierde', async () => {
    let fail: (e: unknown) => void = () => undefined
    api.transition.mockImplementation(() => new Promise((_, reject) => { fail = reject }))
    const w = await mountDetail('analyst')
    await open(w, 'assign')
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="confirm-transition"]').attributes('disabled')).toBeDefined()
    expect(w.find('[aria-label="Cerrar"]').exists()).toBe(false)
    escape()
    await flushPromises()
    expect(w.find('[data-test="transition-dialog"]').exists()).toBe(true)
    fail(new ApiError(403, { message: 'x', code: 'forbidden' } as never, 'x'))
    await flushPromises()
    expect(w.get('[data-test="transition-dialog"] [data-test="action-error"]').attributes('data-kind')).toBe('forbidden')
    expect(w.get('[data-test="confirm-transition"]').attributes('disabled')).toBeUndefined()
    expect(w.find('[aria-label="Cerrar"]').exists()).toBe(true) // terminada la petición, ya se puede cerrar
    escape()
    await flushPromises()
    expect(w.find('[data-test="transition-dialog"]').exists()).toBe(false)
  })

  it('L1: un segundo evento de confirmación con la primera petición en vuelo no envía nada más', async () => {
    api.transition.mockImplementation(() => new Promise(() => undefined))
    const w = await mountDetail('analyst')
    await open(w, 'assign')
    const dialog = w.findComponent(TransitionDialog)
    dialog.vm.$emit('confirm', { reason: '', assigneeUserId: 7 })
    dialog.vm.$emit('confirm', { reason: '', assigneeUserId: 7 })
    await flushPromises()
    expect(api.transition).toHaveBeenCalledTimes(1)
  })

  it('L2: tras una caída de red se recarga antes de otra acción; si la anterior ya se aplicó, se cierra el cuadro y se avisa (no se repite)', async () => {
    api.transition.mockRejectedValue(new ApiError(0, null, 'sin red'))
    const w = await mountDetail('analyst')
    api.show.mockResolvedValue(recommendation({ status: 'assigned', assigned_user_id: 7, version: 78, allowed_transitions: [TRANSITIONS.review!] }))
    await open(w, 'assign')
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    expect(api.show).toHaveBeenCalledTimes(2)
    expect(w.find('[data-test="transition-dialog"]').exists()).toBe(false)
    expect(w.get('[data-test="applied-notice"]').text()).toContain('ya figura registrada')
    expect(api.transition).toHaveBeenCalledTimes(1)
    expect(actions(w)).toEqual(['review'])
  })

  it('L2: si la acción sigue disponible el cuadro queda con el error de red; un reintento sobre la misma versión conserva la clave, sobre otra versión usa una nueva', async () => {
    api.transition.mockRejectedValue(new ApiError(0, null, 'sin red'))
    const w = await mountDetail('analyst')
    await open(w, 'assign')
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="action-error"]').attributes('data-kind')).toBe('network')
    expect(api.show).toHaveBeenCalledTimes(2) // recarga antes de permitir otra acción
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    const [first, same] = api.transition.mock.calls.map((c) => c[1])
    expect(same.expected_version).toBe(first.expected_version)
    expect(same.idempotency_key).toBe(first.idempotency_key)
    // Otra persona cambió algo entre tanto: la versión leída es otra y la clave también.
    api.show.mockResolvedValue(recommendation({ version: 80 }))
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    const third = api.transition.mock.calls[2]![1]
    expect(third.expected_version).toBe(77)
    api.transition.mockRejectedValue(new ApiError(0, null, 'sin red'))
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    const fourth = api.transition.mock.calls[3]![1]
    expect(fourth.expected_version).toBe(80)
    expect(fourth.idempotency_key).not.toBe(first.idempotency_key)
  })

  it('L2: una acción distinta nunca reutiliza la clave pendiente de otra', async () => {
    api.transition.mockRejectedValue(new ApiError(0, null, 'sin red'))
    const w = await mountDetail('analyst')
    await open(w, 'assign')
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    const assignKey = api.transition.mock.calls[0]![1].idempotency_key
    await w.findAll('button').find((b) => b.text() === 'Cancelar')!.trigger('click')
    await flushPromises()
    await open(w, 'dismiss')
    await w.get('#tr-reason').setValue('El comprador cerró su negocio')
    await w.get('[data-test="confirm-transition"]').trigger('click')
    await flushPromises()
    const dismiss = api.transition.mock.calls[1]![1]
    expect(dismiss.to_status).toBe('rejected')
    expect(dismiss.idempotency_key).not.toBe(assignKey)
  })
})

describe('Detalle: restricciones con su texto (L5)', () => {
  it('muestra la etiqueta de cada código del backend, la de las del plan de compras (por familia) y, sin texto, el código tal cual', async () => {
    const w = await mountDetail('analyst', recommendation({ constraints: [
      { code: 'window_without_figures' }, { code: 'overstock_without_demand' }, { code: 'demand_exceeds_availability' }, { code: 'over_budget' }, { family: 'supplier_capacity', ref: 'o1' }, { code: 'codigo_nuevo' },
    ] }))
    expect(w.findAll('[data-test="constraint"]').map((c) => c.text())).toEqual([
      'Ventana de recompra sin cifras', 'Exceso de inventario sin demanda', 'Demanda mayor que lo disponible', 'Sobre presupuesto', 'Capacidad del proveedor', 'codigo_nuevo',
    ])
  })
})

describe('Lista de decisiones', () => {
  const page = (rows: DecisionRecommendation[]) => ({ data: rows, meta: { current_page: 1, per_page: 20, total: rows.length, last_page: 1 } })
  const mountList = async (rows: DecisionRecommendation[] = [recommendation()]) => {
    api.list.mockResolvedValue(page(rows))
    const w = mount(DecisionList, { props: { selectedId: null }, global: { plugins: [tenantAs('analyst')] } })
    await flushPromises()
    return w
  }

  it('pide por defecto las recomendaciones en trabajo y nunca incluye el grupo de control', async () => {
    await mountList()
    const params = api.list.mock.calls[0]![0]
    expect(params.status).toBe('suggested,assigned,reviewed,approved,in_progress')
    expect(params.sort).toBe('-score')
    expect(params).not.toHaveProperty('include_control')
  })

  it('muestra contribución como «No estimada» cuando es nula y rotula la atribución', async () => {
    const w = await mountList([recommendation({ expected_contribution: null })])
    expect(w.text()).toContain('No estimada')
    expect(w.text()).toContain('Oportunidad potencial, atribución no demostrada')
    expect(w.text()).toContain('Contacto #21')
  })

  it('los filtros viajan al servidor y vuelven a la primera página', async () => {
    const w = await mountList()
    await w.get('#dl-kind').setValue('replenishment')
    await w.get('[data-test="filter-mine"]').setValue(true)
    await w.get('#dl-approval').setValue('yes')
    await flushPromises()
    const last = api.list.mock.calls.at(-1)![0]
    expect(last).toMatchObject({ kind: 'replenishment', mine: true, requires_approval: true, page: 1 })
  })

  it('seleccionar una fila emite su id', async () => {
    const w = await mountList()
    await w.get('[data-test="select-decision"]').trigger('click')
    expect(w.emitted('select')![0]).toEqual([21])
  })

  it('la lista no muestra el grupo del experimento', async () => {
    const w = await mountList([recommendation({ experiment_group: 'treatment' })])
    const text = w.text().toLowerCase()
    expect(text).not.toContain('treatment')
    expect(text).not.toContain('tratamiento')
    expect(text).not.toContain('control')
  })
})

describe('Resumen de decisiones', () => {
  it('muestra conteos por estado y la nota de atribución, sin sumar importes ni revelar el grupo de control', () => {
    const w = mount(DecisionSummaryCards, { props: { summary: decisionSummary({ control_group_units: 9 }) }, global: { plugins: [tenantAs('admin')] } })
    expect(w.text()).toContain('Por asignar')
    expect(w.get('[data-test="status-chips"]').text()).toContain('Sugerida: 5')
    expect(w.get('[data-test="summary-attribution"]').text()).toContain('atribución no demostrada')
    const text = w.text().toLowerCase()
    expect(text).not.toContain('control')
    expect(text).not.toContain('9')
    expect(text).not.toMatch(/us\$|\$/)
  })
})
