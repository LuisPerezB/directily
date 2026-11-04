import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'
import es from '@/locales/es.json'
import { ApiError } from '@/api/errors'
import type { DecisionAllowedTransition } from '@/api/types'
import { roleCan, type Ability } from '../abilities'
import {
  EXPLANATION_CODES, KNOWN_CONSTRAINT_CODES, MIN_REASON_LENGTH, actionScope, attemptIsUnresolved, buildTransitionBody, classifyActionError, constraintKey, createKeyHolder,
  newIdempotencyKey, segregationRoleKey, transitionDisposition, transitionInputError,
} from '../decisions'

const KEY_RULE = /^[A-Za-z0-9._:-]{8,120}$/
const tr = (over: Partial<DecisionAllowedTransition> = {}): DecisionAllowedTransition => ({
  action: 'review', to_status: 'reviewed', ability: 'decisions.manage', requires: { assignee: false, reason: false, expected_version: true }, allowed: true, blocked_by: null, ...over,
})
const err = (status: number, body: Record<string, unknown> | null) => new ApiError(status, body as never, 'fallo')

describe('idempotency_key', () => {
  it('cumple la regla del servidor y es distinta en cada acción', () => {
    const a = newIdempotencyKey('decision')
    const b = newIdempotencyKey('decision')
    expect(a).toMatch(KEY_RULE)
    expect(b).toMatch(KEY_RULE)
    expect(a).not.toBe(b)
  })

  it('usa crypto.randomUUID cuando existe', () => {
    const spy = vi.spyOn(globalThis.crypto, 'randomUUID').mockReturnValue('11111111-2222-3333-4444-555555555555')
    expect(newIdempotencyKey('decision')).toBe('decision-11111111-2222-3333-4444-555555555555')
    spy.mockRestore()
  })

  it('L2: la clave es de una acción concreta: otra acción, otra versión u otro cuerpo nunca reutilizan una clave pendiente', () => {
    const holder = createKeyHolder('x')
    const body = { to_status: 'assigned' as const, expected_version: 77, reason: undefined, assignee_user_id: 7 }
    const first = holder.take(actionScope(21, body))
    expect(holder.take(actionScope(21, { ...body }))).toBe(first) // el mismo reintento conserva la clave
    expect(holder.take(actionScope(21, { ...body, to_status: 'rejected' as const }))).not.toBe(first) // otra transición
    const second = holder.take(actionScope(21, { ...body, to_status: 'rejected' as const }))
    expect(holder.take(actionScope(21, { ...body, to_status: 'rejected' as const, expected_version: 78 }))).not.toBe(second) // la versión cambió
    expect(actionScope(21, body)).not.toBe(actionScope(22, body)) // otra recomendación
    expect(actionScope(21, body)).not.toBe(actionScope(21, { ...body, assignee_user_id: 8 })) // otro responsable
  })

  it('se conserva mientras el intento no se resuelve (red caída) y se renueva con una respuesta definitiva', () => {
    const holder = createKeyHolder('x')
    const first = holder.take()
    expect(holder.take()).toBe(first)
    holder.settle()
    const second = holder.take()
    expect(second).not.toBe(first)
    expect(second).toMatch(KEY_RULE)
  })

  it('un intento queda sin resolver con red caída o error del servidor; un 4xx es definitivo', () => {
    expect(attemptIsUnresolved(err(0, null))).toBe(true)
    expect(attemptIsUnresolved(err(503, null))).toBe(true)
    expect(attemptIsUnresolved(err(409, { code: 'decision_version_conflict' }))).toBe(false)
    expect(attemptIsUnresolved(err(422, null))).toBe(false)
    expect(attemptIsUnresolved(new Error('x'))).toBe(true)
  })
})

