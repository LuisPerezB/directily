import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { setupTenant } from '@/test/helpers'
import { roiReport } from '@/test/enterprise'
import { ApiError } from '@/api/errors'
import type { ModelRegistryEntry, TenantRole } from '@/api/types'
import { useAuthStore } from '@/stores/auth'

const api = vi.hoisted(() => ({ models: vi.fn(), show: vi.fn(), validate: vi.fn(), approve: vi.fn(), reject: vi.fn(), promote: vi.fn(), roi: vi.fn(), usage: vi.fn() }))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  accuracyApi: { models: api.models },
  modelLifecycleApi: { show: api.show, validate: api.validate, approve: api.approve, reject: api.reject, promote: api.promote },
  outcomeApi: { roi: api.roi, list: vi.fn() },
  tenantApi: { usage: api.usage },
}))

import ModelApprovals from '../admin/ModelApprovals.vue'
import ModelActionDialog from '../admin/ModelActionDialog.vue'
import UsageAndCosts from '../admin/UsageAndCosts.vue'

enableAutoUnmount(afterEach)
const tenantAs = (role: TenantRole) => {
  const pinia = setupTenant(role)
  useAuthStore().user = { id: 7, name: 'Ana', email: 'a@x.test', locale: 'es', is_superadmin: false }
  return pinia
}
beforeEach(() => { Object.values(api).forEach((m) => m.mockReset()) })

const model = (over: Partial<ModelRegistryEntry> = {}): ModelRegistryEntry => ({
  id: 1, version: 'v20261101-a', model_family: 'lightgbm_global', label: 'LightGBM global', strategy_level: 'product', status: 'validated', serving_basis: 'not_approved', core: 'demand', role: 'primary',
  demand_class: null, trained_at: '2026-10-30T10:00:00-05:00', training_data_from: '2025-01-01', training_data_to: '2026-10-01', series_count: 40, published_by: 9, published_at: '2026-10-31T09:00:00-05:00', approved_by: null, approved_at: null, rejected_reason: null, ...over,
})
const page = (rows: ModelRegistryEntry[]) => ({ data: rows, meta: { current_page: 1, per_page: 15, total: rows.length, last_page: 1 } })
const mountModels = async (role: TenantRole, rows: ModelRegistryEntry[]) => {
  api.models.mockResolvedValue(page(rows))
  const w = mount(ModelApprovals, { global: { plugins: [tenantAs(role)] } })
  await flushPromises()
  return w
}
const actionsOf = (w: Awaited<ReturnType<typeof mountModels>>, row = 0) => w.findAll('tbody tr')[row]!.findAll('[data-action]').map((b) => b.attributes('data-action'))

describe('Modelos y aprobaciones: acciones por estado y habilidad', () => {
  const all = [model({ id: 1, status: 'candidate' }), model({ id: 2, status: 'validated' }), model({ id: 3, status: 'approved', approved_by: 4, approved_at: '2026-11-01T10:00:00-05:00' }), model({ id: 4, status: 'production' }), model({ id: 5, status: 'rejected', rejected_reason: 'Sin calidad' })]

  it('un administrador: validar/rechazar un candidato, aprobar/rechazar un validado, promover/rechazar un aprobado; nada en producción ni rechazado', async () => {
    const w = await mountModels('admin', all)
    expect(actionsOf(w, 0)).toEqual(['validate', 'reject'])
    expect(actionsOf(w, 1)).toEqual(['approve', 'reject'])
    expect(actionsOf(w, 2)).toEqual(['reject', 'promote'])
    expect(actionsOf(w, 3)).toEqual([])
    expect(actionsOf(w, 4)).toEqual([])
  })

  it('un analista solo puede validar (forecast.run); no aprueba, rechaza ni promueve', async () => {
    const w = await mountModels('analyst', all)
    expect(actionsOf(w, 0)).toEqual(['validate'])
    expect(actionsOf(w, 1)).toEqual([])
    expect(actionsOf(w, 2)).toEqual([])
  })

  it('un viewer no puede actuar sobre ningún modelo, pero sí ver su historial', async () => {
    const w = await mountModels('viewer', all)
    for (let i = 0; i < all.length; i++) expect(actionsOf(w, i)).toEqual([])
    expect(w.findAll('[data-test="model-history"]')).toHaveLength(all.length)
  })

  it('quien publicó el modelo no puede aprobarlo ni rechazarlo: botones desactivados con su motivo (el servidor lo hace cumplir igual)', async () => {
    const w = await mountModels('admin', [model({ published_by: 7 })])
    expect(w.get('[data-action="approve"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-action="reject"]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-action="approve"]').attributes('title')).toContain('Usted publicó este modelo')
  })

  it('muestra quién publicó y quién aprobó, y la base de servicio', async () => {
    const w = await mountModels('admin', [model({ status: 'approved', approved_by: 7, approved_at: '2026-11-01T10:00:00-05:00', serving_basis: 'approved' })])
    expect(w.text()).toContain('Usuario 9')
    expect(w.text()).toContain('Usted')
    expect(w.get('[data-test="serving-basis"]').text()).toContain('aprobado por otra persona')
  })

  it('el estado «no aprobado» se dice: no se sirve', async () => {
    const w = await mountModels('admin', [model()])
    expect(w.get('[data-test="serving-basis"]').text()).toContain('No se sirve')
    expect(w.text()).toContain('Sin aprobación')
  })
})

