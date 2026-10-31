import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ApiError } from '@/api/errors'
import { setupTenant } from '@/test/helpers'
import type { AssistantReply, AssistantStatus, EffectiveConfig } from '@/api/types'

const api = vi.hoisted(() => ({ send: vi.fn(), status: vi.fn(), effective: vi.fn(), saveOverrides: vi.fn(), extensions: vi.fn() }))
vi.mock('@/api', async (orig) => ({
  ...(await orig<typeof import('@/api')>()),
  assistantApi: { send: api.send, status: api.status },
  configApi: { effective: api.effective, saveOverrides: api.saveOverrides, extensions: api.extensions },
}))

import AssistantPanel from '../assistant/AssistantPanel.vue'
import AssistantView from '@/views/AssistantView.vue'
import EffectiveConfigSettings from '../settings/EffectiveConfigSettings.vue'
import { useAssistantStore } from '@/stores/assistant'

const status: AssistantStatus = { enabled: true, reason: null, tools: [{ name: 'dashboard_summary', available_to_you: true }], limits: { max_message_chars: 1000, max_history_messages: 6, rate_per_minute: 10, daily_limit_per_tenant: null, max_tool_rounds: 3 }, notice: 'El texto solo explica resultados ya calculados.' }
const reply = (over: Partial<AssistantReply> = {}): AssistantReply => ({
  status: 'answered', answer: 'El pronóstico del panel indica un volumen estable.',
  figures: [{ tool: 'dashboard_summary', arguments: { horizon: 30 }, status: 'ok', data: { kpis: { forecast_units: 1234.5, stockout_risk_products: 3 } },
    envelope: { run_id: 2, core: 'demand', as_of: '2026-10-26', horizon_start: '2026-10-27', horizon_end: '2026-11-25', target_definition: null, entity_keys: null, unit: 'product_unit', currency: 'USD', result_type: 'forecast', model_version: null, data_snapshot_id: 1, config_version: 'c1', assumptions: [], limitations: ['Sin P50 publicada.'], quality_status: 'degraded', uncertainty_source: 'conformal' } }],
  grounding: { checked: true, ungrounded_numbers: [] }, provider: { name: 'ollama_cloud', model: 'm' }, usage: {}, notice: 'El asistente no calcula ni estima.', ...over,
})

beforeEach(() => { for (const f of Object.values(api)) f.mockReset() })

async function ask(w: ReturnType<typeof mount>, text: string) {
  await w.get('#as-message').setValue(text)
  await w.get('form').trigger('submit')
  await flushPromises()
}