describe('cuerpo de la transición', () => {
  it('siempre lleva expected_version (la versión leída) e idempotency_key', () => {
    const body = buildTransitionBody({ version: 42 }, tr(), {}, 'decision-abc12345')
    expect(body).toEqual({ to_status: 'reviewed', idempotency_key: 'decision-abc12345', expected_version: 42 })
  })

  it('asignar manda el responsable; rechazar, el motivo recortado', () => {
    const assign = buildTransitionBody({ version: 3 }, tr({ action: 'assign', to_status: 'assigned', requires: { assignee: true, reason: false, expected_version: true } }), { assigneeUserId: 9 }, 'k-12345678')
    expect(assign.assignee_user_id).toBe(9)
    const reject = buildTransitionBody({ version: 3 }, tr({ action: 'reject', to_status: 'rejected', ability: 'decisions.approve', requires: { assignee: false, reason: true, expected_version: true } }), { reason: '  sin presupuesto  ' }, 'k-12345678')
    expect(reject.reason).toBe('sin presupuesto')
    expect(reject.assignee_user_id).toBeUndefined()
  })

  it('sin versión no se actúa: hay que recargar', () => {
    expect(() => buildTransitionBody({ version: null }, tr(), {}, 'k-12345678')).toThrow()
    expect(transitionDisposition(tr(), () => true, { version: null })).toBe('disabled')
  })

  it('valida lo obligatorio antes de viajar: motivo mínimo y responsable', () => {
    const reject = tr({ action: 'reject', requires: { assignee: false, reason: true, expected_version: true } })
    expect(transitionInputError(reject, { reason: 'no' })).toBe('reason')
    expect(transitionInputError(reject, { reason: 'x'.repeat(MIN_REASON_LENGTH) })).toBeNull()
    const assign = tr({ action: 'assign', requires: { assignee: true, reason: false, expected_version: true } })
    expect(transitionInputError(assign, {})).toBe('assignee')
    expect(transitionInputError(assign, { assigneeUserId: 4 })).toBeNull()
  })
})

describe('botones por habilidad (el servidor sigue siendo la autoridad)', () => {
  const approve = tr({ action: 'approve', to_status: 'approved', ability: 'decisions.approve' })
  it.each([
    ['viewer', 'review', 'hidden'], ['analyst', 'review', 'enabled'], ['analyst', 'approve', 'hidden'], ['admin', 'approve', 'enabled'], ['owner', 'approve', 'enabled'], ['viewer', 'approve', 'hidden'],
  ] as const)('%s → %s: %s', (role, action, expected) => {
    const can = (a: Ability) => roleCan(role, a)
    expect(transitionDisposition(action === 'approve' ? approve : tr(), can)).toBe(expected)
  })

  it('con la habilidad pero bloqueada por el servidor (segregación) se ofrece desactivada', () => {
    expect(transitionDisposition({ ...approve, allowed: false, blocked_by: 'decision_segregation_of_duties' }, (a) => roleCan('admin', a))).toBe('disabled')
  })

  it('una habilidad desconocida para la réplica no se ofrece', () => {
    expect(transitionDisposition(tr({ ability: 'otra.habilidad' }), (a) => roleCan('owner', a))).toBe('hidden')
  })
})

