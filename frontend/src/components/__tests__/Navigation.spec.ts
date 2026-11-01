import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { setupTenant } from '@/test/helpers'

const api = vi.hoisted(() => ({ status: vi.fn() }))
vi.mock('@/api', async (orig) => ({ ...(await orig<typeof import('@/api')>()), assistantApi: { status: api.status, send: vi.fn() } }))

import AppSidebar from '../layout/AppSidebar.vue'
import { routes } from '@/router'
import { resolveGuard } from '@/router/guards'
import { roleCan } from '@/utils/abilities'
import type { TenantRole } from '@/api/types'

enableAutoUnmount(afterEach)
beforeEach(() => { api.status.mockReset() })

const links = (w: ReturnType<typeof mount>) => w.findAll('a').map((a) => a.text())

describe('Navegación condicionada por rol y disponibilidad', () => {
  it('muestra Compradores y Plan de compras a quien puede ver pronósticos', async () => {
    api.status.mockResolvedValue({ enabled: false, reason: 'missing_api_key', tools: [], notice: '' })
    const w = mount(AppSidebar, { global: { plugins: [setupTenant('viewer')] } })
    await flushPromises()
    expect(links(w)).toEqual(expect.arrayContaining(['Compradores', 'Plan de compras', 'Fuentes de datos']))
  })

  it('el enlace del asistente solo aparece si el servidor lo indica activo', async () => {
    api.status.mockResolvedValue({ enabled: true, reason: null, tools: [], notice: '' })
    const on = mount(AppSidebar, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(links(on)).toContain('Asistente')

    api.status.mockResolvedValue({ enabled: false, reason: 'disabled_by_config', tools: [], notice: '' })
    const off = mount(AppSidebar, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(links(off)).not.toContain('Asistente')
  })

  it('si la consulta del asistente falla, el enlace queda oculto y el resto de la navegación funciona', async () => {
    api.status.mockImplementation(() => Promise.reject(new Error('red')))
    const w = mount(AppSidebar, { global: { plugins: [setupTenant('analyst')] } })
    await flushPromises()
    expect(links(w)).not.toContain('Asistente')
    expect(links(w)).toContain('Compradores')
  })
})

describe('Rutas nuevas y habilidades', () => {
  const app = routes.find((r) => r.path === '/app')!.children!
  const byName = (name: string) => app.find((r) => r.name === name)!

  it('cada vista nueva declara la habilidad que exige la API', () => {
    expect(byName('customers').meta).toMatchObject({ ability: 'forecast.view', requiresTenant: true })
    expect(byName('customer-detail').meta).toMatchObject({ ability: 'forecast.view' })
    expect(byName('supply').meta).toMatchObject({ ability: 'forecast.view' })
    expect(byName('sql-source').meta).toMatchObject({ ability: 'datasources.manage' })
    expect(byName('assistant').meta).toMatchObject({ ability: 'data.view' })
  })

  it.each([
    ['viewer', 'customers', true], ['viewer', 'supply', true], ['viewer', 'sql-source', false], ['analyst', 'sql-source', false], ['admin', 'sql-source', true],
  ] as [TenantRole, string, boolean][])('%s → %s: acceso %s', (role, name, allowed) => {
    const meta = byName(name).meta!
    const result = resolveGuard({ fullPath: `/app/${name}`, name, meta: meta as never }, { isAuthenticated: true, hasMemberships: true, hasTenant: true, isSuperadmin: false, can: (a) => roleCan(role, a) })
    expect(result === true).toBe(allowed)
  })

  it('los parámetros de pestaña son opcionales y la ficha del comprador exige un id numérico', () => {
    expect(byName('customers').path).toBe('compradores/:tab?')
    expect(byName('supply').path).toBe('plan-de-compras/:tab?')
    expect(byName('customer-detail').path).toContain('(\\d+)')
  })
})
