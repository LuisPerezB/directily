import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { setupTenant } from '@/test/helpers'
import type { TenantUsage } from '@/api/types'

const api = vi.hoisted(() => ({ usage: vi.fn() }))
vi.mock('@/api', async (orig) => ({ ...(await orig<typeof import('@/api')>()), tenantApi: { usage: api.usage } }))

import PlanUsage from '../settings/PlanUsage.vue'

const usage = (over: Partial<TenantUsage> = {}): TenantUsage => ({
  period: '2026-11',
  metrics: { import_rows: 1200, forecast_runs: 3, active_products: 410, branches: 2, users: 3, series: 820 },
  limits: { max_branches: 5, max_series: 5000, max_active_products: null, max_users: 10, horizons: [7, 15, 30], features: { api_access: true, customer: true, roles: true } },
  ...over,
})

beforeEach(() => api.usage.mockReset())
const mountUsage = () => mount(PlanUsage, { global: { plugins: [setupTenant('owner', { horizons: [7, 15, 30] })] } })

describe('Plan y consumo', () => {
  it('muestra el catálogo del plan (incluido 15), el tope de series y las capacidades; los precios no son un límite', async () => {
    api.usage.mockResolvedValue(usage())
    const w = mountUsage()
    await flushPromises()
    expect(w.text()).toContain('7 días, 15 días, 30 días')
    expect(w.text()).toContain('Series activas')
    expect(w.text()).toMatch(/820\s*\/\s*5\.?000/)
    expect(w.text()).toContain('Comportamiento de compra: Sí')
    expect(w.text()).toContain('Roles de usuario: Sí')
    expect(w.text()).not.toMatch(/USD|precio/i)
  })

  it('sin tope de series en el plan no aparece la fila (planes heredados)', async () => {
    api.usage.mockResolvedValue(usage({ metrics: { import_rows: 1, forecast_runs: 0, active_products: 1, branches: 1, users: 1, series: null }, limits: { max_branches: 2, horizons: [7, 14, 30], features: { scenarios: false } } }))
    const w = mountUsage()
    await flushPromises()
    expect(w.text()).not.toContain('Series activas')
    expect(w.text()).toContain('7 días, 14 días, 30 días')
  })

  it('una capacidad sin texto propio se muestra con su nombre en vez de una clave rota', async () => {
    api.usage.mockResolvedValue(usage({ limits: { horizons: [7], features: { private_processing: true } } }))
    const w = mountUsage()
    await flushPromises()
    expect(w.text()).toContain('private_processing: Sí')
    expect(w.text()).not.toContain('plan.features.')
  })
})