describe('Asistente opcional', () => {
  it('avisa que no calcula ni decide y muestra las cifras desde figures con su procedencia', async () => {
    api.send.mockResolvedValue(reply())
    const w = mount(AssistantPanel, { props: { status }, global: { plugins: [setupTenant('analyst')] } })
    expect(w.get('[data-test="assistant-notice"]').text()).toContain('No calcula, no estima ni decide')
    await ask(w, '¿Cuánto venderé?')
    expect(api.send).toHaveBeenCalledWith('¿Cuánto venderé?', [])
    expect(w.get('[data-test="assistant-question"]').text()).toBe('¿Cuánto venderé?')
    expect(w.get('[data-test="assistant-explanation"]').text()).toContain('volumen estable')
    const figure = w.get('[data-test="assistant-figure"]')
    expect(figure.attributes('data-tool')).toBe('dashboard_summary')
    expect(figure.get('[data-test="figure-rows"]').text()).toMatch(/kpis\.forecast_units\s*1\.?234,5/)
    expect(figure.get('[data-test="quality-badge"]').text()).toBe('Con reservas')
    expect(figure.text()).toContain('Sin P50 publicada.')
  })

  it('una cifra inventada en el texto no se convierte en cifra: se descarta el texto y se listan los números rechazados', async () => {
    api.send.mockResolvedValue(reply({ status: 'ungrounded_rejected', answer: 'No puedo mostrar esa explicación porque citaba cifras que no proceden de los resultados.', grounding: { checked: true, ungrounded_numbers: ['99999'] } }))
    const w = mount(AssistantPanel, { props: { status }, global: { plugins: [setupTenant('analyst')] } })
    await ask(w, '¿Cuánto venderé?')
    expect(w.find('[data-test="assistant-explanation"]').exists()).toBe(false)
    expect(w.get('[data-test="assistant-discarded"]').text()).toContain('99999')
    expect(w.get('[data-test="assistant-fixed-message"]').text()).toContain('No puedo mostrar esa explicación')
    // Las únicas cifras visibles son las de la herramienta.
    expect(w.get('[data-test="figure-rows"]').text()).not.toContain('99999')
  })

  it('sin herramienta aplicable se muestra el mensaje fijo y ninguna cifra', async () => {
    api.send.mockResolvedValue(reply({ status: 'no_applicable_tool', answer: 'No puedo responder esa pregunta con los resultados ya calculados.', figures: [] }))
    const w = mount(AssistantPanel, { props: { status }, global: { plugins: [setupTenant('analyst')] } })
    await ask(w, 'Dame el clima')
    expect(w.get('[data-test="assistant-fixed-message"]').text()).toContain('No puedo responder esa pregunta')
    expect(w.find('[data-test="assistant-figure"]').exists()).toBe(false)
  })

  it('reenvía el historial de la conversación (solo texto) y recorta a 6 mensajes', async () => {
    api.send.mockResolvedValue(reply())
    const w = mount(AssistantPanel, { props: { status }, global: { plugins: [setupTenant('analyst')] } })
    for (let i = 1; i <= 5; i++) await ask(w, `pregunta ${i}`)
    const history = api.send.mock.calls[4]![1]
    expect(history).toHaveLength(6)
    expect(history.every((h: { role: string; content: string }) => ['user', 'assistant'].includes(h.role))).toBe(true)
    expect(history.at(-2)).toEqual({ role: 'user', content: 'pregunta 4' })
  })

  it('errores del asistente: tasa, cuota y desactivación se explican', async () => {
    api.send.mockRejectedValueOnce(new ApiError(429, { message: 'x', code: 'assistant_rate_limited' }, 'x'))
    const w = mount(AssistantPanel, { props: { status }, global: { plugins: [setupTenant('analyst')] } })
    await ask(w, 'hola')
    expect(w.get('[data-test="assistant-error"]').text()).toContain('Demasiadas preguntas seguidas')
    api.send.mockRejectedValueOnce(new ApiError(502, { message: 'x', code: 'assistant_provider_error' }, 'x'))
    await ask(w, 'hola')
    expect(w.get('[data-test="assistant-error"]').text()).toContain('el resto de la aplicación no se ve afectado')
  })

  it('desactivado: el panel no se ofrece y se explica el motivo', async () => {
    api.status.mockResolvedValue({ enabled: false, reason: 'missing_api_key', tools: [], notice: 'No disponible.' })
    const pinia = setupTenant('analyst')
    const w = mount(AssistantView, { global: { plugins: [pinia] } })
    await flushPromises()
    expect(w.find('[data-test="assistant-panel"]').exists()).toBe(false)
    expect(w.get('[data-test="assistant-disabled"]').text()).toContain('Falta configurar la clave del proveedor')
  })

  it('el estado se consulta una vez por empresa; si la consulta falla el asistente queda oculto', async () => {
    const pinia = setupTenant('analyst')
    const store = useAssistantStore(pinia)
    api.status.mockResolvedValueOnce(status)
    await store.ensureLoaded('tenant-test')
    await store.ensureLoaded('tenant-test')
    expect(api.status).toHaveBeenCalledTimes(1)
    expect(store.enabled).toBe(true)
    store.reset()
    api.status.mockRejectedValueOnce(new ApiError(500, { message: 'x' }, 'x'))
    await store.ensureLoaded('tenant-test')
    expect(store.enabled).toBe(false)
  })
})