describe('Modelos y aprobaciones: confirmación y errores del servidor', () => {
  const open = async (w: Awaited<ReturnType<typeof mountModels>>, action: string) => {
    await w.get(`[data-action="${action}"]`).trigger('click')
    await flushPromises()
  }
  const confirm = async (w: Awaited<ReturnType<typeof mountModels>>) => {
    await w.get('[data-test="confirm-model-action"]').trigger('click')
    await flushPromises()
  }

  it('aprobar pide confirmación, llama al servidor, recarga la lista y avisa', async () => {
    api.approve.mockResolvedValue({})
    const w = await mountModels('admin', [model()])
    await open(w, 'approve')
    expect(api.approve).not.toHaveBeenCalled()
    expect(w.get('[data-test="model-dialog"]').text()).toContain('La aprobación es separada')
    await confirm(w)
    expect(api.approve).toHaveBeenCalledWith(1, undefined)
    expect(api.models).toHaveBeenCalledTimes(2)
    expect(w.get('[data-test="model-done"]').text()).toBe('Modelo aprobado.')
    expect(w.find('[data-test="model-dialog"]').exists()).toBe(false)
  })

  it('rechazar exige un motivo y lo envía', async () => {
    api.reject.mockResolvedValue({})
    const w = await mountModels('admin', [model()])
    await open(w, 'reject')
    await confirm(w)
    expect(api.reject).not.toHaveBeenCalled()
    expect(w.get('[data-test="model-dialog"]').text()).toContain('Escriba un motivo de al menos 5 caracteres')
    await w.get('#ma-reason').setValue('Calidad insuficiente en el holdout')
    await confirm(w)
    expect(api.reject).toHaveBeenCalledWith(1, 'Calidad insuficiente en el holdout')
  })

  it('validar y promover usan sus endpoints', async () => {
    api.validate.mockResolvedValue({})
    api.promote.mockResolvedValue({})
    const candidate = await mountModels('admin', [model({ status: 'candidate' })])
    await open(candidate, 'validate')
    await confirm(candidate)
    expect(api.validate).toHaveBeenCalledWith(1, undefined)
    expect(candidate.find('[data-test="model-dialog"]').exists()).toBe(false)
    const approved = await mountModels('admin', [model({ status: 'approved', approved_by: 4 })])
    await open(approved, 'promote')
    await approved.get('#ma-reason').setValue('Pasa a producción')
    await confirm(approved)
    expect(api.promote).toHaveBeenCalledWith(1, 'Pasa a producción')
  })

  it('403 de segregación de funciones: nombra la función y deja el cuadro abierto', async () => {
    api.approve.mockRejectedValue(new ApiError(403, { message: 'x', code: 'approval_segregation_of_duties', context: { roles: ['published_by', 'training_job.user_id'] } } as never, 'x'))
    const w = await mountModels('admin', [model()])
    await open(w, 'approve')
    await confirm(w)
    const error = w.get('[data-test="model-dialog"] [data-test="action-error"]')
    expect(error.attributes('data-kind')).toBe('segregation')
    expect(error.text()).toContain('quien publicó el modelo, quien disparó su entrenamiento')
  })

  it('403 sin habilidad y 409 de estado: mensajes claros; el 409 recarga la lista', async () => {
    api.approve.mockRejectedValueOnce(new ApiError(403, { message: 'x', code: 'forbidden', required_ability: 'models.approve' } as never, 'x'))
    const w = await mountModels('admin', [model()])
    await open(w, 'approve')
    await confirm(w)
    expect(w.get('[data-test="action-error"]').text()).toContain('no tiene permiso')
    api.approve.mockRejectedValueOnce(new ApiError(409, { message: 'x', code: 'model_transition_not_allowed' } as never, 'x'))
    await confirm(w)
    expect(w.get('[data-test="action-error"]').text()).toContain('ya no está permitida')
    expect(api.models.mock.calls.length).toBeGreaterThanOrEqual(2)
  })

  it('422 de gate de datos o de motivo: se muestra el mensaje del servidor', async () => {
    api.promote.mockRejectedValue(new ApiError(422, { message: 'El modelo no declara un alcance válido.', errors: {} } as never, 'x'))
    const w = await mountModels('admin', [model({ status: 'approved', approved_by: 4 })])
    await open(w, 'promote')
    await confirm(w)
    expect(w.get('[data-test="action-error"]').text()).toContain('El modelo no declara un alcance válido')
  })

  it('M5: con la petición en vuelo el cuadro no se cierra (ni con Escape ni con la X) y el error del servidor no se pierde', async () => {
    let fail: (e: unknown) => void = () => undefined
    api.approve.mockImplementation(() => new Promise((_, reject) => { fail = reject }))
    const w = await mountModels('admin', [model()])
    await open(w, 'approve')
    await w.get('[data-test="confirm-model-action"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="confirm-model-action"]').attributes('disabled')).toBeDefined()
    expect(w.find('[aria-label="Cerrar"]').exists()).toBe(false)
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(w.find('[data-test="model-dialog"]').exists()).toBe(true)
    fail(new ApiError(403, { message: 'x', code: 'approval_segregation_of_duties', context: { roles: ['published_by'] } } as never, 'x'))
    await flushPromises()
    expect(w.get('[data-test="model-dialog"] [data-test="action-error"]').attributes('data-kind')).toBe('segregation')
    expect(w.find('[aria-label="Cerrar"]').exists()).toBe(true)
  })

  it('L1: un segundo evento de confirmación con el primero en vuelo no envía otra aprobación', async () => {
    api.approve.mockImplementation(() => new Promise(() => undefined))
    const w = await mountModels('admin', [model()])
    await open(w, 'approve')
    const dialog = w.findComponent(ModelActionDialog)
    dialog.vm.$emit('confirm', '')
    dialog.vm.$emit('confirm', '')
    await flushPromises()
    expect(api.approve).toHaveBeenCalledTimes(1)
  })

  it('L6: la columna de acciones tiene nombre accesible', async () => {
    const w = await mountModels('admin', [model()])
    expect(w.findAll('thead th').map((th) => th.text())).toContain('Acciones')
    expect(w.findAll('thead th').every((th) => th.text() !== '')).toBe(true)
  })

  it('el historial muestra los eventos de auditoría con persona y motivo', async () => {
    api.show.mockResolvedValue({ id: 1, version: 'v1', model_family: 'lightgbm_global', label: 'LightGBM global', status: 'rejected', core: 'demand', role: 'primary', published_by: 9, published_at: null, approved_by: null, approved_at: null, rejected_reason: 'x', published: true,
      promotion_decision: { id: 2, policy: 'demand-promotion-v1', scope_key: 's', decision: 'promote', is_effective: true, reason: null, approved_by: null, approved_at: null, decided_at: null },
      events: [{ id: 1, from_status: null, to_status: 'candidate', actor_user_id: null, actor_kind: 'system', reason: null, evidence: {}, created_at: '2026-10-31T09:00:00-05:00' }, { id: 2, from_status: 'candidate', to_status: 'rejected', actor_user_id: 7, actor_kind: 'user', reason: 'Calidad insuficiente', evidence: {}, created_at: '2026-11-01T09:00:00-05:00' }] })
    const w = await mountModels('viewer', [model()])
    await w.get('[data-test="model-history"]').trigger('click')
    await flushPromises()
    const detail = w.get('[data-test="model-detail"]')
    expect(detail.text()).toContain('Registrado como «Candidato»')
    expect(detail.text()).toContain('De «Candidato» a «Rechazado»')
    expect(detail.text()).toContain('Calidad insuficiente')
    expect(detail.text()).toContain('Usted')
    expect(w.get('[data-test="promotion-decision"]').text()).toContain('demand-promotion-v1')
  })

  it('el filtro por estado viaja al servidor', async () => {
    const w = await mountModels('admin', [model()])
    await w.get('#ma-status').setValue('validated')
    await flushPromises()
    expect(api.models.mock.calls.at(-1)![0]).toMatchObject({ status: 'validated', page: 1 })
  })
})

