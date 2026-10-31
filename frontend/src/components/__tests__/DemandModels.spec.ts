import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import { setupTenant } from '@/test/helpers'
import type { ForecastRun, ModelRegistryEntry } from '@/api/types'

const api = vi.hoisted(() => ({ models: vi.fn(), rollbackDemand: vi.fn() }))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  accuracyApi: { models: api.models },
  modelsApi: { rollbackDemand: api.rollbackDemand, rollbackCustomer: vi.fn() },
}))

import DemandModels from '../accuracy/DemandModels.vue'

const run = (id: number, over: Partial<ForecastRun> = {}): ForecastRun => ({
  id, version: id, run_type: 'production', status: 'completed', triggered_by: 'user', horizon_days: 90, cutoff_date: `2026-10-${10 + id}`, data_from: null, data_to: null,
  feature_set_version: null, is_current: false, selection: null, summary: null, error_message: null, started_at: null, completed_at: null, created_at: '2026-10-20T00:00:00Z', ...over,
})
const model = (id: number, status: ModelRegistryEntry['status'], role: ModelRegistryEntry['role']): ModelRegistryEntry => ({
  id, version: `v${id}`, model_family: 'lightgbm_global', strategy_level: 'global', status, role, core: 'demand', demand_class: null, trained_at: '2026-10-20T00:00:00Z',
  training_data_from: null, training_data_to: null, series_count: 10, legacy_status: status === 'production' && role === 'fallback' ? 'fallback' : status,
})
const runs = [run(10, { is_current: true }), run(9, { run_type: 'historical' }), run(8), run(7, { status: 'failed' })]

beforeEach(() => {
  api.models.mockReset().mockResolvedValue({ data: [model(1, 'production', 'primary'), model(2, 'production', 'fallback'), model(3, 'retired', 'primary'), model(4, 'failed', 'primary'), model(5, 'validated', null)], meta: {} })
  api.rollbackDemand.mockReset()
})

describe('DemandModels (estados del registro y reversión)', () => {
  it('muestra los estados nuevos con su papel y el estado legado cuando difiere', async () => {
    const w = mount(DemandModels, { props: { runs }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    const text = w.text()
    for (const label of ['En producción', 'Retirado', 'Fallido', 'Validado']) expect(text).toContain(label)
    expect(text).toContain('Principal')
    expect(text).toContain('Respaldo')
    expect(text).toContain('Estado anterior: fallback')
  })

  it('solo ofrece volver a ejecuciones de producción completadas y anteriores a la vigente', async () => {
    const w = mount(DemandModels, { props: { runs }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    const buttons = w.findAll('[data-test="rollback-open"]')
    expect(buttons).toHaveLength(1)
    expect(w.get('[data-test="rollback-section"]').text()).toContain('v8')
    expect(w.get('[data-test="rollback-section"]').text()).not.toContain('v9')
  })

  it('exige confirmación: abrir no revierte; confirmar llama a la API con la ejecución elegida y muestra la nota', async () => {
    api.rollbackDemand.mockResolvedValue({ from_run_id: 10, from_version: 10, to_run_id: 8, to_version: 8, to_cutoff_date: '2026-10-18', to_data_snapshot_id: 3, to_config_version: 'abc', restored_model_ids: [3], retired_model_ids: [1], artifacts_verified: true, restored_models: [], note: 'La ejecución elegida conserva los pronósticos de su corte 2026-10-18.' })
    const w = mount(DemandModels, { props: { runs }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    await w.get('[data-test="rollback-open"]').trigger('click')
    expect(api.rollbackDemand).not.toHaveBeenCalled()
    expect(w.text()).toContain('¿Volver a la versión anterior?')
    await w.findAll('button').find((b) => b.text() === 'Sí, volver a esta versión')!.trigger('click')
    await flushPromises()
    expect(api.rollbackDemand).toHaveBeenCalledWith(8)
    const result = w.get('[data-test="rollback-result"]')
    expect(result.text()).toContain('conserva los pronósticos de su corte')
    expect(result.text()).toContain('Integridad de los archivos del modelo verificada')
    expect(w.emitted('rolledBack')).toHaveLength(1)
  })

  it('si la integridad no se pudo verificar desde el servidor, lo dice', async () => {
    api.rollbackDemand.mockResolvedValue({ note: 'ok', artifacts_verified: null })
    const w = mount(DemandModels, { props: { runs }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    await w.get('[data-test="rollback-open"]').trigger('click')
    await w.findAll('button').find((b) => b.text() === 'Sí, volver a esta versión')!.trigger('click')
    await flushPromises()
    expect(w.get('[data-test="rollback-result"]').text()).toContain('no se pudo verificar desde este servidor')
  })

  it('un 409 se explica por su código y no deja de mostrar el diálogo', async () => {
    api.rollbackDemand.mockRejectedValue(new ApiError(409, { message: 'x', code: 'rollback_artifact_mismatch' }, 'x'))
    const w = mount(DemandModels, { props: { runs }, global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    await w.get('[data-test="rollback-open"]').trigger('click')
    await w.findAll('button').find((b) => b.text() === 'Sí, volver a esta versión')!.trigger('click')
    await flushPromises()
    expect(w.get('[data-test="rollback-error"]').text()).toContain('no coincide con su huella de integridad')
    expect(w.emitted('rolledBack')).toBeUndefined()
  })

  it('sin la habilidad forecast.run (usuario de consulta) no hay botón de reversión', async () => {
    const w = mount(DemandModels, { props: { runs }, global: { plugins: [setupTenant('viewer')] } })
    await flushPromises()
    expect(w.find('[data-test="rollback-open"]').exists()).toBe(false)
    expect(w.text()).toContain('requiere permiso para generar pronósticos')
  })

  it('sin versiones anteriores lo explica', async () => {
    const w = mount(DemandModels, { props: { runs: [run(1, { is_current: true })] }, global: { plugins: [setupTenant('owner')] } })
    await flushPromises()
    expect(w.find('[data-test="rollback-none"]').exists()).toBe(true)
  })
})
