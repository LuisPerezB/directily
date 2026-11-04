import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, onMounted, ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { setupTenant } from '@/test/helpers'
import { useTenantStore } from '@/stores/tenant'
import type { Tenant } from '@/api/types'

const api = vi.hoisted(() => ({ status: vi.fn() }))
vi.mock('@/api', async (orig) => ({ ...(await orig<typeof import('@/api')>()), assistantApi: { status: api.status, send: vi.fn() } }))

import AppSidebar from '../layout/AppSidebar.vue'
import AppLayout from '@/layouts/AppLayout.vue'
import type { TenantRole } from '@/api/types'

enableAutoUnmount(afterEach)
beforeEach(() => { api.status.mockReset().mockResolvedValue({ enabled: false, reason: 'missing_api_key', tools: [], notice: '' }) })
const links = async (role: TenantRole) => {
  const w = mount(AppSidebar, { global: { plugins: [setupTenant(role)] } })
  await flushPromises()
  return { w, links: w.findAll('a').map((a) => a.text()) }
}

describe('Menú Enterprise por habilidad', () => {
  it('quien consulta ve el resumen, el Radar, el Centro de decisiones y ROI, pero no la Administración', async () => {
    const { w, links: l } = await links('viewer')
    expect(l).toEqual(expect.arrayContaining(['Resumen ejecutivo', 'Revenue Radar', 'Centro de decisiones', 'ROI y resultados']))
    expect(l).not.toContain('Administración')
    expect(w.text()).toContain('Enterprise')
  })

  it('un analista tampoco ve la Administración (aprobar modelos exige models.approve)', async () => {
    expect((await links('analyst')).links).not.toContain('Administración')
  })

  it.each(['admin', 'owner'] as const)('%s ve las cinco vistas Enterprise', async (role) => {
    expect((await links(role)).links).toEqual(expect.arrayContaining(['Resumen ejecutivo', 'Revenue Radar', 'Centro de decisiones', 'ROI y resultados', 'Administración']))
  })

  it('las vistas existentes siguen en el menú', async () => {
    expect((await links('viewer')).links).toEqual(expect.arrayContaining(['Dashboard', 'Pronósticos', 'Compradores', 'Plan de compras']))
  })
})

describe('V25-UIX-019 · al cambiar de empresa la vista se remonta con la empresa nueva ya cargada (M3)', () => {
  it('la página activa se desmonta mientras carga la empresa nueva y vuelve a montarse una sola vez, sin estado de la anterior', async () => {
    api.status.mockResolvedValue({ enabled: false, reason: null, tools: [], notice: '' })
    const mounts = vi.fn()
    const Page = defineComponent({ setup() { onMounted(mounts); return () => h('div', { 'data-test': 'page' }, 'página') } })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: Page }] })
    await router.push('/')
    await router.isReady()
    const pinia = setupTenant('analyst')
    const tenant = useTenantStore()
    const w = mount(AppLayout, { global: { plugins: [pinia, router], stubs: { AppSidebar: true, AppTopbar: true } } })
    await flushPromises()
    expect(mounts).toHaveBeenCalledTimes(1)
    // TenantSwitcher: select() vacía la empresa y load() la pide.
    const previous = tenant.tenant as Tenant
    tenant.select('tenant-b')
    tenant.loading = true
    await flushPromises()
    expect(w.find('[data-test="page"]').exists()).toBe(false) // nada de la A mientras llega la B (ni una vista montada sin plan ni moneda)
    expect(w.find('[role="status"]').exists()).toBe(true)
    tenant.tenant = { ...previous, uuid: 'tenant-b', name: 'Empresa B' }
    tenant.loading = false
    await flushPromises()
    expect(w.find('[data-test="page"]').exists()).toBe(true)
    expect(mounts).toHaveBeenCalledTimes(2)
  })

  it('aunque la empresa nueva ya venga cargada (sin fase de carga) la vista se remonta: el estado local de la anterior no sobrevive', async () => {
    api.status.mockResolvedValue({ enabled: false, reason: null, tools: [], notice: '' })
    const mounts = vi.fn()
    const Page = defineComponent({
      setup() {
        onMounted(mounts)
        const typed = ref('')
        return () => h('input', { 'data-test': 'filter', value: typed.value, onInput: (e: Event) => (typed.value = (e.target as HTMLInputElement).value) })
      },
    })
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: Page }] })
    await router.push('/')
    await router.isReady()
    const pinia = setupTenant('analyst')
    const tenant = useTenantStore()
    const w = mount(AppLayout, { global: { plugins: [pinia, router], stubs: { AppSidebar: true, AppTopbar: true } } })
    await flushPromises()
    await w.get('[data-test="filter"]').setValue('filtro de la empresa A')
    expect(mounts).toHaveBeenCalledTimes(1)
    const previous = tenant.tenant as Tenant
    tenant.currentUuid = 'tenant-b'
    tenant.tenant = { ...previous, uuid: 'tenant-b', name: 'Empresa B' }
    await flushPromises()
    expect(mounts).toHaveBeenCalledTimes(2)
    expect((w.get('[data-test="filter"]').element as HTMLInputElement).value).toBe('')
  })
})