describe('Uso por empresa y costos', () => {
  const mountUsage = async () => {
    api.roi.mockResolvedValue(roiReport())
    api.usage.mockResolvedValue({ period: '2026-11', metrics: { import_rows: 10, forecast_runs: 3, active_products: 5, branches: 1, users: 2 }, limits: {} })
    const w = mount(UsageAndCosts, { global: { plugins: [tenantAs('admin')], stubs: { PlanUsage: { template: '<div data-test="plan-usage" />' } } } })
    await flushPromises()
    return w
  }

  it('muestra el consumo del plan y el uso de la plataforma como cantidades; sin precios, el costo no se estima', async () => {
    const w = await mountUsage()
    expect(w.find('[data-test="plan-usage"]').exists()).toBe(true)
    const usage = w.get('[data-test="platform-usage"]')
    expect(usage.text()).toContain('forecast_runs')
    expect(usage.text()).toContain('Costo de uso no estimado')
    expect(usage.text()).toContain('Sin precio')
    expect(usage.find('[data-test="figure"]').exists()).toBe(false)
    expect(w.get('[data-test="as-of"]').text()).toMatch(/Datos hasta/)
  })

  it('si el informe falla, lo dice sin ocultar el resto', async () => {
    api.roi.mockRejectedValue(new ApiError(500, null, 'x'))
    const w = mount(UsageAndCosts, { global: { plugins: [tenantAs('admin')], stubs: { PlanUsage: { template: '<div data-test="plan-usage" />' } } } })
    await flushPromises()
    expect(w.find('[data-test="plan-usage"]').exists()).toBe(true)
    expect(w.find('[role="alert"]').exists()).toBe(true)
  })
})