describe('Configuración efectiva por módulo', () => {
  const config = (over: Partial<EffectiveConfig> = {}): EffectiveConfig => ({
    core: 'demand', config_version: 'abcdef0123456789', profile: { code: 'synthetic_retail_a', version: '1.0', synthetic: true }, precedence: ['core', 'profile', 'tenant'],
    keys: [
      { key: 'service_level', value: 0.95, source: 'core' }, { key: 'min_improvement', value: 0.05, source: 'profile:synthetic_retail_a@1.0' },
      { key: 'review_period_days', value: 14, source: 'tenant' }, { key: 'exceptional_events_flag', value: true, source: 'core' },
    ],
    tenant_overrides: { review_period_days: 14 }, note: 'Los cambios no modifican ejecuciones pasadas.', ...over,
  })
  const mountConfig = (role: 'owner' | 'viewer' | 'admin' = 'admin') => mount(EffectiveConfigSettings, { global: { plugins: [setupTenant(role)] } })

  beforeEach(() => { api.extensions.mockResolvedValue({ sector: null, profile: null, providers: [], note: null }) })

  it('muestra el valor de cada clave con su procedencia: core, perfil sectorial o empresa', async () => {
    api.effective.mockResolvedValue(config())
    const w = mountConfig()
    await flushPromises()
    const source = (key: string) => w.get(`[data-key="${key}"] [data-source]`).attributes('data-source')
    expect(source('service_level')).toBe('core')
    expect(source('min_improvement')).toBe('profile')
    expect(source('review_period_days')).toBe('tenant')
    expect(w.get('[data-test="profile-applied"]').text()).toContain('synthetic_retail_a')
    expect(w.get('[data-test="profile-applied"]').text()).toContain('perfil de prueba')
  })

  it('sin perfil sectorial lo dice: solo core y empresa', async () => {
    api.effective.mockResolvedValue(config({ profile: null }))
    const w = mountConfig()
    await flushPromises()
    expect(w.get('[data-test="no-profile"]').text()).toContain('Sin perfil sectorial')
  })

  it('editar un valor lo marca como de la empresa y envía solo las sobrescrituras', async () => {
    api.effective.mockResolvedValue(config())
    api.saveOverrides.mockImplementation(async (_core, overrides) => config({ tenant_overrides: overrides }))
    const w = mountConfig()
    await flushPromises()
    expect(w.get('[data-test="save-config"]').attributes('disabled')).toBeDefined()
    await w.get('#ec-service_level').setValue('0,9')
    expect(w.get('[data-key="service_level"] [data-source]').attributes('data-source')).toBe('tenant')
    await w.get('[data-test="save-config"]').trigger('click')
    await flushPromises()
    expect(api.saveOverrides).toHaveBeenCalledWith('demand', { review_period_days: 14, service_level: 0.9 })
  })

  it('un texto que no es del tipo del valor se rechaza en el campo y no permite guardar', async () => {
    api.effective.mockResolvedValue(config())
    const w = mountConfig()
    await flushPromises()
    await w.get('#ec-service_level').setValue('mucho')
    expect(w.get('[data-key="service_level"]').text()).toContain('Escriba un número')
    expect(w.get('[data-test="save-config"]').attributes('disabled')).toBeDefined()
  })

  it('los errores de validación del motor aparecen junto a su clave', async () => {
    api.effective.mockResolvedValue(config())
    api.saveOverrides.mockRejectedValue(new ApiError(422, { message: 'x', code: 'invalid_config', errors: [{ key: 'service_level', code: 'out_of_range', message: 'debe estar entre 0,5 y 0,999' }] } as never, 'x'))
    const w = mountConfig()
    await flushPromises()
    await w.get('#ec-service_level').setValue('2')
    await w.get('[data-test="save-config"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-key="service_level"] [data-test="config-issue"]').text()).toBe('debe estar entre 0,5 y 0,999')
    expect(w.get('[data-test="config-error"]').text()).toContain('El motor rechazó la configuración')
  })

  it('un usuario de consulta solo ve los valores, sin editar', async () => {
    api.effective.mockResolvedValue(config())
    const w = mountConfig('viewer')
    await flushPromises()
    expect(w.find('#ec-service_level').exists()).toBe(false)
    expect(w.find('[data-test="save-config"]').exists()).toBe(false)
    expect(w.text()).toContain('requiere permiso de administración')
  })

  it('volver al valor heredado quita la sobrescritura de empresa', async () => {
    api.effective.mockResolvedValue(config())
    api.saveOverrides.mockImplementation(async (_core, overrides) => config({ tenant_overrides: overrides }))
    const w = mountConfig()
    await flushPromises()
    await w.get('[data-key="review_period_days"] button').trigger('click')
    expect(w.get('[data-key="review_period_days"] [data-test="will-restore"]').exists()).toBe(true)
    await w.get('[data-test="save-config"]').trigger('click')
    await flushPromises()
    expect(api.saveOverrides).toHaveBeenCalledWith('demand', {})
  })
})