describe('errores del servidor', () => {
  it('403 sin habilidad y 403 por segregación de funciones (con las funciones, sin identificadores)', () => {
    expect(classifyActionError(err(403, { code: 'forbidden', required_ability: 'decisions.approve' }))).toMatchObject({ kind: 'forbidden', requiredAbility: 'decisions.approve' })
    expect(classifyActionError(err(403, { code: 'decision_segregation_of_duties', context: { roles: ['proposer', 'executor'] } }))).toMatchObject({ kind: 'segregation', roles: ['proposer', 'executor'] })
    expect(classifyActionError(err(403, { code: 'approval_segregation_of_duties', context: { roles: ['published_by'] } }))).toMatchObject({ kind: 'segregation' })
  })

  it('409: versión desfasada, transición no permitida, aprobación pendiente, control y clave reutilizada', () => {
    expect(classifyActionError(err(409, { code: 'decision_version_conflict', context: { current_version: 9 } })).kind).toBe('version_conflict')
    expect(classifyActionError(err(409, { code: 'decision_transition_not_allowed' })).kind).toBe('not_allowed')
    expect(classifyActionError(err(409, { code: 'model_transition_not_allowed' })).kind).toBe('not_allowed')
    expect(classifyActionError(err(409, { code: 'decision_approval_required' })).kind).toBe('approval_required')
    expect(classifyActionError(err(409, { code: 'control_group_not_actionable' })).kind).toBe('control')
    expect(classifyActionError(err(409, { code: 'idempotency_key_reused' })).kind).toBe('key_reused')
    expect(classifyActionError(err(409, { code: 'otro' })).kind).toBe('conflict')
  })

  it('422, 404, red y servidor', () => {
    expect(classifyActionError(err(422, { code: 'snooze_beyond_expiry', errors: { snoozed_until: ['x'] } }))).toMatchObject({ kind: 'validation', code: 'snooze_beyond_expiry', fieldErrors: { snoozed_until: ['x'] } })
    expect(classifyActionError(err(404, null)).kind).toBe('not_found')
    expect(classifyActionError(err(0, null)).kind).toBe('network')
    expect(classifyActionError(err(500, null)).kind).toBe('server')
    expect(classifyActionError(new Error('boom')).kind).toBe('unknown')
  })

  it('las funciones de segregación se traducen a una clave de texto', () => {
    expect(segregationRoleKey('training_job.user_id')).toBe('training_job_user_id')
    expect(segregationRoleKey('published_by')).toBe('published_by')
  })
})

describe('L5: toda restricción que el backend puede emitir tiene su texto', () => {
  const constraintLabels = (es as unknown as { decisions: { constraint: Record<string, string> } }).decisions.constraint
  const families = (es as unknown as { supply: { families: Record<string, string> } }).supply.families

  it('cada código conocido tiene una etiqueta en español', () => {
    const missing = KNOWN_CONSTRAINT_CODES.filter((c) => !constraintLabels[c])
    expect(missing).toEqual([])
    for (const c of KNOWN_CONSTRAINT_CODES) expect(constraintLabels[c]).not.toBe(c)
  })

  it('las restricciones del plan de compras (por familia) tienen etiqueta', () => {
    for (const family of ['budget', 'supplier_capacity', 'storage_capacity', 'resource_capacity', 'minimum_order']) expect(families[family], family).toBeTruthy()
  })

  it('una restricción se identifica por su código o, en las del plan de compras, por su familia', () => {
    expect(constraintKey({ code: 'over_budget' })).toEqual({ kind: 'code', key: 'over_budget' })
    expect(constraintKey({ family: 'budget', ref: 1 })).toEqual({ kind: 'family', key: 'budget' })
    expect(constraintKey({ note: 'x' })).toBeNull()
  })

  // Recorre los servicios del Decision Engine y del Radar (solo lectura): un código nuevo que no sea una explicación debe traer su etiqueta o esta prueba falla.
  const root = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../backend/app/Services')
  const phpFiles = (dir: string): string[] => readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? phpFiles(path) : path.endsWith('.php') ? [path] : []
  })
  it.skipIf(!existsSync(join(root, 'Decision')))('ningún código de los servicios Decision y Radar queda sin etiqueta ni declarado como explicación', () => {
    const codes = new Set<string>()
    for (const dir of ['Decision', 'Radar']) {
      for (const file of phpFiles(join(root, dir))) {
        for (const m of readFileSync(file, 'utf8').matchAll(/'code'\s*=>\s*'([a-z_]+)'/g)) codes.add(m[1]!)
      }
    }
    expect(codes.size).toBeGreaterThan(5) // la búsqueda encontró los códigos conocidos
    const unlabelled = [...codes].filter((c) => !(EXPLANATION_CODES as readonly string[]).includes(c) && !constraintLabels[c])
    expect(unlabelled).toEqual([])
  })
})
